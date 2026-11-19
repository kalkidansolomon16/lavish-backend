<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassSessionResource;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\SubmissionResource;
use App\Models\Attachment;
use App\Models\ClassSession;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\ExerciseSubmission;
use App\Services\AcademyService;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The student app: Home, My course, Lesson, Certificate. Chat goes through /conversations. */
class StudentController extends Controller
{
    public function __construct(private AcademyService $academy, private ChatService $chat) {}

    public function enrollments(Request $request): AnonymousResourceCollection
    {
        return EnrollmentResource::collection(
            $request->user()->enrollments()->with('course.teachers.user', 'course.branch', 'certificate.issuer', 'conversation')->latest()->get()
        );
    }

    /** My course: progress, published modules with my submissions, upcoming classes, teachers. */
    public function enrollment(Request $request, Enrollment $enrollment): JsonResponse
    {
        $this->own($request, $enrollment);
        $enrollment->load('course.teachers.user', 'course.branch', 'certificate.issuer', 'conversation');
        $modules = $enrollment->course->modules()->where('published', true)->get();
        // Lessons stay locked until the course fee is marked paid by the academy.
        $locked = $this->feeUnpaid($enrollment);
        $published = $modules->count();
        if ($locked) {
            $modules = collect();
        }
        $subs = ExerciseSubmission::query()->where('user_id', $request->user()->id)->whereIn('course_module_id', $modules->pluck('id'))->get()->keyBy('course_module_id');

        return response()->json(['data' => [
            'enrollment' => new EnrollmentResource($enrollment),
            'locked' => $locked,
            'locked_reason' => $locked ? 'Your lessons open once your course fee (ETB '.number_format($enrollment->fee).') is paid and confirmed by Lavish Academy.' : null,
            'modules_published' => $published,
            'modules' => $modules->map(fn ($m) => (new CourseModuleResource($m))->withSubmission($subs->get($m->id))->toArray($request)),
            'upcoming_sessions' => ClassSessionResource::collection(
                ClassSession::query()->with('teacher.user')->where('course_id', $enrollment->course_id)->where('ends_at', '>=', now())->orderBy('starts_at')->limit(10)->get()
            ),
        ]]);
    }

    public function module(Request $request, CourseModule $module): CourseModuleResource
    {
        $this->enrolledIn($request, $module);
        $submission = ExerciseSubmission::query()->with('attachments')->where('user_id', $request->user()->id)->where('course_module_id', $module->id)->first();

        return (new CourseModuleResource($module))->withSubmission($submission);
    }

    /** multipart/form-data: body (text answer) and/or files[] (up to 4, 10 MB each). */
    public function submit(Request $request, CourseModule $module): SubmissionResource
    {
        $this->enrolledIn($request, $module);
        $data = $request->validate([
            'body' => ['required_without:files', 'nullable', 'string', 'max:10000'],
            'files' => ['nullable', 'array', 'max:4'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx,mp4,mov'],
        ]);
        $submission = $this->academy->submitExercise($module, $request->user(), $data['body'] ?? null);
        foreach ($request->file('files', []) as $file) {
            Attachment::store($submission, $file, $request->user()->id, 'submissions/'.$submission->id);
        }

        return new SubmissionResource($submission->load('attachments'));
    }

    public function sessions(Request $request): AnonymousResourceCollection
    {
        $courseIds = $request->user()->enrollments()->where('status', 'active')->pluck('course_id');

        return ClassSessionResource::collection(
            ClassSession::query()->with('course', 'teacher.user')->whereIn('course_id', $courseIds)->where('ends_at', '>=', now())->orderBy('starts_at')->get()
        );
    }

    /** Certificate details for the in-app certificate screen. */
    public function certificate(Request $request, Enrollment $enrollment): JsonResponse
    {
        $this->own($request, $enrollment);
        $enrollment->load('certificate.issuer', 'course', 'student');
        abort_unless($enrollment->certificate, 404, 'No certificate yet.');

        return response()->json(['data' => [
            'number' => $enrollment->certificate->number,
            'student_name' => $enrollment->student->name,
            'course_title' => $enrollment->course->title,
            'weeks' => $enrollment->course->weeks,
            'issued_at' => $enrollment->certificate->issued_at->toIso8601String(),
            'issued_by' => $enrollment->certificate->issuer?->name,
        ]]);
    }

    private function own(Request $request, Enrollment $enrollment): void
    {
        abort_unless($enrollment->user_id === $request->user()->id, 404);
    }

    private function enrolledIn(Request $request, CourseModule $module): void
    {
        $enrollment = $request->user()->enrollments()->where('course_id', $module->course_id)->first();
        abort_unless($module->published && $enrollment, 404);
        if ($this->feeUnpaid($enrollment)) {
            throw \App\Exceptions\BusinessRuleException::make('fee_unpaid', 'This lesson opens once your course fee is paid and confirmed by Lavish Academy.', status: 403);
        }
    }

    /** A course with a fee that isn't marked paid yet. */
    private function feeUnpaid(Enrollment $enrollment): bool
    {
        return (int) $enrollment->fee > 0 && $enrollment->paid_at === null;
    }
}
