<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account (GET /auth/me and the login response).
 * For staff it carries everything the web portal needs to build its sidebar and hide buttons:
 * roles, level, permissions and the visible pages.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'phone_local' => Phone::local($this->phone),
            'email' => $this->email,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'must_change_password' => $this->must_change_password,
            'avatar_url' => $this->avatarUrl(),
            'locale' => $this->locale,
        ];

        if ($this->isStaff()) {
            $this->loadMissing('roles', 'staffProfile.branch', 'doctor');
            $permissions = $this->permissionKeys();
            $teamOnly = $this->roles->isNotEmpty() && $this->roles->every('team_only', true);
            $primary = $this->roles->sortBy('level')->first();

            return $base + [
                'roles' => $this->roles->sortBy('level')->values()->map(fn ($r) => ['key' => $r->key, 'label' => $r->label]),
                'portal' => $primary ? ['key' => $primary->key, 'label' => $primary->label] : null,
                'level' => $this->level(),
                'permissions' => $permissions,
                'pages' => collect(config('permissions.pages'))
                    ->filter(fn ($p) => $p === 'dashboard' ? ! $teamOnly : in_array("page.{$p}", $permissions, true))
                    ->values(),
                'position' => $this->staffProfile?->position,
                'branch' => $this->staffProfile?->branch ? new BranchResource($this->staffProfile->branch) : null,
                'doctor_id' => $this->doctor?->id,
            ];
        }

        if ($this->isClient()) {
            $client = $this->client()->with('preferredBranch')->first();

            return $base + [
                'client_id' => $client?->id,
                'gender' => $client?->gender,
                'date_of_birth' => $client?->date_of_birth?->toDateString(),
                'preferred_branch' => $client?->preferredBranch ? new BranchResource($client->preferredBranch) : null,
                'joined_at' => ($client?->created_at ?? $this->created_at)->toIso8601String(),
            ];
        }

        return $base + [
            'username' => Phone::local($this->phone),
            'joined_at' => $this->created_at->toIso8601String(),
        ];
    }
}
