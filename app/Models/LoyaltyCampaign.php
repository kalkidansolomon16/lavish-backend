<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Double-points days and targeted offers by service or tier. */
class LoyaltyCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'multiplier' => 'float',
            'service_ids' => 'array',
            'tier_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'active' => 'boolean',
            'notify_members' => 'boolean',
            'notified_at' => 'datetime',
        ];
    }

    public function scopeRunning(Builder $q, $at = null): Builder
    {
        $at ??= now();

        return $q->where('active', true)->where('starts_at', '<=', $at)->where('ends_at', '>=', $at);
    }

    public function covers(?int $serviceId, ?int $tierId): bool
    {
        return (empty($this->service_ids) || ($serviceId && in_array($serviceId, $this->service_ids)))
            && (empty($this->tier_ids) || ($tierId && in_array($tierId, $this->tier_ids)));
    }
}
