<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Notifications\LavishNotification;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Every 15 minutes (routes/console.php):
 *   - from 18:00, remind clients of their approved appointments tomorrow;
 *   - ~2 hours before an approved appointment, remind them again ("in 2 hours").
 * Each reminder is sent once (reminded_* columns). Clients without the app are skipped by the Notifier.
 */
class AppointmentReminders extends Command
{
    protected $signature = 'lavish:appointment-reminders';

    protected $description = 'Send appointment reminders to clients (evening before and 2 hours before)';

    public function handle(Notifier $notifier): int
    {
        $sent = 0;
        $now = now();
        $where = fn (Appointment $a) => $a->is_online ? 'online (video call)' : 'at Lavish '.($a->branch?->name ?? '');

        if ($now->hour >= 18) {
            Appointment::query()->with('client.user', 'service', 'branch', 'doctor.user')
                ->where('status', AppointmentStatus::Approved)->whereNull('reminded_day_before_at')
                ->whereBetween('starts_at', [$now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay()])
                ->get()->each(function (Appointment $a) use ($notifier, $where, &$sent) {
                    $a->forceFill(['reminded_day_before_at' => now()])->save();
                    $notifier->client($a->client, new LavishNotification('appointment.reminder', 'Your appointment is tomorrow',
                        "{$a->service->name} at {$a->starts_at->format('H:i')} {$where($a)}".($a->doctor ? " with {$a->doctor->name}" : '').'.',
                        'event_available', ['screen' => 'appointment', 'id' => $a->id]));
                    $sent++;
                });
        }

        Appointment::query()->with('client.user', 'service', 'branch')
            ->where('status', AppointmentStatus::Approved)->whereNull('reminded_soon_at')
            ->whereBetween('starts_at', [$now->copy()->addMinutes(90), $now->copy()->addMinutes(135)])
            ->get()->each(function (Appointment $a) use ($notifier, $where, &$sent) {
                $a->forceFill(['reminded_soon_at' => now()])->save();
                $notifier->client($a->client, new LavishNotification('appointment.reminder', 'See you in 2 hours',
                    "{$a->service->name} at {$a->starts_at->format('H:i')} {$where($a)}.".($a->is_online ? ' Your video link is in the app.' : ' Please arrive 10 minutes early.'),
                    'schedule', ['screen' => 'appointment', 'id' => $a->id]));
                $sent++;
            });

        $this->line("reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
