<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An item on the reward ladder, bought with points. */
class Reward extends Model
{
    public const TYPES = ['discount', 'free_service', 'product'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'available_from' => 'datetime', 'available_until' => 'datetime'];
    }

    /** Active and inside its date window (if it has one) — what clients can see and redeem. */
    public function scopeAvailable(Builder $q, $at = null): Builder
    {
        $at ??= now();

        return $q->where('active', true)
            ->where(fn ($q) => $q->whereNull('available_from')->orWhere('available_from', '<=', $at))
            ->where(fn ($q) => $q->whereNull('available_until')->orWhere('available_until', '>=', $at));
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function tierRequired(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'tier_required_id');
    }

    /** What the voucher is worth against a bill for $service (ETB). */
    public function discountFor(?Service $service, int $billAmount): int
    {
        return match ($this->reward_type) {
            'discount' => min($this->value, $billAmount),
            'free_service' => $service && $service->id === $this->service_id ? $billAmount : 0,
            default => 0, // product rewards are handed over at the desk, not taken off a treatment bill
        };
    }

    public function summary(): string
    {
        return match ($this->reward_type) {
            'discount' => 'ETB '.number_format($this->value).' off any treatment',
            'free_service' => 'One free '.($this->service?->name ?? 'treatment'),
            'product' => 'Free '.($this->product?->name ?? 'product').' — collect at the front desk',
            default => $this->name,
        };
    }
}
