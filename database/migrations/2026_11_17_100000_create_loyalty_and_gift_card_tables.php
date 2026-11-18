<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lavish Rewards (loyalty points) and Gift Cards.
 *
 * Both run on ledgers: every change to a points balance or a gift card balance is a row, so
 * expiry, refunds and disputes stay traceable. Balances are cached on client_loyalty / gift_cards
 * and kept in sync by LoyaltyService / GiftCardService inside the same database transaction.
 *
 * All money is whole ETB. Every numeric rule (points per ETB, tiers, catalog costs, expiry) is a
 * setting the manager edits in the web app — the seeded values are placeholders until Lavish decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------ Lavish Rewards

        // Program-wide numbers (one row). Edited in Web → Rewards → Settings.
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('point_value_etb', 8, 2)->default(0.5);           // 1 point is worth this much off when spent at checkout
            $table->unsignedSmallInteger('points_expiry_months')->default(12); // points expire this long after they are earned (Elite: never)
            $table->unsignedSmallInteger('expiry_reminder_days')->default(14);
            $table->unsignedSmallInteger('voucher_valid_days')->default(90);   // a redeemed reward must be used within this many days
            $table->unsignedInteger('max_points_per_visit')->default(5000);
            $table->unsignedInteger('max_points_per_day')->default(8000);
            $table->unsignedSmallInteger('max_referrals_per_year')->default(10);
            $table->unsignedSmallInteger('gift_card_expiry_months')->default(24);
            $table->unsignedSmallInteger('promo_card_expiry_months')->default(3);
            $table->unsignedTinyInteger('gift_card_low_balance_percent')->default(20);
            $table->json('gift_card_amounts')->nullable();                     // preset amounts offered in the app
            $table->timestamps();
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('key', 24)->unique();                // glow | radiance | elite
            $table->unsignedInteger('min_points_12m')->default(0);
            $table->decimal('earn_multiplier', 4, 2)->default(1);
            $table->unsignedTinyInteger('service_discount_percent')->default(0); // "exclusive prices"
            $table->boolean('points_never_expire')->default(false);
            $table->json('benefits')->nullable();               // list of strings shown in the app
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });

        // How points are earned. One row per rule; a service_id makes it a per-treatment bonus.
        Schema::create('earn_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);                         // spend | product_spend | visit | referral | review | birthday | signup | app_booking
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points');                  // spend rules: points per `per_etb` ETB; others: flat points
            $table->unsignedInteger('per_etb')->nullable();     // spend rules only, e.g. 100 → `points` per ETB 100
            $table->unsignedInteger('cap_per_visit')->nullable();
            $table->string('label')->nullable();                // shown in "Ways to earn"
            $table->boolean('active')->default(true);
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();
            $table->index(['type', 'active']);
        });

        // Double-points days and targeted offers.
        Schema::create('loyalty_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('multiplier', 4, 2)->default(2);
            $table->json('service_ids')->nullable();            // null = every service
            $table->json('tier_ids')->nullable();               // null = every tier
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A client's membership (cached balance + tier). Created when they join or first earn.
        Schema::create('client_loyalty', function (Blueprint $table) {
            $table->foreignId('client_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->integer('points_balance')->default(0);      // may go negative after a refund of spent points
            $table->unsignedInteger('points_12m')->default(0);  // sets the tier; spending never lowers it
            $table->unsignedInteger('lifetime_points')->default(0);
            $table->string('referral_code', 16)->unique();
            $table->timestamp('joined_at')->nullable();         // joined explicitly (welcome points given)
            $table->timestamp('tier_changed_at')->nullable();
            $table->boolean('tier_up_seen')->default(true);     // false → app shows the tier-up screen once
            $table->unsignedSmallInteger('birthday_year')->nullable(); // last year the birthday reward was given
            $table->timestamps();
        });

        // Every earn / redeem / expire / adjust / reverse. Positive rows are "lots" spent oldest-first.
        Schema::create('points_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);                         // earn | redeem | expire | adjust | reverse
            $table->integer('points');                          // + earned, − spent
            $table->unsignedInteger('remaining')->default(0);   // positive lots: points not yet spent/expired
            $table->string('source_type', 24)->nullable();      // appointment | order | reward | referral | review | birthday | signup | checkout | manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();               // manual changes
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['expires_at', 'remaining']);
        });

        // The reward catalog (reward ladder).
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('points_cost');
            $table->string('reward_type', 16);                  // discount | free_service | product
            $table->unsignedInteger('value')->default(0);       // discount: ETB off
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tier_required_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->unsignedInteger('stock')->nullable();       // null = unlimited
            $table->string('icon', 40)->default('redeem');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // A redeemed reward = a voucher in the client's wallet, used at the next booking or checkout.
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained()->restrictOnDelete();
            $table->string('voucher_code', 16)->unique();
            $table->unsignedInteger('points_spent');
            $table->string('status', 12)->default('issued');    // issued | used | expired | void
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete(); // the bill it was applied to
            $table->foreignId('used_on_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->unsignedInteger('discount_applied')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('referred_client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('status', 12)->default('pending');   // pending | completed | rewarded
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // ------------------------------------------------------------ Gift cards

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();          // HMAC of the code — the code itself is never stored in plain text
            $table->string('code_last4', 4);
            $table->text('code_encrypted')->nullable();         // kept only until the card is delivered, then wiped
            $table->string('type', 12);                         // monetary | service | promo
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('initial_value');
            $table->unsignedInteger('balance');
            $table->string('status', 16)->default('active');    // pending_payment | active | redeemed | expired | void
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('purchaser_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('purchaser_name')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('recipient_email')->nullable();
            $table->foreignId('recipient_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('design', 24)->default('classic');
            $table->string('delivery', 12)->default('sms');     // sms | email | print
            $table->timestamp('deliver_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('source', 12)->default('app');       // app | front_desk | promo
            $table->foreignId('replaces_id')->nullable()->constrained('gift_cards')->nullOnDelete(); // reissued from a lost card
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['deliver_at', 'delivered_at']);
        });

        Schema::create('gift_card_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);                         // issue | redeem | adjust | void | reissue | expire | restore
            $table->integer('amount');                          // + adds to the balance, − takes from it
            $table->unsignedInteger('balance_after');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        // ------------------------------------------------------------ shared

        // How a bill was settled: voucher / tier price / points (no points earned on these) and gift card.
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('discount_amount')->default(0)->after('amount');   // tier price + voucher + points
            $table->unsignedInteger('gift_card_amount')->default(0)->after('discount_amount');
            $table->unsignedInteger('points_used')->default(0)->after('gift_card_amount');
        });

        // Every manual change to points, gift cards and program settings.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->string('entity', 32);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
            $table->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['discount_amount', 'gift_card_amount', 'points_used']));
        Schema::dropIfExists('gift_card_ledger');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('points_ledger');
        Schema::dropIfExists('client_loyalty');
        Schema::dropIfExists('loyalty_campaigns');
        Schema::dropIfExists('earn_rules');
        Schema::dropIfExists('loyalty_tiers');
        Schema::dropIfExists('loyalty_settings');
    }
};
