<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attachment */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'is_image' => str_starts_with($this->mime, 'image/'),
        ];
    }
}
