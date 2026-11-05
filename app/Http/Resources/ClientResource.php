<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Staff view of a client (patient). @mixin \App\Models\Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeHistory = $user?->hasPermission('clients.history', 'page.clients') ?? false;
        $canSeeMoney = $user?->hasPermission('finance.revenue') ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'source' => $this->source,
            'notes' => $this->notes,
            'has_app' => $this->hasApp(),
            'app_invited_at' => $this->app_invited_at?->toIso8601String(),
            'first_treated_at' => $this->first_treated_at?->toIso8601String(),
            'treated' => $this->first_treated_at !== null,
            'joined_at' => $this->created_at->toIso8601String(),
            'last_treatment' => $this->last_treatment ?? null,
            'primary_doctor' => $this->whenLoaded('primaryDoctor', fn () => $this->primaryDoctor ? ['id' => $this->primaryDoctor->id, 'name' => $this->primaryDoctor->name] : null),
            'preferred_branch' => $this->whenLoaded('preferredBranch', fn () => $this->preferredBranch ? ['id' => $this->preferredBranch->id, 'name' => $this->preferredBranch->name] : null),
            'history' => $this->when($canSeeHistory && $this->relationLoaded('history'), fn () => $this->history ? new ClientHistoryResource($this->history) : null),
            'total_spent' => $this->when($canSeeMoney && isset($this->total_spent), fn () => (int) $this->total_spent),
            'appointments' => AppointmentResource::collection($this->whenLoaded('appointments')),
            'payments' => $this->when($canSeeMoney, fn () => PaymentResource::collection($this->whenLoaded('payments'))),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
            'prescriptions' => PrescriptionResource::collection($this->whenLoaded('prescriptions')),
        ];
    }
}
