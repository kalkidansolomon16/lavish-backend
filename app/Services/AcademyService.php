<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\SubmissionStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Models\Certificate;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Doctor;
use App\Models\Enrollment;
use App\Models\ExerciseSubmission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LavishNotification;
use App\Support\Phone;
use App\Support\TempPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Lavish Academy. Students don't sign up in the app: staff create their account when they
 * enrol (in person or by phone) and hand over a temporary password, which the student
 * replaces at first login.
 */
class AcademyService
{
    public function __construct(
        private ChatService $chat,
        private Notifier $notifier,
        private Messenger $messenger,
        private ScheduleService $schedule,
    ) {}

    /**
     * @return array{student: User, enrollment: Enrollment, username: string, password: ?string}
     *                                                                                           password is null when an existing student was enrolled in another course
     */
    public function enroll(array $data, User $by): array
    {
        $phone = Phone::normalize($data['phone']);
        $course = Course::query()->findOrFail($data['course_id']);

        $result = DB::transaction(function () use ($data, $by, $phone, $course) {
            $student = User::query()->where('phone', $phone)->first();
            $password = null;
            if ($student && ! $student->isStudent()) {
                throw BusinessRuleException::make('phone_in_use', 'This phone number belongs to a staff or client account.');
            }
            if (! $student) {
                $password = TempPassword::make();
                $student = User::create([
                    'name' => $data['name'],
                    'phone' => $phone,
                    'email' => $data['email'] ?? null,
                    'type' => UserType::Student,
                    'status' => UserStatus::Active,
                    'password' => $password,
                    'must_change_password' => true,
                    'phone_verified_at' => now(),
                ]);
            }
            if ($student->enrollments()->where('course_id', $course->id)->exists()) {
                throw BusinessRuleException::make('already_enrolled', "{$student->name} is already enrolled in {$course->title}.");
            }
            $enrollment = $student->enrollments()->create([
                'course_id' => $course->id,
                'status' => EnrollmentStatus::Active,
                'enrolled_via' => $data['via'] ?? 'in_person',
                'enrolled_by' => $by->id,
                'fee' => $data['fee'] ?? $course->price,
                'paid_at' => ! empty($data['paid']) ? now() : null,
            ]);
            $this->chat->studentTeacher($enrollment->load('course'));

            return compact('student', 'enrollment', 'password');
        });

        $username = Phone::local($phone);
        if (! empty($data['send_sms'])) {
            $this->smsCredentials($result['student'], $result['password'], $course);
        }

        return [...$result, 'username' => $username];
    }

    /** The student forgot their password and called the academy: issue a new temporary one. */
    public function resetPassword(User $student, bool $sendSms): array
    {
        if (! $student->isStudent()) {
            throw BusinessRuleException::make('not_a_student', 'Only student passwords can be reset here.');
        }
        $password = TempPassword::make();
        $student->update(['password' => $password, 'must_change_password' => true]);
        $student->tokens()->delete();
        if ($sendSms) {
            $this->smsCredentials($student, $password);
        }

        return ['student' => $student, 'username' => Phone::local($student->phone), 'password' => $password];
    }

    private function smsCredentials(User $student, ?string $password, ?Course $course = null): void
    {
        $first = strtok($student->name, ' ');
        $this->messenger->sms($student->phone, $password
            ? "Lavish Academy: Hi {$first}, your student app login — username ".Phone::local($student->phone)." · temporary password {$password}. You’ll choose your own password at first login. ".config('lavish.app_download_url')
            : "Lavish Academy: Hi {$first}, you’re enrolled in {$course?->title}. Log in to the Lavish app with your usual student account.", $student, $password ? 'Student login details [temporary password hidden]' : null);
    }

    /** Learning-path teacher access. Keeps the "teacher" portal role in step with who teaches. */
    public function setTeachers(Course $course, array $doctorIds): void
    {
        DB::transaction(function () use ($course, $doctorIds) {
            $before = $course->teachers()->pluck('doctors.id')->all();
            $course->teachers()->sync($doctorIds);
            $role = Role::query()->where('key', 'teacher')->firstOrFail();

            foreach (Doctor::query()->with('user.roles')->whereIn('id', array_unique([...$before, ...$doctorIds]))->get() as $doctor) {
                $teaches = $doctor->courses()->exists();
                $teaches ? $doctor->user->roles()->syncWithoutDetaching([$role->id]) : $doctor->user->roles()->detach($role->id);
            }
            foreach ($course->enrollments()->with('course')->get() as $enrollment) {
                $this->chat->studentTeacher($enrollment);
            }
        });
    }

    public function addModule(Course $course, string $title): CourseModule
    {
        $week = (int) $course->modules()->max('week') + 1;
        $max = (int) config('lavish.course_weeks');
        if ($week > $max) {
            throw BusinessRuleException::make('course_full', "Courses are {$max} weeks long — this course already has a module for every week.");
        }

        return $course->modules()->create(['week' => $week, 'title' => $title, 'published' => false]);
    }

    public function publishModule(CourseModule $module, bool $published): CourseModule
    {
        $module->update(['published' => $published]);
        if ($published) {
            $this->notifyStudents($module->course, new LavishNotification('academy.lesson', 'New lesson available',
                "Week {$module->week}: {$module->title} is now open in {$module->course->title}.", 'school', ['screen' => 'lesson', 'id' => $module->id]));
        }

        return $module;
    }

