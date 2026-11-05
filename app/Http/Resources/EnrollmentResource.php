<?php

namespace App\Http\Resources;

use App\Models\Enrollment;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Enrollment */
class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $request->user()?->isStaff() ?? false;
        $weeks = $this->relationLoaded('course') ? max(1, $this->course->weeks) : null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'current_week' => $weeks ? min($weeks, max(1, (int) ceil($this->progress / 100 * $weeks))) : null,
            'enrolled_via' => $this->enrolled_via,
            'fee' => $this->fee,
            'paid' => $this->paid_at !== null,
            'course' => new CourseResource($this->whenLoaded('course')),
            'certificate' => $this->whenLoaded('certificate', fn () => $this->certificate ? [
                'number' => $this->certificate->number,
                'issued_at' => $this->certificate->issued_at->toIso8601String(),
                'issued_by' => $this->certificate->issuer?->name,
            ] : null),
            'conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->id),
            'enrolled_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'student' => $this->when($staff && $this->relationLoaded('student'), fn () => [
                'id' => $this->student->id,
                'name' => $this->student->name,
                'phone' => $this->student->phone,
                'username' => Phone::local($this->student->phone),
                // has logged in and replaced the temporary password
                'activated' => ! $this->student->must_change_password && $this->student->last_login_at !== null,
                'status' => $this->student->status->value,
            ]),
            'unread' => $this->when(isset($this->unread), fn () => $this->unread),
            'last_message' => $this->when(isset($this->last_message), fn () => $this->last_message),
        ];
    }
}
