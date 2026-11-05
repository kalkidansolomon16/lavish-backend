<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Stock lives per branch in branch_stock. Use the withStock() scope to get:
 *  - stock    : units on hand across all branches
 *  - reserved : units promised to open (not yet delivered) orders
 * available = stock - reserved is what can still be sold.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'reorder_level' => 'integer', 'active' => 'boolean'];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function scopeWithStock(Builder $q): Builder
    {
        return $q->withSum('stocks as stock', 'quantity')
            ->withSum(['orderItems as reserved' => fn ($i) => $i->whereHas('order', fn ($o) => $o->whereIn('status', OrderStatus::openValues()))], 'quantity');
    }

    public function stockTotal(): int
    {
        return (int) ($this->attributes['stock'] ?? $this->stocks()->sum('quantity'));
    }

    public function reservedTotal(): int
    {
        if (array_key_exists('reserved', $this->attributes)) {
            return (int) $this->attributes['reserved'];
        }

        return (int) $this->orderItems()->whereHas('order', fn ($o) => $o->whereIn('status', OrderStatus::openValues()))->sum('quantity');
    }

    public function available(): int
    {
        return max(0, $this->stockTotal() - $this->reservedTotal());
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
