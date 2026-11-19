<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reception can ask a client to change a pending booking when the chosen doctor is busy:
 * change_request = { kind: time|doctor|either, message, times: [{date, time}], doctors: [{id, name}], by }.
 * The client picks a new time / doctor in the app (or cancels); the request is then cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->json('change_request')->nullable()->after('decline_reason');
            $table->timestamp('change_requested_at')->nullable()->after('change_request');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['change_request', 'change_requested_at']));
    }
};
