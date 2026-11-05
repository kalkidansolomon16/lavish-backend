<?php

namespace App\Http\Resources;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as seen by the signed-in user: the title is the other person's name
 * (or the group name), plus last message and unread count.
 * Controllers set ->unread (int) and, for client chats, ->locked (bool) before returning.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $me = $request->user();
        $others = $this->relationLoaded('users') ? $this->users->where('id', '!=', $me?->id)->values() : collect();

        $title = match ($this->type) {
            ConversationType::Group => $this->name,
            ConversationType::ClientDoctor => $me?->isClient() ? $this->doctor?->name : $this->client?->name,
            ConversationType::StudentTeacher => $me?->isStudent()
                ? ($this->enrollment?->course?->title ?? 'Your teachers')
                : ($this->enrollment?->student?->name ?? 'Student'),
            default => $others->first()?->name ?? 'Conversation',
        };

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $title,
            'about' => $this->about,
            'doctor' => $this->when($this->type === ConversationType::ClientDoctor && $this->relationLoaded('doctor'), fn () => ['id' => $this->doctor->id, 'name' => $this->doctor->name, 'specialty' => $this->doctor->specialty, 'hue' => $this->doctor->hue]),
            'client' => $this->when($this->type === ConversationType::ClientDoctor && $this->relationLoaded('client') && $me?->isStaff(), fn () => ['id' => $this->client->id, 'name' => $this->client->name]),
            'enrollment_id' => $this->enrollment_id,
            'members' => $this->when($this->relationLoaded('users') && in_array($this->type, [ConversationType::Group, ConversationType::Direct, ConversationType::StudentTeacher], true),
                fn () => $this->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()),
            'created_by' => $this->created_by,
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
                'body' => $this->latestMessage->body,
                'mine' => $this->latestMessage->user_id === $me?->id,
                'created_at' => $this->latestMessage->created_at->toIso8601String(),
            ] : null),
            'unread' => $this->unread ?? 0,
            'locked' => $this->locked ?? false,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
