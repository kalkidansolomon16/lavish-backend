<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_ceo_enrols_a_student_who_then_logs_in(): void
    {
        $course = Course::where('title', 'Medical Aesthetics Foundations')->first();
        $this->as(self::CEO);
        $creds = $this->postJson('/api/v1/staff/academy/enrollments', [
            'name' => 'Meklit Bogale', 'phone' => '0918 00 00 01', 'course_id' => $course->id, 'via' => 'in_person', 'send_sms' => true,
        ])->assertCreated()->assertJsonPath('data.credentials.username', '0918000001')->json('data.credentials');

        $this->postJson('/api/v1/staff/academy/enrollments', ['name' => 'Meklit Bogale', 'phone' => '0918000001', 'course_id' => $course->id, 'via' => 'in_person'])
            ->assertStatus(422)->assertJsonPath('code', 'already_enrolled');
        $this->assertDatabaseMissing('outbound_messages', ['body' => 'Lavish Academy: Hi Meklit, your student app login — username 0918000001 · temporary password '.$creds['password']]);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['login' => $creds['username'], 'password' => $creds['password'], 'app' => 'mobile'])
            ->assertOk()->assertJsonPath('user.type', 'student')->assertJsonPath('user.must_change_password', true);

        // The student's chat includes the course teacher.
        $e = Enrollment::query()->whereHas('student', fn ($s) => $s->where('phone', '+251918000001'))->first();
        $this->assertTrue($e->conversation->hasParticipant(User::where('phone', self::DR_MERON)->first()));
    }

    public function test_teacher_cannot_schedule_a_class_over_a_treatment(): void
    {
        $meron = User::where('phone', self::DR_MERON)->first()->doctor;
        $treatment = Appointment::query()->where('doctor_id', $meron->id)->where('status', 'approved')->where('starts_at', '>', now())->first();
        $course = $meron->courses()->first();

        $this->as(self::DR_MERON);
        $this->postJson('/api/v1/staff/teaching/sessions', [
            'course_id' => $course->id, 'title' => 'Peels — theory', 'kind' => 'Theory',
            'date' => $treatment->starts_at->toDateString(), 'time' => $treatment->starts_at->format('H:i'), 'room' => 'Room 2',
        ])->assertStatus(422)->assertJsonPath('code', 'teacher_busy');

        $this->postJson('/api/v1/staff/teaching/sessions', [
            'course_id' => $course->id, 'title' => 'Peels — theory', 'kind' => 'Theory', 'date' => now()->addDays(10)->toDateString(), 'time' => '09:00',
        ])->assertCreated();

        // and not for a course they don't teach
        $other = Course::whereDoesntHave('teachers', fn ($t) => $t->whereKey($meron->id))->first();
        $this->postJson('/api/v1/staff/teaching/sessions', ['course_id' => $other->id, 'title' => 'X', 'kind' => 'Theory', 'date' => now()->addDays(11)->toDateString(), 'time' => '09:00'])
            ->assertForbidden();
    }

    public function test_lesson_submission_grading_and_certificate(): void
    {
        $eden = User::where('phone', self::EDEN)->first();
        $enrollment = $eden->enrollments()->whereHas('course', fn ($c) => $c->where('title', 'Medical Aesthetics Foundations'))->first();
        $week5 = $enrollment->course->modules()->where('week', 5)->first();

        $this->as(self::EDEN);
        $sub = $this->postJson("/api/v1/student/modules/{$week5->id}/submission", ['body' => 'My 500-word case study…'])
            ->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');
        $unpublished = $enrollment->course->modules()->where('id', '!=', $week5->id)->orderByDesc('week')->first();
        $unpublished->update(['published' => false]);
        $this->getJson("/api/v1/student/modules/{$unpublished->id}")->assertNotFound();

        $this->as(self::DR_MERON);
        $this->postJson("/api/v1/staff/teaching/submissions/{$sub}/grade", ['status' => 'graded', 'grade' => '17/20', 'feedback' => 'Great work'])->assertOk();
        $this->assertSame('academy.graded', $eden->notifications()->first()->data['type']);

        $this->as(self::GM);
        $this->postJson("/api/v1/staff/academy/enrollments/{$enrollment->id}/certificate")->assertStatus(422)->assertJsonPath('code', 'not_completed');

        $this->as(self::DR_MERON);
        $this->putJson("/api/v1/staff/teaching/enrollments/{$enrollment->id}/progress", ['progress' => 100])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->as(self::GM);
        $this->postJson("/api/v1/staff/academy/enrollments/{$enrollment->id}/certificate")->assertOk()
            ->assertJsonPath('data.certificate.number', 'LA-'.now()->year.'-0002');
    }
}
