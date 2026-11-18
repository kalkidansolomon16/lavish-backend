<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Program-wide Lavish Rewards / gift card numbers. Always one row — use LoyaltySetting::current(). */
class LoyaltySetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'point_value_etb' => 'float',
            'gift_card_amounts' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create(['gift_card_amounts' => [1000, 2500, 5000, 10000]]);
    }

    /** ETB value of a number of points when spent at checkout (rounded down). */
    public function pointsToEtb(int $points): int
    {
        return (int) floor($points * $this->point_value_etb);
    }

    /** Points needed to cover an ETB amount (rounded up). */
    public function etbToPoints(int $etb): int
    {
        return $this->point_value_etb > 0 ? (int) ceil($etb / $this->point_value_etb) : 0;
    }
}
