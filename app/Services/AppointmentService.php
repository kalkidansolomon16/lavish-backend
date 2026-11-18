<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\BookingSource;
use App\Enums\DoctorAccess;
use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientHistory;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Services\Payments\PaymentService;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appointment lifecycle:
 *   app booking ─► pending ─(reception approves / assigns a doctor)─► approved ─(doctor)─► completed
 *                     │                                                 │
 *                     ├─► declined (reception)                          └─► cancelled (client)
 *                     └─► cancelled (client)
 *   walk-in / phone booking at reception ─► approved straight away
 *
 * Every appointment gets a payment row (unpaid) when it's created.
 * Online sessions get a video link on approval; the client sees it once paid.
 */
class AppointmentService
{
    public function __construct(
        private ScheduleService $schedule,
        private PaymentService $payments,
        private ChatService $chat,
        private Notifier $notifier,
        private Messenger $messenger,
        private LoyaltyService $loyalty,
    ) {}

    /**
     * A client books in the app.
     *
     * @param  array{service_id: int, doctor_id?: ?int, branch_id?: ?int, date: string, time: string, note?: ?string}  $data
     */
    public function book(Client $client, array $data): Appointment
    {
        $service = Service::query()->active()->findOrFail($data['service_id']);
        $doctor = isset($data['doctor_id']) ? Doctor::query()->bookable()->with('services:id', 'user.staffProfile')->find($data['doctor_id']) : null;
        if (isset($data['doctor_id']) && ! $doctor) {
            throw BusinessRuleException::make('doctor_unavailable', 'This doctor is not taking bookings.');
        }
        if ($doctor && ! $doctor->offers($service)) {
            throw BusinessRuleException::make('doctor_not_offering', "{$doctor->name} doesn’t offer {$service->name}.");
        }

        $branchId = $service->is_online ? null : ($doctor?->branch()?->id ?? ($data['branch_id'] ?? null));
        if (! $service->is_online && ! $branchId) {
            throw BusinessRuleException::make('branch_required', 'Choose the branch you want to visit.');
        }

        [$start, $end] = $this->window($data['date'], $data['time'], $service, forClient: true);

        if ($doctor && $this->schedule->clashes($doctor, $start, $end, includePending: true)) {
            throw BusinessRuleException::make('slot_taken', 'That time was just taken. Please pick another slot.');
        }
        if (! $doctor && ! collect($this->schedule->slots($service, $data['date'], null, Branch::find($branchId)))->firstWhere('time', $data['time'])['available']) {
            throw BusinessRuleException::make('slot_taken', 'No doctor is free at that time. Please pick another slot.');
        }
        if ($this->schedule->clientBusy($client, $start, $end)) {
            throw BusinessRuleException::make('client_double_booked', 'You already have an appointment at that time.');
        }

        $appt = DB::transaction(function () use ($client, $service, $doctor, $branchId, $start, $end, $data) {
            $a = Appointment::create([
                'client_id' => $client->id,
                'doctor_id' => $doctor?->id,
                'service_id' => $service->id,
                'branch_id' => $branchId,
                'is_online' => $service->is_online,
                'starts_at' => $start,
                'ends_at' => $end,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price,
                'status' => AppointmentStatus::Pending,
                'source' => BookingSource::App,
                'client_note' => $data['note'] ?? null,
                'created_by' => $client->user_id,
            ]);
            $this->payments->createFor($a, $client, $service->price, $service->is_online ? "Online Consultation ({$service->duration_minutes} min)" : $service->name);

            return $a;
        });

        $when = $start->format('D j M, H:i');
        $this->notifier->staff('appointments.approve', new LavishNotification(
            $service->is_online ? 'appointment.online_requested' : 'appointment.requested',
            $service->is_online ? 'Online session to confirm' : 'New booking request',
            $service->is_online
                ? "{$client->name} requested a {$service->duration_minutes}-min online session".($doctor ? " with {$doctor->name}" : '')." on {$when} — confirm it in Appointments."
                : "{$client->name} requested {$service->name}".($doctor ? " with {$doctor->name}" : ' (no doctor chosen)')." on {$when}.",
            $service->is_online ? 'video_call' : 'event', null, '/appointments?focus='.$appt->id,
        ));

        return $appt;
    }

