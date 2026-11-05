<?php

namespace App\Enums;

enum DoctorAccess: string
{
    use HasValues;

    case Pending = 'pending'; // cannot log in or be booked yet
    case Active = 'active';
    case Banned = 'banned';   // hidden from the mobile app, cannot log in
}
