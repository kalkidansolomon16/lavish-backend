<?php

namespace App\Http\Resources;

use App\Models\RewardRedemption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RewardRedemption  A redeemed reward waiting in the wallet. */
class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status === 'issued' && $this->expires_at->isPast() ? 'expired' : $this->status;

        return [
            'id' => $this->id,
            'code' => $this->voucher_code,
            'status' => $status,
            'name' => $this->reward->name,
            'summary' => $this->reward->summary(),
            'reward_type' => $this->reward->reward_type,
            'value' => $this->reward->value,             // ETB off (discount vouchers)
            'reward_id' => $this->reward_id,
            'service' => $this->reward->service ? ['id' => $this->reward->service->id, 'name' => $this->reward->service->name] : null,
            'points_spent' => $this->points_spent,
            'discount_applied' => $this->discount_applied,
            'expires_at' => $this->expires_at->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(),
            'used_on_appointment_id' => $this->used_on_appointment_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
