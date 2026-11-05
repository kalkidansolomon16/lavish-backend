<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    use HasValues;

    case Submitted = 'submitted';
    case Graded = 'graded';
    case Returned = 'returned'; // teacher asked for a redo
}
