<?php

namespace App\Enums;

enum ComplaintStatus: string
{
    use HasValues;

    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
}
