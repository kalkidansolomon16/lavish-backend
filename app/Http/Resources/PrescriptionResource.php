<?php

namespace App\Http\Resources;

use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Prescription */
class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'note' => $this->note,
            'doctor' => $this->whenLoaded('doctor', fn () => ['id' => $this->doctor->id, 'name' => $this->doctor->name, 'specialty' => $this->doctor->specialty]),
            'client' => $this->when($staff && $this->relationLoaded('client'), fn () => ['id' => $this->client->id, 'name' => $this->client->name, 'phone' => $this->client->phone, 'has_app' => $this->client->hasApp()]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($i) {
                $p = $i->product;
                $available = $p ? $p->available() : 0;

                return [
                    'id' => $i->id,
                    'product' => $p ? ['id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'size' => $p->size, 'image_url' => $p->imageUrl(), 'hue' => $p->hue, 'in_stock' => $available > 0] : null,
                    'custom_name' => $i->custom_name,
                    'is_custom' => $i->isCustom(),
                    'quantity' => $i->quantity,
                    'instructions' => $i->instructions,
                    'reminder' => $i->reminder(),             // {times, starts_on, ends_on, duration_days, active} or null
                    'was_out_of_stock' => $i->was_out_of_stock,
                    'suggested_at' => $i->suggested_at?->toIso8601String(),
                    // a doctor may re-suggest it now
                    'can_suggest' => $i->was_out_of_stock && ! $i->suggested_at && $available > 0,
                ];
            })),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
