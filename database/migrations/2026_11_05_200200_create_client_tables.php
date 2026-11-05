<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clients are the spa's patients. A client record can exist without an app account
 * (walk-ins and phone bookings made by reception). When that person later registers
 * in the mobile app with the same phone number, the account is linked to the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 20)->unique();
            $table->string('email')->nullable();
            $table->string('gender', 16)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->foreignId('preferred_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('primary_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->string('source', 16)->default('app');          // app | walk_in | phone_call | referral
            $table->text('notes')->nullable();                     // internal notes (allergies, preferences…)
            $table->timestamp('app_invited_at')->nullable();       // reception sent the app download link
            $table->timestamp('first_treated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Intake questionnaire recorded by reception (or edited later by staff).
        Schema::create('client_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 32)->nullable();               // Walk-in | Phone call | Referral
            $table->text('concern')->nullable();
            $table->string('skin_type', 32)->nullable();           // Normal | Dry | Oily | Combination | Sensitive
            $table->text('allergies')->nullable();
            $table->text('medications')->nullable();
            $table->text('conditions')->nullable();
            $table->text('previous_treatments')->nullable();
            $table->string('pregnant', 24)->nullable();            // No | Yes | Not applicable
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_histories');
        Schema::dropIfExists('clients');
    }
};
