<?php

namespace App\Http\Resources;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'about' => $this->about,
            'level' => $this->level,
            'weeks' => $this->weeks,
            'price' => $this->price,
            'mode' => $this->mode,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null),
            'starts_on' => $this->starts_on?->toDateString(),
            'requirement' => $this->requirement,
            'hue' => $this->hue,
            'teachers' => $this->whenLoaded('teachers', fn () => $this->teachers->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'specialty' => $d->specialty])),
            // public catalogue shows only module titles; staff and enrolled students get full modules
            'module_titles' => $this->whenLoaded('modules', fn () => $this->modules->pluck('title')),
            'modules' => $this->when($staff && $this->relationLoaded('modules'), fn () => CourseModuleResource::collection($this->modules)),
            'enrollments_count' => $this->when($staff && isset($this->enrollments_count), fn () => $this->enrollments_count),
            'active' => $this->when($staff, $this->active),
        ];
    }
}
