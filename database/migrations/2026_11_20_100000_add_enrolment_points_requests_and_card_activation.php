<?php

use App\Services\PermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lavish Rewards by enrolment + points requests, and gift card activation.
 *
 * Rewards: clients no longer join by signing up. Reception / GM / CEO (rewards.enroll) enrols the clients who
 * want the program; only members earn. A member asks to use points in the app (shop discount, treatment
 * discount or something else). Reception handles it: treatment → takes it off the client's bill; shop → sends
 * it to e-commerce, who takes it off the order; other → marks it done with a note. Points are taken only when
 * the request is completed, and every ledger row keeps the balance after it.
 *
 * Gift cards: a card bought in the app waits for reception after it is paid (awaiting_activation). Reception
 * looks it up by barcode, checks it and activates it; the recipient then gets "your gift card is active, use it
 * from … to …" by email / SMS. Printed cards are active from the moment they are sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_loyalty', function (Blueprint $table) {
            $table->foreignId('enrolled_by')->nullable()->after('joined_at')->constrained('users')->nullOnDelete();
            $table->string('enrol_note')->nullable()->after('enrolled_by');
        });

        Schema::table('points_ledger', function (Blueprint $table) {
            $table->integer('balance_after')->nullable()->after('remaining');
        });

        Schema::create('points_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                          // shop | treatment | other
            $table->unsignedInteger('points');                   // asked for
            $table->unsignedInteger('value_etb');                // what those points were worth when asked
            $table->text('note')->nullable();                    // the client's words: "for my next Hydrafacial"
            $table->string('status', 12)->default('pending');    // pending | forwarded | completed | rejected | cancelled
            $table->foreignId('forwarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('forwarded_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete(); // the bill the points came off
            $table->unsignedInteger('points_used')->nullable();  // may be less than asked if the bill was smaller
            $table->unsignedInteger('discount_etb')->nullable();
            $table->string('staff_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'kind']);
            $table->index(['client_id', 'status']);
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable()->after('claimed_at');
            $table->foreignId('activated_by')->nullable()->after('activated_at')->constrained('users')->nullOnDelete();
        });
        // Cards that are already live count as activated when they were issued.
        DB::table('gift_cards')->whereIn('status', ['active', 'redeemed', 'expired'])->update(['activated_at' => DB::raw('created_at')]);

        // New permissions (rewards.enroll, rewards.requests, orders.points, giftcards.activate, giftcards.use) go to
        // the roles whose defaults include them.
        if (Schema::hasTable('roles')) {
            app(PermissionService::class)->syncCatalogue();
        }
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activated_by');
            $table->dropColumn('activated_at');
        });
        Schema::dropIfExists('points_requests');
        Schema::table('points_ledger', fn (Blueprint $t) => $t->dropColumn('balance_after'));
        Schema::table('client_loyalty', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrolled_by');
            $table->dropColumn('enrol_note');
        });
    }
};
