<?php

namespace App\Enums;

enum DoctorAvailability: string
{
    use HasValues;

    case Available = 'available';
    case InSurgery = 'in_surgery';
    case OffToday = 'off_today';
    case OnLeave = 'on_leave';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::InSurgery => 'In surgery',
            self::OffToday => 'Off today',
            self::OnLeave => 'On leave',
        };
    }
}
