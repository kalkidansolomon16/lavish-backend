<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Resources\ClassSessionResource;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\SubmissionResource;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\ExerciseSubmission;
use App\Services\AcademyService;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** The Lavish Academy portal for teacher doctors (academy.teach). Only courses they teach. */
class TeachingController extends StaffController
{
    public function __construct(private AcademyService $academy, private ChatService $chat) {}

    public function courses(Request $request): AnonymousResourceCollection
    {
        $doctor = $this->myDoctor($request);

        return CourseResource::collection(
            $doctor->courses()->with(['teachers.user', 'modules' => fn ($q) => $q->withCount('submissions'), 'branch'])->withCount('enrollments')->orderBy('title')->get()
        );
    }

    /** Lesson content. Body: video {title, url, minutes} | null, exercise {title, instructions, due_on} | null. */
    public function updateModule(Request $request, CourseModule $module): CourseModuleResource
    {
        $this->assertTeaches($request, $module->course);
        $data = $request->validate([
            'video' => ['nullable', 'array'],
            'video.title' => ['required_with:video', 'string', 'max:160'],
            'video.url' => ['nullable', 'url', 'max:500'],
            'video.minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'exercise' => ['nullable', 'array'],
            'exercise.title' => ['required_with:exercise', 'string', 'max:160'],
            'exercise.instructions' => ['nullable', 'string', 'max:4000'],
            'exercise.due_on' => ['nullable', 'date'],
        ]);
        $module->update([
            'video_title' => $data['video']['title'] ?? null,
            'video_url' => $data['video']['url'] ?? null,
            'video_minutes' => $data['video']['minutes'] ?? null,
            'exercise_title' => $data['exercise']['title'] ?? null,
            'exercise_instructions' => $data['exercise']['instructions'] ?? null,
            'exercise_due_on' => $data['exercise']['due_on'] ?? null,
        ]);

        return new CourseModuleResource($module);
    }

    public function publishModule(Request $request, CourseModule $module): CourseModuleResource
    {
        $this->assertTeaches($request, $module->course);
        $data = $request->validate(['published' => ['required', 'boolean']]);

        return new CourseModuleResource($this->academy->publishModule($module->load('course'), $data['published']));
    }

    /** Filters: from, to. Defaults to upcoming. */
    public function sessions(Request $request): AnonymousResourceCollection
    {
        $doctor = $this->myDoctor($request);

        return ClassSessionResource::collection(
            ClassSession::query()->with('course', 'teacher.user')->where('teacher_id', $doctor->id)
                ->where('starts_at', '>=', $request->date('from') ?? now()->startOfDay())
                ->when($request->date('to'), fn ($q, $d) => $q->where('starts_at', '<=', $d->endOfDay()))
                ->orderBy('starts_at')->get()
        );
    }

    /** 422 teacher_busy (details.clashes) when it overlaps your treatments or classes. */
    public function storeSession(Request $request): ClassSessionResource
    {
        $data = $request->validate([
            'course_id' => ['required', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', Rule::in(['Practical', 'Theory', 'Exam'])],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'room' => ['nullable', 'string', 'max:160'],
        ]);

        return new ClassSessionResource($this->academy->addSession($this->myDoctor($request), $data)->load('course', 'teacher.user'));
    }

    public function destroySession(Request $request, ClassSession $session): JsonResponse
    {
        abort_unless($session->teacher_id === $this->myDoctor($request)->id, 404);
        $session->delete();

        return response()->json(['message' => 'Class removed.']);
    }

    /** My students across my courses, with unread chat counts. Filter: course_id. */
    public function students(Request $request): AnonymousResourceCollection
    {
        $doctor = $this->myDoctor($request);
        $courseIds = $doctor->courses()->pluck('courses.id');

        $enrollments = Enrollment::query()->with('student', 'course', 'certificate', 'conversation.latestMessage', 'conversation.participants')
            ->whereIn('course_id', $courseIds)
            ->when($request->integer('course_id'), fn ($q, $c) => $q->where('course_id', $c))
            ->latest()->get()
            ->each(function (Enrollment $e) use ($request) {
                $c = $e->conversation;
                $e->unread = $c ? $this->chat->unreadCount($c, $request->user()) : 0;
                $e->last_message = $c?->latestMessage ? ['body' => $c->latestMessage->body, 'from_student' => $c->latestMessage->user_id === $e->user_id, 'created_at' => $c->latestMessage->created_at->toIso8601String()] : null;
            });

        return EnrollmentResource::collection($enrollments);
    }

    public function setProgress(Request $request, Enrollment $enrollment): EnrollmentResource
    {
        $this->assertTeaches($request, $enrollment->course);
        $data = $request->validate(['progress' => ['required', 'integer', 'between:0,100']]);

        return new EnrollmentResource($this->academy->setProgress($enrollment->load('student', 'course'), $data['progress'])->load('student', 'course', 'certificate'));
    }

    /** Submissions to grade. Filters: status=submitted|graded|returned, course_id, enrollment_id. */
    public function submissions(Request $request): AnonymousResourceCollection
    {
        $courseIds = $this->myDoctor($request)->courses()->pluck('courses.id');

        return SubmissionResource::collection(
            ExerciseSubmission::query()->with('module', 'student', 'attachments')
                ->whereHas('module', fn ($m) => $m->whereIn('course_id', $request->integer('course_id') ? [$request->integer('course_id')] : $courseIds))
                ->when($request->integer('enrollment_id'), function ($q, $id) {
                    $e = Enrollment::findOrFail($id);
                    $q->where('user_id', $e->user_id)->whereHas('module', fn ($m) => $m->where('course_id', $e->course_id));
                })
                ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s))
                ->latest('submitted_at')->get()
        );
    }

    /** Body: status=graded|returned, grade ("18/20", "Pass"), feedback. */
    public function grade(Request $request, ExerciseSubmission $submission): SubmissionResource
    {
        $this->assertTeaches($request, $submission->module->course);
        $data = $request->validate([
            'status' => ['required', Rule::in(['graded', 'returned'])],
            'grade' => ['nullable', 'string', 'max:32'],
            'feedback' => ['nullable', 'string', 'max:4000'],
        ]);

        return new SubmissionResource($this->academy->grade($submission->load('student', 'module'), $request->user(), $data['status'], $data['grade'] ?? null, $data['feedback'] ?? null));
    }

    private function assertTeaches(Request $request, Course $course): void
    {
        abort_unless($course->isTaughtBy($this->myDoctor($request)), 403, 'You don’t teach this course.');
    }
}
