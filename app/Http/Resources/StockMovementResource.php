<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMovement */
class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->whenLoaded('product', fn () => ['id' => $this->product->id, 'name' => $this->product->name, 'sku' => $this->product->sku]),
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'moved' => $this->moved,
            'from_branch' => $this->whenLoaded('fromBranch', fn () => $this->fromBranch?->name),
            'to_branch' => $this->whenLoaded('toBranch', fn () => $this->toBranch?->name),
            'balance_after' => $this->balance_after,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'by' => $this->whenLoaded('user', fn () => $this->user?->name ?? 'System'),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
