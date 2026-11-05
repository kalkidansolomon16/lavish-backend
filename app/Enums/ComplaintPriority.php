<?php

namespace App\Enums;

enum ComplaintPriority: string
{
    use HasValues;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
