<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medicine reminders: the doctor sets when to take each prescribed item (e.g. 08:00 and 20:00) and for how
 * many days. The client's app turns this into phone reminders until the last day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->json('reminder_times')->nullable()->after('instructions');   // ["08:00","20:00"]
            $table->date('starts_on')->nullable()->after('reminder_times');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', fn (Blueprint $t) => $t->dropColumn(['reminder_times', 'starts_on', 'duration_days']));
    }
};
