<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    use HasValues;

    case Active = 'active';
    case Completed = 'completed';
    case Withdrawn = 'withdrawn';
}
