<?php

namespace App\Enums;

enum PrescriptionStatus: string
{
    use HasValues;

    case Sent = 'sent';       // in the client's app
    case Ordered = 'ordered'; // turned into a shop order
}
