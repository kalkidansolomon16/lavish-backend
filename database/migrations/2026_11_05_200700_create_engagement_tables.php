<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complaints, news, file attachments, OTP codes, push device tokens and the outbound SMS / email log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('category', 24);                 // Treatment | Appointment | Order | Staff | App | Other
            $table->string('subject');
            $table->text('message');
            $table->string('priority', 8)->default('medium'); // low | medium | high
            $table->string('status', 16)->default('open');    // open | in_review | resolved
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedTinyInteger('feedback_rating')->nullable(); // 1..5, asked after it's resolved
            $table->text('feedback_comment')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('complaint_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_staff');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('tag', 32);                      // New treatment | Offer | Academy | Update
            $table->string('image_path')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Photos on complaints, files on chat messages and exercise submissions.
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('disk', 16)->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('purpose', 24);                  // register | reset_password
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'purpose']);
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('platform', 16);                 // android | ios | web
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['token']);
        });

        // Every SMS and email the system sends, for the "emails sent" panel on orders and for support.
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 8);                   // sms | email
            $table->string('to');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->nullableMorphs('related');              // order, appointment, enrolment…
            $table->string('status', 16)->default('sent');  // sent | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('news');
        Schema::dropIfExists('complaint_messages');
        Schema::dropIfExists('complaints');
    }
};
