<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Load with Product::withStock() so stock/reserved come from one query.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;
        $available = $this->available();

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'size' => $this->size,
            'description' => $this->description,
            'usage' => $this->usage,
            'price' => $this->price,
            'image_url' => $this->imageUrl(),
            'hue' => $this->hue,
            'in_stock' => $available > 0,
            'available' => $available,
            // staff-only
            'stock' => $this->when($staff, fn () => $this->stockTotal()),
            'reserved' => $this->when($staff, fn () => $this->reservedTotal()),
            'reorder_level' => $this->when($staff, $this->reorder_level),
            'low_stock' => $this->when($staff, fn () => $this->stockTotal() <= $this->reorder_level),
            'active' => $this->when($staff, $this->active),
            'branches' => $this->when($staff && $this->relationLoaded('stocks'), fn () => $this->stocks->map(fn ($s) => [
                'branch_id' => $s->branch_id, 'name' => $s->branch?->name, 'quantity' => $s->quantity,
            ])->values()),
        ];
    }
}
