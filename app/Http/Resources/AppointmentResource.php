<?php

namespace App\Http\Resources;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Appointment */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;
        $doctor = $this->relationLoaded('doctor') ? $this->doctor : null;
        $payment = $this->relationLoaded('payment') ? $this->payment : null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'is_online' => $this->is_online,
            'service' => $this->whenLoaded('service', fn () => ['id' => $this->service->id, 'name' => $this->service->name, 'icon' => $this->service->icon]),
            'doctor' => $doctor ? ['id' => $doctor->id, 'name' => $doctor->name, 'specialty' => $doctor->specialty, 'hue' => $doctor->hue] : null,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'name' => $this->branch->name] : null),
            'date' => $this->starts_at->toDateString(),
            'time' => $this->starts_at->format('H:i'),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'price' => $this->price,
            'note' => $this->client_note,
            'source' => $this->source->value,
            'video_link' => $staff ? $this->video_link : ($this->relationLoaded('payment') && $this->videoLinkVisibleToClient() ? $this->video_link : null),
            // Online session confirmed but not paid yet: the app shows "Pay to unlock your video link".
            'video_link_locked' => $this->is_online && $this->status === AppointmentStatus::Approved && $payment?->status !== PaymentStatus::Paid,
            'decline_reason' => $this->decline_reason,
            'payment' => $payment ? new PaymentResource($payment) : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            // client app helpers
            'can_cancel' => $this->when(! $staff, fn () => in_array($this->status, [AppointmentStatus::Pending, AppointmentStatus::Approved], true) && $this->starts_at->isFuture()),
            'can_review' => $this->when(! $staff && $this->relationLoaded('review'), fn () => $this->status === AppointmentStatus::Completed && ! $this->review),
            // staff-only
            'client' => $this->when($staff && $this->relationLoaded('client'), fn () => ['id' => $this->client->id, 'name' => $this->client->name, 'phone' => $this->client->phone, 'has_app' => $this->client->hasApp()]),
            'approved_by' => $this->when($staff && $this->relationLoaded('approver'), fn () => $this->approver?->name),
        ];
    }
}
