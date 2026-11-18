<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EarnRule extends Model
{
    public const TYPES = ['spend', 'product_spend', 'visit', 'referral', 'review', 'birthday', 'signup', 'app_booking'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'active_from' => 'date',
            'active_to' => 'date',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Active today. */
    public function scopeLive(Builder $q): Builder
    {
        $today = now()->toDateString();

        return $q->where('active', true)
            ->where(fn ($q) => $q->whereNull('active_from')->orWhereDate('active_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('active_to')->orWhereDate('active_to', '>=', $today));
    }

    /** The general (not per-service) live rule of a type. */
    public static function general(string $type): ?self
    {
        return static::query()->live()->where('type', $type)->whereNull('service_id')->first();
    }
}
