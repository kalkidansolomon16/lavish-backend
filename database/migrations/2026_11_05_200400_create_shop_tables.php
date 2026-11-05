<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products, per-branch stock and its movement log, prescriptions, and shop orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 32)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category', 32)->index();     // Skincare | Sun care | Aftercare | Hair | Supplements
            $table->string('size', 32)->nullable();      // "30 ml"
            $table->text('description')->nullable();
            $table->text('usage')->nullable();           // how to use
            $table->unsignedInteger('price');            // whole ETB
            $table->unsignedInteger('reorder_level')->default(10);
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('hue')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Total stock of a product = sum over branches.
        Schema::create('branch_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                  // in | sold | adjust | transfer
            $table->integer('quantity');                 // signed change of the TOTAL stock (0 for transfers)
            $table->unsignedInteger('moved')->default(0); // units moved for transfers
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedInteger('balance_after');    // total stock after the movement
            $table->string('reason');
            $table->string('reference')->nullable();     // invoice no., order no.…
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Clients who tapped "Notify me" on an out-of-stock product.
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'client_id']);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('sent'); // sent | ordered
            $table->timestamps();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // null = outside medicine
            $table->string('custom_name')->nullable();     // medicine Lavish doesn't sell (bought at a pharmacy)
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('instructions')->nullable();
            $table->boolean('was_out_of_stock')->default(false); // product had no stock when prescribed
            $table->timestamp('suggested_at')->nullable();       // doctor re-suggested it after restock
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('placed');   // placed | confirmed | preparing | ready | out | delivered | cancelled
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('delivery_fee')->default(0);
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('total');
            $table->string('payment_method', 24);              // pay_on_delivery | telebirr | cbe_birr | card
            $table->string('delivery_address');
            $table->string('delivery_note')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('courier_name')->nullable();
            $table->string('courier_phone', 20)->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('source', 16)->default('app');      // app | prescription | staff
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');                    // snapshot
            $table->unsignedInteger('unit_price');             // snapshot
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('line_total');
            $table->timestamps();
        });

        // Tracking timeline shown in the app and the web.
        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = the mobile app / system
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('stock_alerts');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('branch_stock');
        Schema::dropIfExists('products');
    }
};
