<?php

namespace App\Enums;

enum BookingSource: string
{
    use HasValues;

    case App = 'app';
    case WalkIn = 'walk_in';
    case PhoneCall = 'phone_call';
    case Referral = 'referral';
}
