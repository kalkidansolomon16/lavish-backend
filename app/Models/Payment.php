<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'discount_amount' => 'integer',
            'gift_card_amount' => 'integer',
            'points_used' => 'integer',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class)->latest('id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** What is still owed in money: the bill minus tier price / voucher / points and gift card. */
    public function dueAmount(): int
    {
        return max(0, (int) $this->amount - (int) $this->discount_amount - (int) $this->gift_card_amount);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [PaymentStatus::Unpaid, PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::InReview], true);
    }
}
