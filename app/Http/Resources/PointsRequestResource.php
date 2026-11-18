<?php

namespace App\Http\Resources;

use App\Models\PointsRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PointsRequest  A member's request to use points (app: Rewards → Use my points; web: Points requests). */
class PointsRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'kind' => $this->kind,                     // shop | treatment | other
            'kind_label' => $this->kindLabel(),
            'points' => $this->points,
            'value_etb' => $this->value_etb,
            'note' => $this->note,
            'status' => $this->status,                 // pending | forwarded | completed | rejected | cancelled
            'points_used' => $this->points_used,
            'discount_etb' => $this->discount_etb,
            'staff_note' => $this->staff_note,
            'bill' => $this->when($this->relationLoaded('payment'), fn () => $this->payment
                ? ['id' => $this->payment->id, 'description' => $this->payment->description, 'amount' => $this->payment->amount] : null),
            'client' => $this->when($staff && $this->relationLoaded('client'), fn () => [
                'id' => $this->client->id, 'name' => $this->client->name, 'phone' => $this->client->phone,
                'points_balance' => (int) $this->client->loyalty?->points_balance,
            ]),
            'forwarded_by' => $this->when($staff && $this->relationLoaded('forwarder'), fn () => $this->forwarder?->name),
            'forwarded_at' => $this->forwarded_at?->toIso8601String(),
            'handled_by' => $this->when($staff && $this->relationLoaded('handler'), fn () => $this->handler?->name),
            'handled_at' => $this->handled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
