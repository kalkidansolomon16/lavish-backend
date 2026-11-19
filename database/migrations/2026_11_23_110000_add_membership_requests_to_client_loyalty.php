<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client can ask to join Lavish Rewards from the app (requested_at + note). Reception (rewards.enroll) enrols them
 * — which clears the request — or declines it with a reason the client sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_loyalty', function (Blueprint $table) {
            $table->timestamp('requested_at')->nullable()->after('enrol_note');
            $table->string('request_note')->nullable()->after('requested_at');
            $table->string('declined_reason')->nullable()->after('request_note');
            $table->timestamp('declined_at')->nullable()->after('declined_reason');
        });
    }

    public function down(): void
    {
        Schema::table('client_loyalty', fn (Blueprint $t) => $t->dropColumn(['requested_at', 'request_note', 'declined_reason', 'declined_at']));
    }
};
