<?php

namespace App\Http\Resources;

use App\Models\ExerciseSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExerciseSubmission */
class SubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_id' => $this->course_module_id,
            'module' => $this->whenLoaded('module', fn () => ['id' => $this->module->id, 'week' => $this->module->week, 'title' => $this->module->title, 'exercise_title' => $this->module->exercise_title]),
            'student' => $this->whenLoaded('student', fn () => ['id' => $this->student->id, 'name' => $this->student->name]),
            'body' => $this->body,
            'status' => $this->status->value,
            'grade' => $this->grade,
            'feedback' => $this->feedback,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'graded_at' => $this->graded_at?->toIso8601String(),
        ];
    }
}
