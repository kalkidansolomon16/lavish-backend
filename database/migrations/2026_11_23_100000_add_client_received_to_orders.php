<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery confirmation: when the order is out for delivery the client taps "I received my order" in the app
 * (received_at); only then can e-commerce confirm the delivery. If the client can't confirm in the app, staff
 * confirm with a reason (received_override, e.g. "Client confirmed by phone").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('delivered_at');
            $table->string('received_override')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['received_at', 'received_override']));
    }
};
