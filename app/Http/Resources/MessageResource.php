<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'mine' => $this->user_id === $request->user()?->id,
            'sender' => $this->whenLoaded('sender', fn () => $this->sender ? ['id' => $this->sender->id, 'name' => $this->sender->name, 'type' => $this->sender->type->value] : null),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
