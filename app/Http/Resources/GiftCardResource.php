<?php

namespace App\Http\Resources;

use App\Models\GiftCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GiftCard
 * Staff never get the full code here (they scan or type it). In the app the buyer and the recipient get
 * `code` for e-gift cards, to show the card's barcode at the front desk.
 */
class GiftCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;
        $client = $request->user()?->client;
        $role = match (true) {
            $staff => null,
            $client && $this->recipient_client_id === $client->id => 'received',
            $client && $this->purchaser_client_id === $client->id => 'sent',
            default => null,
        };
        $expired = $this->status === 'active' && $this->expires_at?->isPast();

        return [
            'id' => $this->id,
            'masked_code' => $this->maskedCode(),
            'code_last4' => $this->code_last4,
            'type' => $this->type,                       // monetary | service | promo
            'label' => $this->label(),
            'service' => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name, 'price' => $this->service->price] : null,
            'initial_value' => $this->initial_value,
            'balance' => $this->balance,
            'status' => $expired ? 'expired' : $this->status, // pending_payment | awaiting_activation | in_stock | active | redeemed | expired | void
            'code' => $this->when(! $staff && $role !== null && ! in_array($this->status, ['pending_payment'], true), fn () => $this->code_encrypted),
            // Printed cards: staff see the number to show / re-print its barcode.
            'card_number' => $this->when($staff && $this->physical && $request->user()?->hasPermission('giftcards.sell', 'giftcards.stock'), fn () => $this->code_encrypted),
            'used' => max(0, $this->initial_value - $this->balance),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'activated_by' => $this->when($staff && $this->relationLoaded('activator'), fn () => $this->activator?->name),
            'payment_status' => $this->when($this->relationLoaded('payment'), fn () => $this->payment?->status?->value),
            'purchaser' => $this->when($staff && $this->relationLoaded('purchaser'), fn () => $this->purchaser
                ? ['id' => $this->purchaser->id, 'name' => $this->purchaser->name, 'phone' => $this->purchaser->phone, 'email' => $this->purchaser->email] : null),
            'recipient_client' => $this->when($staff && $this->relationLoaded('recipient'), fn () => $this->recipient
                ? ['id' => $this->recipient->id, 'name' => $this->recipient->name, 'phone' => $this->recipient->phone] : null),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'design' => $this->design,
            'message' => $this->message,
            'from' => $this->purchaser_name,
            'recipient' => ['name' => $this->recipient_name, 'phone' => $this->recipient_phone, 'email' => $this->recipient_email],
            'delivery' => $this->delivery,               // sms | email | print
            'deliver_at' => $this->deliver_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'mine' => $this->when(! $staff, $role),       // received | sent (client app)
            'payment_id' => $this->when($this->relationLoaded('payment'), fn () => $this->payment?->id),
            'source' => $this->when($staff, $this->source),
            'batch' => $this->when($staff, $this->batch),
            'physical' => (bool) $this->physical,
            'sold_by' => $this->when($staff && $this->relationLoaded('seller'), fn () => $this->seller?->name),
            'purchaser_client_id' => $this->when($staff, $this->purchaser_client_id),
            'recipient_client_id' => $this->when($staff, $this->recipient_client_id),
            'replaces_id' => $this->when($staff, $this->replaces_id),
            // Staff: the messages sent to the recipient, and whether they really left the server.
            'messages' => $this->when($staff && $this->relationLoaded('outboundMessages'), fn () => $this->outboundMessages->map(fn ($m) => [
                'channel' => $m->channel, 'to' => $m->to, 'at' => $m->created_at->toIso8601String(),
                'status' => $m->status === 'failed' ? 'failed'
                    : (($m->channel === 'email' && config('mail.default') === 'log') || ($m->channel === 'sms' && config('lavish.sms.driver') === 'log') ? 'not_set_up' : 'sent'),
                'error' => $m->error,
            ])),
            'ledger' => $this->when($this->relationLoaded('ledger'), fn () => $this->ledger->map(fn ($l) => [
                'id' => $l->id, 'type' => $l->type, 'amount' => $l->amount, 'balance_after' => $l->balance_after,
                'appointment_id' => $l->appointment_id, 'order_id' => $l->order_id, 'reason' => $l->reason,
                'by' => $staff ? $l->creator?->name : null, 'created_at' => $l->created_at->toIso8601String(),
            ])),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
