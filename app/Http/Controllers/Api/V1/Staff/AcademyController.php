<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AcademyService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Academy management (CEO / General Manager):
 *   academy.paths         learning paths, modules, teacher access
 *   academy.students      enrol students, create & reset their app logins
 *   academy.certificates  issue certificates
 */
class AcademyController extends StaffController
{
    public function __construct(private AcademyService $academy) {}

    public function courses(): AnonymousResourceCollection
    {
        return CourseResource::collection(
            Course::query()->with('teachers.user', 'modules', 'branch')->withCount('enrollments')->orderBy('title')->get()
        );
    }

    public function storeCourse(Request $request): CourseResource
    {
        $data = $request->validate($this->courseRules(true));
        $course = Course::create([...$data, 'slug' => Str::slug($data['title']).'-'.Str::lower(Str::random(4))]);

        return new CourseResource($course->load('teachers.user', 'modules', 'branch'));
    }

    public function updateCourse(Request $request, Course $course): CourseResource
    {
        $course->update($request->validate($this->courseRules(false)));

        return new CourseResource($course->load('teachers.user', 'modules', 'branch'));
    }

    /** Body: doctor_ids[] — the doctors who teach this course (they get the Lavish Academy portal). */
    public function setTeachers(Request $request, Course $course): CourseResource
    {
        $data = $request->validate(['doctor_ids' => ['present', 'array'], 'doctor_ids.*' => ['integer', 'exists:doctors,id']]);
        $this->academy->setTeachers($course, $data['doctor_ids']);

        return new CourseResource($course->load('teachers.user', 'modules', 'branch'));
    }

    public function addModule(Request $request, Course $course): CourseModuleResource
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:160']]);

        return new CourseModuleResource($this->academy->addModule($course, $data['title']));
    }

    /** Path owners can rename / re-order modules (teachers edit the content). */
    public function updateModule(Request $request, CourseModule $module): CourseModuleResource
    {
        $module->update($request->validate(['title' => ['sometimes', 'string', 'max:160'], 'week' => ['sometimes', 'integer', 'min:1', 'max:'.config('lavish.course_weeks')]]));

        return new CourseModuleResource($module);
    }

    public function destroyModule(CourseModule $module): JsonResponse
    {
        $module->delete();

        return response()->json(['message' => 'Module removed.']);
    }

    /** Students list. Filters: course_id, status, q. */
    public function enrollments(Request $request): AnonymousResourceCollection
    {
        return EnrollmentResource::collection(
            Enrollment::query()->with('student', 'course', 'certificate.issuer')
                ->when($request->integer('course_id'), fn ($q, $c) => $q->where('course_id', $c))
                ->when($request->string('status')->value(), fn ($q, $s) => $q->where('status', $s))
                ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->whereHas('student', fn ($s) => $s->where('name', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
                ->latest()->paginate($this->perPage($request, 50))
        );
    }

    /**
     * Enrol a student. Creates their app account if the phone is new and returns the
     * one-time password ONCE (it is not stored in readable form). send_sms=true texts it to them.
     */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'course_id' => ['required', 'exists:courses,id'],
            'via' => ['required', Rule::in(['in_person', 'phone_call'])],
            'fee' => ['nullable', 'integer', 'min:0'],
            'paid' => ['boolean'],
            'send_sms' => ['boolean'],
        ]);
        if (! Phone::normalize($data['phone'])) {
            return response()->json(['message' => 'Enter a valid phone number.', 'errors' => ['phone' => ['Enter a valid phone number.']]], 422);
        }
        $result = $this->academy->enroll($data, $request->user());

        return response()->json(['data' => [
            'enrollment' => new EnrollmentResource($result['enrollment']->load('student', 'course')),
            'credentials' => ['name' => $result['student']->name, 'username' => $result['username'], 'password' => $result['password']],
        ]], 201);
    }

    /** New temporary password for a student who forgot theirs. Returned once. */
    public function resetStudentPassword(Request $request, User $student): JsonResponse
    {
        $data = $request->validate(['send_sms' => ['boolean']]);
        $r = $this->academy->resetPassword($student, $data['send_sms'] ?? false);

        return response()->json(['data' => ['credentials' => ['name' => $r['student']->name, 'username' => $r['username'], 'password' => $r['password']]]]);
    }

    public function markPaid(Enrollment $enrollment): EnrollmentResource
    {
        $enrollment->update(['paid_at' => $enrollment->paid_at ?? now()]);

        return new EnrollmentResource($enrollment->load('student', 'course', 'certificate.issuer'));
    }

    public function issueCertificate(Request $request, Enrollment $enrollment): EnrollmentResource
    {
        $this->academy->issueCertificate($enrollment->load('certificate', 'student', 'course'), $request->user());

        return new EnrollmentResource($enrollment->load('student', 'course', 'certificate.issuer'));
    }

    private function courseRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:160'],
            'about' => ['nullable', 'string', 'max:4000'],
            'level' => [$req, Rule::in(['Beginner', 'Intermediate', 'Advanced'])],
            'price' => [$req, 'integer', 'min:0'],
            'mode' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'starts_on' => ['nullable', 'date'],
            'requirement' => ['nullable', 'string', 'max:255'],
            'hue' => ['nullable', 'integer', 'between:0,360'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
