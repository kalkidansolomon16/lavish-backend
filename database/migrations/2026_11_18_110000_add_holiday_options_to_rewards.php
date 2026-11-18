<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holiday options:
 *  - rewards can be limited to a date window (e.g. a Genna reward shown 1–10 January only)
 *  - a campaign can send members an app notice when it starts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->dateTime('available_from')->nullable()->after('stock');
            $table->dateTime('available_until')->nullable()->after('available_from');
        });
        Schema::table('loyalty_campaigns', function (Blueprint $table) {
            $table->boolean('notify_members')->default(false)->after('active');
            $table->string('notice_title', 120)->nullable()->after('notify_members');
            $table->text('notice_body')->nullable()->after('notice_title');
            $table->dateTime('notified_at')->nullable()->after('notice_body');
            $table->unsignedInteger('notified_count')->default(0)->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('rewards', fn (Blueprint $t) => $t->dropColumn(['available_from', 'available_until']));
        Schema::table('loyalty_campaigns', fn (Blueprint $t) => $t->dropColumn(['notify_members', 'notice_title', 'notice_body', 'notified_at', 'notified_count']));
    }
};
