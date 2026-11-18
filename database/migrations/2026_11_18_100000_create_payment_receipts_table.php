<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof of payment. While online payment (Chapa) is not connected, clients pay by any means
 * (Telebirr / CBE Birr / bank transfer / cash…) and upload the receipt; reception accepts or rejects it.
 * Staff can also attach a receipt when they record a payment at the desk.
 * The file itself is an Attachment (attachable = payment_receipt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 8);                       // client | staff
            $table->string('method', 24);                      // cash | telebirr | cbe_birr | card | bank_transfer
            $table->string('reference')->nullable();           // transaction / slip number
            $table->unsignedInteger('amount')->nullable();     // what the client says they paid
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending');  // pending | accepted | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
