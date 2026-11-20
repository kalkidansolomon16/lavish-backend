<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courses are no longer fixed at 5 weeks: each course has its own length (weeks). When a course session ends the
 * CEO / General Manager closes it (closed_at): no new enrolments, students are marked completed (certificates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('active');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });
    }
};
