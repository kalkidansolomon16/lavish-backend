<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Glow → Radiance → Lavish Elite. A client's tier comes from points earned in the last 12 months. */
class LoyaltyTier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'earn_multiplier' => 'float',
            'points_never_expire' => 'boolean',
            'benefits' => 'array',
        ];
    }

    /** All tiers, lowest first. */
    public static function ladder()
    {
        return static::query()->orderBy('min_points_12m')->get();
    }

    public static function forPoints(int $points12m): ?self
    {
        return static::query()->where('min_points_12m', '<=', $points12m)->orderByDesc('min_points_12m')->first();
    }
}
