<?php

namespace App\Http\Resources;

use App\Models\ClientHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientHistory */
class ClientHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'source' => $this->source,
            'concern' => $this->concern,
            'skin_type' => $this->skin_type,
            'allergies' => $this->allergies,
            'medications' => $this->medications,
            'conditions' => $this->conditions,
            'previous_treatments' => $this->previous_treatments,
            'pregnant' => $this->pregnant,
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->name),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
