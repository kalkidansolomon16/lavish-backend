<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    use HasValues;

    case Pending = 'pending';     // requested in the app, waiting for reception
    case Approved = 'approved';   // confirmed by reception; the doctor is notified
    case Declined = 'declined';   // reception declined
    case Cancelled = 'cancelled'; // the client cancelled
    case Completed = 'completed'; // the doctor marked the treatment done; chat unlocks

    /** Statuses that hold a slot in the doctor's calendar. */
    public static function holding(): array
    {
        return [self::Pending->value, self::Approved->value];
    }
}
