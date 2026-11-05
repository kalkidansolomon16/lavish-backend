<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches, staff roles & permissions, staff profiles, doctors and the treatments (services) they give.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // Addis Ababa, Adama, Dire Dawa
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_main')->default(false); // stock is received / sold from here first
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // The web portals: ceo, gm, reception, ecommerce, doctor, teacher, team.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('label');
            $table->unsignedTinyInteger('level');      // 1 = top of the hierarchy; you can only manage roles with a higher number
            $table->boolean('team_only')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();       // e.g. appointments.approve
            $table->string('label');
            $table->string('group');
            $table->json('only_roles')->nullable();    // permission only makes sense for these role keys
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        // A person can hold several portals, e.g. a doctor who also teaches at the academy.
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('position')->nullable();    // Front Desk, Nurse, Skin Therapist…
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('specialty');
            $table->text('bio')->nullable();
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->string('working_days')->nullable();                  // "Mon – Sat"
            $table->string('availability', 24)->default('available');    // available | in_surgery | off_today | on_leave
            $table->string('access', 16)->default('pending')->index();   // pending | active | banned
            $table->string('photo_path')->nullable();
            $table->unsignedSmallInteger('hue')->nullable();             // avatar colour in the app
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('price');                          // whole ETB
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('icon')->nullable();                        // Material Symbols name used by the app
            $table->boolean('is_online')->default(false);              // the 45-min online video consultation
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // The treatments a doctor is skilled in.
        Schema::create('doctor_service', function (Blueprint $table) {
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['doctor_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_service');
        Schema::dropIfExists('services');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
    }
};