    /**
     * Reception approves a pending appointment, optionally assigning (or changing) the doctor.
     * Refuses when the doctor is busy (treatment or academy class) — reception picks another doctor or declines.
     */
    public function approve(Appointment $appt, User $by, ?Doctor $assign = null): Appointment
    {
        if ($appt->status !== AppointmentStatus::Pending) {
            throw BusinessRuleException::make('not_pending', 'Only pending appointments can be approved.');
        }
        $doctor = $assign ?? $appt->doctor;
        if (! $doctor) {
            throw BusinessRuleException::make('doctor_required', 'Assign a doctor before approving.');
        }
        if ($doctor->access !== DoctorAccess::Active) {
            throw BusinessRuleException::make('doctor_unavailable', "{$doctor->name} doesn’t have portal access.");
        }
        $clashes = $this->schedule->clashes($doctor, $appt->starts_at, $appt->ends_at, $appt->id);
        if ($clashes) {
            throw BusinessRuleException::make('doctor_busy', "{$doctor->name} is busy at that time.", ['clashes' => $clashes]);
        }

        $appt->fill([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::Approved,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);
        if ($appt->is_online && ! $appt->video_link) {
            $appt->video_link = $this->videoLink($appt);
        }
        $appt->save();

        $client = $appt->client;
        if (! $client->primary_doctor_id) {
            $client->update(['primary_doctor_id' => $doctor->id]);
        }

        $when = $appt->starts_at->format('D j M, H:i');
        $this->notifier->client($client, $appt->is_online
            ? new LavishNotification('appointment.online_confirmed', 'Online session confirmed',
                "Your {$appt->duration_minutes}-min video consultation with {$doctor->name} on {$when} is confirmed.".($appt->isPaid() ? ' Join from the app.' : ' Pay in the app to get your video link.'),
                'video_call', ['screen' => 'appointment', 'id' => $appt->id])
            : new LavishNotification('appointment.approved', 'Appointment approved',
                "{$appt->service->name} on {$when} is confirmed. {$doctor->name} has been notified.",
                'event_available', ['screen' => 'appointment', 'id' => $appt->id]));

        $this->notifier->doctor($doctor, new LavishNotification(
            'appointment.assigned', $appt->is_online ? 'Online session confirmed' : 'New appointment',
            "{$client->name} — ".($appt->is_online ? "{$appt->duration_minutes}-min online session" : $appt->service->name)." on {$when}.",
            'event', null, '/appointments?focus='.$appt->id,
        ));

        return $appt->refresh();
    }

    public function decline(Appointment $appt, User $by, ?string $reason = null): Appointment
    {
        if (! in_array($appt->status, [AppointmentStatus::Pending, AppointmentStatus::Approved], true)) {
            throw BusinessRuleException::make('not_open', 'This appointment can no longer be declined.');
        }
        $wasApproved = $appt->status === AppointmentStatus::Approved;
        $appt->update(['status' => AppointmentStatus::Declined, 'decline_reason' => $reason]);
        $this->payments->cancelFor($appt->payment, 'appointment declined');

        $this->notifier->client($appt->client, new LavishNotification(
            'appointment.declined', 'Appointment not approved',
            "We couldn’t confirm {$appt->service->name} on {$appt->starts_at->format('D j M, H:i')}".($reason ? ": {$reason}" : '.').' Please pick another time.',
            'event_busy', ['screen' => 'appointment', 'id' => $appt->id]));
        if ($wasApproved) {
            $this->notifier->doctor($appt->doctor, new LavishNotification('appointment.cancelled', 'Appointment cancelled',
                "{$appt->client->name} — {$appt->service->name} on {$appt->starts_at->format('D j M, H:i')} was cancelled by reception.", 'event_busy', null, '/appointments'));
        }

        return $appt;
    }

    /** The client cancels in the app (pending or approved, before it starts). */
    public function cancelByClient(Appointment $appt): Appointment
    {
        if (! in_array($appt->status, [AppointmentStatus::Pending, AppointmentStatus::Approved], true) || $appt->starts_at->isPast()) {
            throw BusinessRuleException::make('not_cancellable', 'This appointment can no longer be cancelled.');
        }
        $wasApproved = $appt->status === AppointmentStatus::Approved;
        $appt->update(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now()]);
        $this->payments->cancelFor($appt->payment, 'cancelled by the client');

        $text = "{$appt->client->name} cancelled {$appt->service->name} on {$appt->starts_at->format('D j M, H:i')}.";
        $this->notifier->staff('appointments.approve', new LavishNotification('appointment.cancelled', 'Booking cancelled', $text, 'event_busy', null, '/appointments'));
        if ($wasApproved) {
            $this->notifier->doctor($appt->doctor, new LavishNotification('appointment.cancelled', 'Booking cancelled', $text, 'event_busy', null, '/appointments'));
        }

        return $appt;
    }

