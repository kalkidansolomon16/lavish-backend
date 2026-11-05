<?php

namespace App\Http\Resources;

use App\Models\CourseModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CourseModule */
class CourseModuleResource extends JsonResource
{
    /** The signed-in student's submission for this module (set by the controller). */
    public mixed $submission = null;

    public function withSubmission(mixed $submission): static
    {
        $this->submission = $submission;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $student = $request->user()?->isStudent() ?? false;

        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'week' => $this->week,
            'title' => $this->title,
            'video' => $this->video_title ? ['title' => $this->video_title, 'url' => $this->video_url, 'minutes' => $this->video_minutes] : null,
            'exercise' => $this->exercise_title ? ['title' => $this->exercise_title, 'instructions' => $this->exercise_instructions, 'due_on' => $this->exercise_due_on?->toDateString()] : null,
            'published' => $this->published,
            'submission' => $this->when($student, fn () => $this->submission ? new SubmissionResource($this->submission) : null),
            'submissions_count' => $this->when(isset($this->submissions_count), fn () => $this->submissions_count),
        ];
    }
}
