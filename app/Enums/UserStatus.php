<?php

namespace App\Enums;

enum UserStatus: string
{
    use HasValues;

    case Active = 'active';
    case PendingVerification = 'pending_verification'; // client registered but has not entered the SMS code yet
    case Suspended = 'suspended';
}
