<?php

namespace App\Http\Resources;

use App\Models\PointsLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PointsLedger  One line of the points history: earn, spend, expiry… with its reason. */
class PointsLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'type' => $this->type,                 // earn | redeem | expire | adjust | reverse
            'points' => $this->points,             // + earned, − spent
            'description' => $this->description,
            'balance_after' => $this->balance_after,   // points left after this change
            'source' => $this->source_type ? ['type' => $this->source_type, 'id' => $this->source_id] : null,
            'expires_at' => $this->points > 0 ? $this->expires_at?->toIso8601String() : null,
            'remaining' => $this->points > 0 ? $this->remaining : null,
            'reason' => $this->reason,
            'by' => $this->when($staff, fn () => $this->creator?->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
