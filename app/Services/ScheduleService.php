<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClassSession;
use App\Models\Client;
use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A doctor has ONE calendar: treatments, online sessions and academy classes must not overlap.
 */
class ScheduleService
{
    /** Builds the start time from a date ("2026-11-06") and a slot ("10:30") in the app timezone. */
    public static function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", config('app.timezone'))->startOfMinute();
    }

    /**
     * What the doctor is already committed to during [start, end).
     *
     * @param  bool  $includePending  also count unapproved requests (used when a client picks a slot)
     * @return list<array{kind: string, text: string, id: int, starts_at: string, ends_at: string}>
     */
    public function clashes(Doctor $doctor, CarbonInterface $start, CarbonInterface $end, ?int $ignoreAppointmentId = null, bool $includePending = false): array
    {
        $classes = ClassSession::query()->where('teacher_id', $doctor->id)->overlapping($start, $end)->get()
            ->map(fn (ClassSession $s) => [
                'kind' => 'class',
                'id' => $s->id,
                'text' => "Teaching “{$s->title}” {$s->starts_at->format('H:i')}–{$s->ends_at->format('H:i')}".($s->room ? " · {$s->room}" : ''),
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
            ]);

        $statuses = $includePending ? AppointmentStatus::holding() : [AppointmentStatus::Approved->value];
        $treatments = Appointment::query()->with('client:id,name', 'service:id,name')
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', $statuses)
            ->when($ignoreAppointmentId, fn ($q) => $q->whereKeyNot($ignoreAppointmentId))
            ->overlapping($start, $end)->get()
            ->map(fn (Appointment $a) => [
                'kind' => 'treatment',
                'id' => $a->id,
                'text' => $a->is_online
                    ? "Online session with {$a->client->name} at {$a->starts_at->format('H:i')} ({$a->duration_minutes} min)"
                    : "Treating {$a->client->name} — {$a->service->name} at {$a->starts_at->format('H:i')}",
                'starts_at' => $a->starts_at->toIso8601String(),
                'ends_at' => $a->ends_at->toIso8601String(),
            ]);

        return $classes->concat($treatments)->values()->all();
    }

    /** The doctor's approved treatments and classes on a day, in time order (shown before approving). */
    public function dayPlan(Doctor $doctor, CarbonInterface $day): array
    {
        $start = CarbonImmutable::parse($day)->startOfDay();

        return $this->clashes($doctor, $start, $start->endOfDay());
    }

    /**
     * Start times for a service on a date, each flagged available or not.
     * - With a doctor: the doctor must be free (pending requests count, so two clients can't ask for the same slot).
     * - Without a doctor ("let reception choose"): at least one bookable doctor who offers the service
     *   (at that branch, for clinic visits) must be free.
     *
     * @return list<array{time: string, starts_at: string, available: bool}>
     */
    public function slots(Service $service, string $date, ?Doctor $doctor = null, ?Branch $branch = null): array
    {
        $candidates = $doctor ? collect([$doctor]) : $this->candidates($service, $branch);
        $dayStart = self::at($date, '00:00');
        $busy = $this->busyIntervals($candidates->pluck('id'), $dayStart, $dayStart->endOfDay());
        $earliest = now()->addMinutes(config('lavish.booking.min_lead_minutes'));

        return collect(config('lavish.booking.slots'))->map(function (string $time) use ($date, $service, $candidates, $busy, $earliest) {
            $start = self::at($date, $time);
            $end = $start->addMinutes($service->duration_minutes);
            $free = $start->greaterThanOrEqualTo($earliest) && $candidates->contains(
                fn (Doctor $d) => ! collect($busy[$d->id] ?? [])->contains(fn ($b) => $b[0] < $end && $b[1] > $start)
            );

            return ['time' => $time, 'starts_at' => $start->toIso8601String(), 'available' => $free];
        })->all();
    }

    /** Bookable doctors who give the service (every doctor gives online consultations). */
    public function candidates(Service $service, ?Branch $branch = null): Collection
    {
        return Doctor::query()->bookable()->with('user.staffProfile', 'services:id')
            ->when(! $service->is_online, fn ($q) => $q->whereHas('services', fn ($s) => $s->whereKey($service->id)))
            ->when(! $service->is_online && $branch, fn ($q) => $q->whereHas('user.staffProfile', fn ($p) => $p->where('branch_id', $branch->id)))
            ->get();
    }

    /** @return array<int, list<array{0: CarbonInterface, 1: CarbonInterface}>> busy intervals per doctor id */
    private function busyIntervals(Collection $doctorIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $busy = [];
        Appointment::query()->whereIn('doctor_id', $doctorIds)->holding()->overlapping($from, $to)->get(['doctor_id', 'starts_at', 'ends_at'])
            ->each(function ($a) use (&$busy) {
                $busy[$a->doctor_id][] = [$a->starts_at, $a->ends_at];
            });
        ClassSession::query()->whereIn('teacher_id', $doctorIds)->overlapping($from, $to)->get(['teacher_id', 'starts_at', 'ends_at'])
            ->each(function ($s) use (&$busy) {
                $busy[$s->teacher_id][] = [$s->starts_at, $s->ends_at];
            });

        return $busy;
    }

    /**
     * Ranks doctors for an appointment (port of suggestDoctors() in the web prototype).
     * Score: skill match 50 (related specialty 30) + free at that time 30 + light day up to 20 + has treated the client 10.
     * Unavailable doctors are capped at 15 and listed last.
     *
     * @return list<array{doctor: Doctor, score: int, available: bool, reasons: list<array{ok: bool, text: string}>}>
     */
    public function suggest(Appointment $appt): array
    {
        $service = $appt->service;
        $client = $appt->client;
        $words = fn (string $t) => array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($t)), fn ($w) => strlen($w) > 3));
        $time = $appt->starts_at->format('H:i');

        $ranked = Doctor::query()->bookable()->with('user.staffProfile.branch', 'services:id,name')->get()->map(function (Doctor $d) use ($appt, $service, $client, $words, $time) {
            $reasons = [];
            $score = 0;
            $available = true;

            $exact = $d->services->contains('id', $service->id);
            $related = (bool) array_intersect($words($service->name), $words($d->specialty));
            if ($service->is_online) {
                $score += 50;
                $reasons[] = ['ok' => true, 'text' => "Gives online consultations ({$d->specialty})"];
            } elseif ($exact) {
                $score += 50;
                $reasons[] = ['ok' => true, 'text' => "Specialist in {$service->name}"];
            } elseif ($related) {
                $score += 30;
                $reasons[] = ['ok' => true, 'text' => "Related specialty: {$d->specialty}"];
            } else {
                $reasons[] = ['ok' => false, 'text' => "Not a {$service->name} specialist ({$d->specialty})"];
            }

            if (! $service->is_online && $appt->branch_id && $d->branch()?->id !== $appt->branch_id) {
                $score -= 20;
                $reasons[] = ['ok' => false, 'text' => 'Works at '.($d->branch()?->name ?? 'another branch')];
            }

            $clashes = $this->clashes($d, $appt->starts_at, $appt->ends_at, $appt->id, includePending: true);
            $class = collect($clashes)->firstWhere('kind', 'class');
            if ($class) {
                $available = false;
                $reasons[] = ['ok' => false, 'text' => $class['text']];
            } elseif ($clashes) {
                $available = false;
                $reasons[] = ['ok' => false, 'text' => "Already booked at {$time}"];
            } elseif ($appt->starts_at->isToday() && $d->availability->value !== 'available') {
                $available = false;
                $reasons[] = ['ok' => false, 'text' => $d->availability->label().' today'];
            } else {
                $score += 30;
                $reasons[] = ['ok' => true, 'text' => "Free at {$time}"];
            }

            $load = Appointment::query()->where('doctor_id', $d->id)->holding()
                ->whereDate('starts_at', $appt->starts_at->toDateString())->whereKeyNot($appt->id)->count();
            $score += max(0, 20 - $load * 6);
            $reasons[] = ['ok' => $load < 3, 'text' => $load ? "{$load} other session(s) that day" : 'No other sessions that day — their turn'];

            $seen = $client->primary_doctor_id === $d->id || $client->treatedBy($d->id);
            if ($seen) {
                $score += 10;
                $reasons[] = ['ok' => true, 'text' => 'Has treated this client before'];
            }

            return ['doctor' => $d, 'score' => $available ? $score : min($score, 15), 'available' => $available, 'reasons' => $reasons];
        });

        return $ranked->sort(fn ($a, $b) => [$b['available'], $b['score']] <=> [$a['available'], $a['score']])->values()->all();
    }

    /** Is the client already booked at that time (any doctor)? */
    /**
     * The doctor's booking slots on the appointment's day, each with what is there — shown to reception before
     * approving or assigning. state: free | this (this booking) | booked (approved treatment) | requested (another
     * client's pending request) | class (teaching) | past. `who` says who / what holds it.
     */
    public function doctorDay(Doctor $doctor, Appointment $appt): array
    {
        $date = $appt->starts_at->toDateString();
        $dayStart = self::at($date, '00:00');
        $appts = Appointment::query()->with('client:id,name', 'service:id,name')->where('doctor_id', $doctor->id)->holding()
            ->overlapping($dayStart, $dayStart->endOfDay())->get();
        $classes = ClassSession::query()->where('teacher_id', $doctor->id)->overlapping($dayStart, $dayStart->endOfDay())->get();
        $minutes = $appt->duration_minutes ?: 60;

        return collect(config('lavish.booking.slots'))->map(function (string $time) use ($date, $appt, $appts, $classes, $minutes) {
            $start = self::at($date, $time);
            $end = $start->addMinutes($minutes);
            $hit = fn ($x) => $x->starts_at < $end && $x->ends_at > $start;
            $class = $classes->first($hit);
            $booked = $appts->first(fn ($a) => $a->id !== $appt->id && $a->status === AppointmentStatus::Approved && $hit($a));
            $requested = $appts->first(fn ($a) => $a->id !== $appt->id && $a->status === AppointmentStatus::Pending && $hit($a));
            [$state, $who] = match (true) {
                $start->format('H:i') === $appt->starts_at->format('H:i') && ! $class && ! $booked => ['this', $appt->client?->name],
                (bool) $class => ['class', "Teaching “{$class->title}”"],
                (bool) $booked => ['booked', "{$booked->client?->name} — {$booked->service?->name}"],
                (bool) $requested => ['requested', "{$requested->client?->name} asked for {$requested->service?->name}"],
                $start->lt(now()) => ['past', null],
                default => ['free', null],
            };

            return ['time' => $time, 'state' => $state, 'who' => $who, 'this_time' => $start->format('H:i') === $appt->starts_at->format('H:i')];
        })->all();
    }

    /** Free start times for a service with this doctor on the next few days (for "ask the client to change"). */
    public function freeTimes(Service $service, Doctor $doctor, CarbonInterface $from, int $days = 4, int $limit = 8): array
    {
        $out = [];
        for ($i = 0; $i < $days && count($out) < $limit; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            foreach ($this->slots($service, $date, $doctor) as $slot) {
                if ($slot['available']) {
                    $out[] = ['date' => $date, 'time' => $slot['time']];
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $out;
    }

    public function clientBusy(Client $client, CarbonInterface $start, CarbonInterface $end, ?int $ignoreId = null): bool
    {
        return $client->appointments()->holding()->overlapping($start, $end)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
    }
}