    /** The doctor marks the treatment done. Unlocks chat with the client. */
    public function complete(Appointment $appt, User $by): Appointment
    {
        if ($appt->status !== AppointmentStatus::Approved) {
            throw BusinessRuleException::make('not_approved', 'Only approved appointments can be marked as done.');
        }
        if (! $by->hasRole('ceo') && $appt->doctor?->user_id !== $by->id) {
            throw BusinessRuleException::make('not_your_patient', 'Only the treating doctor can mark this appointment as done.', status: 403);
        }

        $appt->update(['status' => AppointmentStatus::Completed, 'completed_at' => now()]);
        $client = $appt->client;
        $client->first_treated_at ??= now();
        $client->save();

        if ($client->user_id) {
            $this->chat->clientDoctor($client, $appt->doctor);
        }
        $this->notifier->client($client, new LavishNotification(
            'appointment.completed', 'Thank you for visiting Lavish',
            "Your {$appt->service->name} is complete. You can now chat with {$appt->doctor->name} about your results and aftercare.",
            'forum', ['screen' => 'chat', 'id' => $appt->doctor_id]));

        // Lavish Rewards: credited now if the visit is already paid, otherwise when the payment comes in.
        $this->loyalty->creditVisit($appt);

        return $appt;
    }

    /**
     * Reception books a walk-in or phone client: client (new or existing) → intake history → approved slot.
     *
     * @param  array  $data  see Staff\WalkInRequest
     * @return array{appointment: Appointment, client: Client}
     */
    public function walkIn(array $data, User $by): array
    {
        $service = Service::query()->active()->findOrFail($data['service_id']);
        $doctor = Doctor::query()->bookable()->findOrFail($data['doctor_id']);
        [$start, $end] = $this->window($data['date'], $data['time'], $service, forClient: false);

        $clashes = $this->schedule->clashes($doctor, $start, $end);
        if ($clashes) {
            throw BusinessRuleException::make('doctor_busy', "{$doctor->name} is busy at that time.", ['clashes' => $clashes]);
        }

        $result = DB::transaction(function () use ($data, $by, $service, $doctor, $start, $end) {
            $client = $this->findOrCreateClient($data, $by, $doctor);
            if ($this->schedule->clientBusy($client, $start, $end)) {
                throw BusinessRuleException::make('client_double_booked', "{$client->name} already has an appointment at that time.");
            }
            if (! empty($data['history'])) {
                ClientHistory::updateOrCreate(['client_id' => $client->id], [
                    ...array_intersect_key($data['history'], array_flip(ClientHistory::FIELDS)),
                    'recorded_by' => $by->id,
                ]);
            }

            $appt = Appointment::create([
                'client_id' => $client->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'branch_id' => $service->is_online ? null : ($data['branch_id'] ?? $doctor->branch()?->id ?? $by->staffProfile?->branch_id),
                'is_online' => $service->is_online,
                'starts_at' => $start,
                'ends_at' => $end,
                'duration_minutes' => $service->duration_minutes,
                'price' => $data['price'] ?? $service->price,
                'status' => AppointmentStatus::Approved,
                'source' => BookingSource::from($data['source'] ?? BookingSource::WalkIn->value),
                'client_note' => $data['history']['concern'] ?? null,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'created_by' => $by->id,
            ]);
            if ($appt->is_online) {
                $appt->update(['video_link' => $this->videoLink($appt)]);
            }
            $this->payments->createFor($appt, $client, $appt->price, $service->name);

            return ['appointment' => $appt, 'client' => $client];
        });

        /** @var Client $client */
        $client = $result['client'];
        $appt = $result['appointment'];
        $invite = ! empty($data['send_app_invite']) && ! $client->hasApp();
        if ($invite) {
            $client->update(['app_invited_at' => now()]);
        }
        if (! empty($data['send_sms'])) {
            $first = Str::before($client->name, ' ');
            $this->messenger->sms($client->phone,
                "Lavish Medical Spa: Hi {$first}, your {$service->name} with {$doctor->name} is booked for {$start->format('D j M')} at {$start->format('H:i')}."
                .($invite ? ' Get the Lavish app to manage bookings & chat with your doctor: '.config('lavish.app_download_url') : ''),
                $appt);
        }
        $this->notifier->doctor($doctor, new LavishNotification('appointment.assigned', 'New appointment',
            "{$client->name} — {$service->name} on {$start->format('D j M, H:i')} was booked at reception.", 'event', null, '/appointments?focus='.$appt->id));

        return ['appointment' => $appt->refresh(), 'client' => $client->refresh()];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(string $date, string $time, Service $service, bool $forClient): array
    {
        if (! in_array($time, config('lavish.booking.slots'), true)) {
            throw BusinessRuleException::make('invalid_slot', 'Please choose one of the offered times.');
        }
        $start = ScheduleService::at($date, $time);
        $end = $start->addMinutes($service->duration_minutes);

        if ($forClient && $start->lt(now()->addMinutes(config('lavish.booking.min_lead_minutes')))) {
            throw BusinessRuleException::make('too_soon', 'That time is too soon. Please pick a later slot.');
        }
        if (! $forClient && $start->lt(now()->subMinutes(30))) {
            throw BusinessRuleException::make('in_the_past', 'That time has already passed.');
        }
        if ($start->gt(now()->addDays(config('lavish.booking.horizon_days')))) {
            throw BusinessRuleException::make('too_far', 'Bookings open '.config('lavish.booking.horizon_days').' days ahead.');
        }

        return [$start, $end];
    }

    private function findOrCreateClient(array $data, User $by, Doctor $doctor): Client
    {
        if (! empty($data['client_id'])) {
            return Client::query()->findOrFail($data['client_id']);
        }
        $phone = Phone::normalize($data['client']['phone']);
        $existing = Client::query()->where('phone', $phone)->first();
        if ($existing) {
            return $existing;
        }

        return Client::create([
            'name' => $data['client']['name'],
            'phone' => $phone,
            'email' => $data['client']['email'] ?? null,
            'gender' => $data['client']['gender'] ?? null,
            'date_of_birth' => $data['client']['date_of_birth'] ?? null,
            'source' => $data['source'] ?? BookingSource::WalkIn->value,
            'primary_doctor_id' => $doctor->id,
            'preferred_branch_id' => $by->staffProfile?->branch_id,
            'created_by' => $by->id,
        ]);
    }

    private function videoLink(Appointment $appt): string
    {
        $cfg = config('lavish.online');

        return rtrim($cfg['video_base_url'], '/').'/'.$cfg['room_prefix'].'-'.$appt->id.'-'.Str::lower(Str::random(10));
    }
}
