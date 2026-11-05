<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Complaint */
class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;
        $messages = $this->relationLoaded('messages') ? $this->messages : null;

        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'subject' => $this->subject,
            'message' => $this->message,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'reference' => $this->reference(),
            'appointment_id' => $this->appointment_id,
            'order_id' => $this->order_id,
            'latest_reply' => $messages?->where('from_staff', true)->last()?->body,
            'messages' => $messages ? $messages->map(fn ($m) => [
                'id' => $m->id,
                'body' => $m->body,
                'from_staff' => $m->from_staff,
                'author' => $m->from_staff ? ($staff ? ($m->user?->name ?? 'Lavish') : 'Lavish client care') : ($m->user?->name ?? $this->client?->name),
                'created_at' => $m->created_at->toIso8601String(),
            ]) : null,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'feedback' => $this->feedback_rating ? ['rating' => $this->feedback_rating, 'comment' => $this->feedback_comment] : null,
            'can_give_feedback' => $this->when(! $staff, fn () => $this->status->value === 'resolved' && ! $this->feedback_rating),
            'created_at' => $this->created_at->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'client' => $this->when($staff && $this->relationLoaded('client'), fn () => ['id' => $this->client->id, 'name' => $this->client->name, 'phone' => $this->client->phone]),
        ];
    }
}
