<?php

namespace App\Enums;

enum StockMovementType: string
{
    use HasValues;

    case In = 'in';             // stock received
    case Sold = 'sold';         // order delivered
    case Adjust = 'adjust';     // stock count, damaged, expired…
    case Transfer = 'transfer'; // moved between branches (total unchanged)
}
