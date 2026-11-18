<?php

namespace App\Http\Resources;

use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reward
 * With ->additional context the app gets `affordable` / `locked` for the signed-in client
 * (set as attributes by the controller: _balance, _tier_min).
 */
class RewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $balance = $this->getAttribute('_balance');
        $tierMin = $this->getAttribute('_tier_min');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'summary' => $this->summary(),
            'points_cost' => $this->points_cost,
            'reward_type' => $this->reward_type,
            'value' => $this->value,
            'service' => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name] : null,
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
            'tier_required' => $this->tierRequired ? ['id' => $this->tierRequired->id, 'name' => $this->tierRequired->name] : null,
            'stock' => $this->stock,
            'available_from' => $this->available_from?->toIso8601String(),
            'available_until' => $this->available_until?->toIso8601String(),
            'icon' => $this->icon,
            'active' => $this->active,
            'sort' => $this->sort,
            'affordable' => $balance === null ? null : $balance >= $this->points_cost,
            'locked' => $tierMin === null ? null : ($this->tierRequired && $tierMin < $this->tierRequired->min_points_12m),
        ];
    }
}
