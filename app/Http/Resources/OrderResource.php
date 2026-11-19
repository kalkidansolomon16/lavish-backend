<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;
        $events = $this->relationLoaded('events') ? $this->events : collect();
        $next = $this->status->next();

        return [
            'id' => $this->id,
            'tracking_code' => $this->trackingCode(),
            'status' => $this->status->value,
            // Delivery confirmation: the client taps "I received my order"; staff then confirm the delivery.
            'received_at' => $this->received_at?->toIso8601String(),
            'received_override' => $this->received_override,
            'can_confirm_received' => in_array($this->status->value, ['out', 'ready'], true) && ! $this->received_at,
            'status_label' => $staff ? $this->status->label() : $this->status->clientLabel(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->product_name,
                'unit_price' => $i->unit_price,
                'quantity' => $i->quantity,
                'line_total' => $i->line_total,
                'image_url' => $i->relationLoaded('product') ? $i->product?->imageUrl() : null,
                'hue' => $i->relationLoaded('product') ? $i->product?->hue : null,
            ])),
            'items_count' => $this->whenLoaded('items', fn () => $this->items->sum('quantity')),
            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'discount' => $this->discount,
            'total' => $this->total,
            'payment_method' => ['value' => $this->payment_method->value, 'label' => $this->payment_method->label()],
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? new PaymentResource($this->payment) : null),
            'delivery_address' => $this->delivery_address,
            'delivery_note' => $this->delivery_note,
            'contact_phone' => $this->contact_phone,
            'courier' => $this->courier_name ? ['name' => $this->courier_name, 'phone' => $this->courier_phone] : null,
            'cancel_reason' => $this->cancel_reason,
            'prescription_id' => $this->prescription_id,
            // The delivery tracker: every step with the time it happened (null = not yet).
            'timeline' => $this->whenLoaded('events', fn () => collect(OrderStatus::flow())->map(fn (OrderStatus $s) => [
                'status' => $s->value,
                'title' => $s->clientLabel(),
                'at' => $events->firstWhere('status', $s)?->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            // staff-only
            'next_action' => $this->when($staff, fn () => $next ? ['status' => $next->value, 'label' => $next->actionLabel(), 'needs_courier' => $next === OrderStatus::Out] : null),
            'client' => $this->when($staff && $this->relationLoaded('client'), fn () => ['id' => $this->client->id, 'name' => $this->client->name, 'phone' => $this->client->phone, 'email' => $this->client->email]),
            'history' => $this->when($staff && $this->relationLoaded('events'), fn () => $events->map(fn ($e) => [
                'status' => $e->status->value, 'note' => $e->note, 'by' => $e->user?->name ?? 'Mobile app', 'at' => $e->created_at->toIso8601String(),
            ])),
            'emails' => $this->when($staff && $this->relationLoaded('emails'), fn () => $this->emails->map(fn ($m) => [
                'to' => $m->to, 'subject' => $m->subject, 'body' => $m->body, 'status' => $m->status, 'at' => $m->created_at->toIso8601String(),
            ])),
        ];
    }
}
