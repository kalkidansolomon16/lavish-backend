<?php

namespace App\Http\Resources;

use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Doctor */
class DoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;
        $branch = $this->relationLoaded('user') && $this->user?->relationLoaded('staffProfile') ? $this->user->staffProfile?->branch : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'specialty' => $this->specialty,
            'bio' => $this->bio,
            'years_experience' => $this->years_experience,
            'working_days' => $this->working_days,
            'availability' => ['value' => $this->availability->value, 'label' => $this->availability->label()],
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'photo_url' => $this->photoUrl(),
            'hue' => $this->hue,
            'rating' => isset($this->reviews_avg_rating) ? round((float) $this->reviews_avg_rating, 1) : null,
            'reviews_count' => $this->reviews_count ?? null,
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            // staff-only
            'user_id' => $this->when($staff, $this->user_id),
            'access' => $this->when($staff, $this->access->value),
            'phone' => $this->when($staff, fn () => $this->user?->phone),
            'account_status' => $this->when($staff, fn () => $this->user?->status->value),
            'teaches' => $this->when($staff && $this->relationLoaded('courses'), fn () => $this->courses->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])),
        ];
    }
}
