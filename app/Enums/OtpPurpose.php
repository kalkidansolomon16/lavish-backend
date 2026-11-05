<?php

namespace App\Enums;

enum OtpPurpose: string
{
    use HasValues;

    case Register = 'register';
    case ResetPassword = 'reset_password';
}
