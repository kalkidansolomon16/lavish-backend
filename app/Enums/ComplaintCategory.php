<?php

namespace App\Enums;

enum ComplaintCategory: string
{
    use HasValues;

    case Treatment = 'Treatment';
    case Appointment = 'Appointment';
    case Order = 'Order';
    case Staff = 'Staff';
    case App = 'App';
    case Other = 'Other';
}