    /** A teacher schedules a class. Refused when it overlaps their treatments or other classes. */
    public function addSession(Doctor $teacher, array $data): ClassSession
    {
        $course = Course::query()->findOrFail($data['course_id']);
        if (! $course->isTaughtBy($teacher)) {
            throw BusinessRuleException::make('not_your_course', 'You don’t teach this course.', status: 403);
        }
        $start = ScheduleService::at($data['date'], $data['time']);
        $end = $start->addMinutes((int) ($data['duration_minutes'] ?? config('lavish.booking.class_minutes')));
        $clashes = $this->schedule->clashes($teacher, $start, $end, includePending: false);
        if ($clashes) {
            throw BusinessRuleException::make('teacher_busy', 'You already have something booked at that time.', ['clashes' => $clashes]);
        }

        $session = ClassSession::create([
            'course_id' => $course->id, 'teacher_id' => $teacher->id, 'title' => $data['title'],
            'kind' => $data['kind'], 'starts_at' => $start, 'ends_at' => $end, 'room' => $data['room'] ?? null,
        ]);
        $this->notifyStudents($course, new LavishNotification('academy.session', 'New class scheduled',
            "{$session->title} — {$start->format('D j M, H:i')}".($session->room ? " · {$session->room}" : '').'.', 'calendar_month', ['screen' => 'my-course', 'id' => $course->id]));

        return $session;
    }

    public function setProgress(Enrollment $enrollment, int $progress): Enrollment
    {
        $progress = max(0, min(100, $progress));
        $completedNow = $progress >= 100 && $enrollment->status !== EnrollmentStatus::Completed;
        $enrollment->update([
            'progress' => $progress,
            'status' => $progress >= 100 ? EnrollmentStatus::Completed : ($enrollment->status === EnrollmentStatus::Completed ? EnrollmentStatus::Active : $enrollment->status),
            'completed_at' => $progress >= 100 ? ($enrollment->completed_at ?? now()) : null,
        ]);
        if ($completedNow) {
            $this->notifier->staff('academy.certificates', new LavishNotification('academy.completed', 'Certificate ready to issue',
                "{$enrollment->student->name} completed {$enrollment->course->title}.", 'workspace_premium', null, '/academy?tab=certificates'));
        }

        return $enrollment;
    }

    public function issueCertificate(Enrollment $enrollment, User $by): Certificate
    {
        if ($enrollment->status !== EnrollmentStatus::Completed) {
            throw BusinessRuleException::make('not_completed', 'The student hasn’t completed this course yet.');
        }
        if ($enrollment->certificate) {
            throw BusinessRuleException::make('already_issued', 'A certificate was already issued.');
        }

        $certificate = DB::transaction(function () use ($enrollment, $by) {
            $year = now()->year;
            $seq = Certificate::query()->where('number', 'like', "LA-{$year}-%")->lockForUpdate()->count() + 1;

            return $enrollment->certificate()->create([
                'number' => sprintf('LA-%d-%04d', $year, $seq),
                'issued_at' => now(),
                'issued_by' => $by->id,
            ]);
        });
        $this->notifier->user($enrollment->student, new LavishNotification('academy.certificate', 'Your certificate is ready',
            "Congratulations! Your {$enrollment->course->title} certificate ({$certificate->number}) is in the app.", 'workspace_premium', ['screen' => 'certificate', 'id' => $enrollment->id]));

        return $certificate;
    }

    public function submitExercise(CourseModule $module, User $student, ?string $body): ExerciseSubmission
    {
        $enrollment = $student->enrollments()->where('course_id', $module->course_id)->first();
        if (! $enrollment || ! $module->published || ! $module->hasExercise()) {
            throw BusinessRuleException::make('exercise_unavailable', 'This exercise isn’t open to you.', status: 403);
        }
        $existing = ExerciseSubmission::query()->where('course_module_id', $module->id)->where('user_id', $student->id)->first();
        if ($existing?->status === SubmissionStatus::Graded) {
            throw BusinessRuleException::make('already_graded', 'This exercise was already graded.');
        }

        $submission = ExerciseSubmission::updateOrCreate(
            ['course_module_id' => $module->id, 'user_id' => $student->id],
            ['body' => $body, 'status' => SubmissionStatus::Submitted, 'submitted_at' => now(), 'grade' => null, 'feedback' => null, 'graded_at' => null, 'graded_by' => null],
        );
        $teachers = $module->course->teachers()->with('user')->get()->pluck('user')->filter();
        Notification::send($teachers, new LavishNotification('academy.submission', 'Exercise submitted',
            "{$student->name} submitted “{$module->exercise_title}”.", 'assignment', null, '/students?enrollment='.$enrollment->id));

        return $submission;
    }

    public function grade(ExerciseSubmission $submission, User $by, string $status, ?string $grade, ?string $feedback): ExerciseSubmission
    {
        $submission->update([
            'status' => SubmissionStatus::from($status),
            'grade' => $grade,
            'feedback' => $feedback,
            'graded_by' => $by->id,
            'graded_at' => now(),
        ]);
        $this->notifier->user($submission->student, new LavishNotification('academy.graded',
            $status === 'graded' ? 'Exercise graded' : 'Exercise returned',
            "“{$submission->module->exercise_title}”".($grade ? ": {$grade}" : '').($feedback ? " — {$feedback}" : ''), 'grading', ['screen' => 'lesson', 'id' => $submission->course_module_id]));

        return $submission;
    }

    private function notifyStudents(Course $course, LavishNotification $n): void
    {
        $students = User::query()->whereHas('enrollments', fn ($e) => $e->where('course_id', $course->id)->where('status', EnrollmentStatus::Active))->get();
        Notification::send($students, $n);
    }
}
