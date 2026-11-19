<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gift_cards.status was 16 characters; "awaiting_activation" is 19, so on MySQL accepting the receipt for an app
 * gift card saved the payment as paid but couldn't move the card on (it stayed "pending_payment").
 * Widen the column, then repair those cards: paid app cards still marked pending_payment → awaiting_activation
 * (they appear under Gift cards → To activate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('status', 24)->default('active')->change();
        });

        $paid = DB::table('payments')->where('payable_type', 'gift_card')->where('status', 'paid')->pluck('payable_id');
        DB::table('gift_cards')->whereIn('id', $paid)->where('status', 'pending_payment')->where('source', 'app')
            ->update(['status' => 'awaiting_activation', 'updated_at' => now()]);
    }

    public function down(): void {}
};
