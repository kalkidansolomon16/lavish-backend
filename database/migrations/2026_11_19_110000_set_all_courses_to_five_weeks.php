<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every Lavish Academy course lasts 5 weeks (config lavish.course_weeks). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('courses')->update(['weeks' => 5]);
    }

    public function down(): void {}
};
