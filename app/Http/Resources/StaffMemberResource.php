<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A person on the Staff page (doctors included). @mixin \App\Models\User */
class StaffMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $doctor = $this->relationLoaded('doctor') ? $this->doctor : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status->value,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->sortBy('level')->values()->map(fn ($r) => ['key' => $r->key, 'label' => $r->label])),
            'level' => $this->whenLoaded('roles', fn () => $this->level()),
            'position' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile?->position),
            'branch' => $this->whenLoaded('staffProfile', fn () => $this->staffProfile?->branch ? ['id' => $this->staffProfile->branch->id, 'name' => $this->staffProfile->branch->name] : null),
            'doctor' => $doctor ? new DoctorResource($doctor) : null,
            'activated' => ! $this->must_change_password && $this->last_login_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
