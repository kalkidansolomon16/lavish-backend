<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments (in-clinic treatments and online video consultations), payments and doctor reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete(); // null = "let reception choose"
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); // null for online sessions
            $table->boolean('is_online')->default(false);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('duration_minutes');   // snapshot of the service at booking time
            $table->unsignedInteger('price');                   // snapshot, whole ETB
            $table->string('status', 16)->default('pending');   // pending | approved | declined | cancelled | completed
            $table->string('source', 16)->default('app');       // app | walk_in | phone_call | referral
            $table->text('client_note')->nullable();
            $table->string('video_link')->nullable();           // online sessions, created on confirmation
            $table->string('decline_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['doctor_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        // One payment row per thing a client owes for: an appointment or a shop order.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('payable');                  // App\Models\Appointment | App\Models\Order
            $table->string('description');
            $table->unsignedInteger('amount');                  // whole ETB
            $table->string('currency', 3)->default('ETB');
            $table->string('status', 16)->default('unpaid');    // unpaid | pending | paid | failed | refund_due | refunded | cancelled
            $table->string('method', 24)->nullable();           // cash | telebirr | cbe_birr | card | bank_transfer
            $table->string('provider', 16)->nullable();         // chapa | null (recorded at the desk)
            $table->string('tx_ref', 64)->nullable()->unique(); // our reference sent to Chapa
            $table->string('provider_reference')->nullable();   // Chapa's own reference
            $table->text('checkout_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'paid_at']);
        });

        Schema::create('doctor_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');              // 1..5
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_reviews');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('appointments');
    }
};
