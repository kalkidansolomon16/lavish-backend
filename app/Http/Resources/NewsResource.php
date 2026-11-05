<?php

namespace App\Http\Resources;

use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin News */
class NewsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'tag' => $this->tag,
            'image_url' => $this->imageUrl(),
            'published_at' => $this->published_at?->toIso8601String(),
            'published' => $this->when($staff, $this->published),
            'author' => $this->when($staff && $this->relationLoaded('author'), fn () => $this->author?->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
