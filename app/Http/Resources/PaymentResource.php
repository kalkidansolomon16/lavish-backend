<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'description' => $this->description,
            'amount' => $this->amount,
            'discount_amount' => $this->discount_amount,   // tier price + voucher + points (earns no points)
            'gift_card_amount' => $this->gift_card_amount,
            'points_used' => $this->points_used,
            'due_amount' => $this->dueAmount(),             // what is left to pay in money
            'currency' => $this->currency,
            'status' => $this->status->value,
            'method' => $this->method ? ['value' => $this->method->value, 'label' => $this->method->label()] : null,
            'provider' => $this->provider,
            'tx_ref' => $this->tx_ref,
            'provider_reference' => $this->provider_reference,
            'checkout_url' => $this->when($this->status === PaymentStatus::Pending, $this->checkout_url),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'payable' => $this->payable_type ? ['type' => $this->payable_type, 'id' => $this->payable_id] : null,
            // Latest receipt (proof of payment) — `receipts` has all of them when loaded.
            'receipt' => $this->relationLoaded('receipts') ? $this->receipts->first()?->toArray() : null,
            'receipts' => $this->when($staff && $this->relationLoaded('receipts'), fn () => $this->receipts->map->toArray()),
            'client' => $this->when($staff && $this->relationLoaded('client'), fn () => ['id' => $this->client->id, 'name' => $this->client->name, 'phone' => $this->client->phone]),
            'recorded_by' => $this->when($staff && $this->relationLoaded('recorder'), fn () => $this->recorder?->name),
        ];
    }
}
