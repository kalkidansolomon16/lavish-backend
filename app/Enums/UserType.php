<?php

namespace App\Enums;

enum UserType: string
{
    use HasValues;

    case Staff = 'staff';     // web portal
    case Client = 'client';   // mobile app — patients
    case Student = 'student'; // mobile app — academy students
}
