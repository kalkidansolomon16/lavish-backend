<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printed (hard-copy) gift cards with barcodes. A batch of card numbers is generated (or imported from the
 * printing company) as status `in_stock` with no value; a card gets its value and becomes active only when it
 * is sold at the front desk (scan the barcode → amount → payment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('batch', 40)->nullable()->after('source');
            $table->boolean('physical')->default(false)->after('batch');
            $table->index(['status', 'batch']);
        });
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropIndex(['status', 'batch']);
            $table->dropColumn(['batch', 'physical']);
        });
    }
};
