<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lavish Academy: courses (learning paths) with weekly modules, teacher doctors,
 * class sessions, student enrolments, exercise submissions and certificates.
 * Students are users with type = student; their accounts are created by staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('about')->nullable();
            $table->string('level', 16);                  // Beginner | Intermediate | Advanced
            $table->unsignedTinyInteger('weeks');
            $table->unsignedInteger('price');             // whole ETB
            $table->string('mode')->nullable();           // "In person · Addis Ababa"
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on')->nullable();        // next intake
            $table->string('requirement')->nullable();    // "For licensed doctors and nurses only"
            $table->unsignedSmallInteger('hue')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('course_teacher', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->primary(['course_id', 'doctor_id']);
        });

        Schema::create('course_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week');
            $table->string('title');
            $table->string('video_title')->nullable();
            $table->string('video_url')->nullable();
            $table->unsignedSmallInteger('video_minutes')->nullable();
            $table->string('exercise_title')->nullable();
            $table->text('exercise_instructions')->nullable();
            $table->date('exercise_due_on')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
            $table->index(['course_id', 'week']);
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 16);                   // Practical | Theory | Exam
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('room')->nullable();           // room or online link
            $table->timestamps();
            $table->index(['teacher_id', 'starts_at']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // the student
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active');                 // active | completed | withdrawn
            $table->unsignedTinyInteger('progress')->default(0);             // 0..100
            $table->string('enrolled_via', 16)->default('in_person');        // in_person | phone_call
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('fee')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();       // LA-2026-0001
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('exercise_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();  // the student
            $table->text('body')->nullable();
            $table->string('status', 16)->default('submitted');              // submitted | graded | returned
            $table->string('grade', 32)->nullable();                         // "18/20", "Pass"
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['course_module_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_submissions');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('course_teacher');
        Schema::dropIfExists('courses');
    }
};
