<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Appointment reminders to the client's phone: the evening before, and ~2 hours before. Each is sent once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('reminded_day_before_at')->nullable();
            $table->dateTime('reminded_soon_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['reminded_day_before_at', 'reminded_soon_at']));
    }
};
