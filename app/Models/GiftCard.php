<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Prepaid value. The code is never stored in plain text: `code_hash` is used to find a card,
 * `code_last4` is what staff see. `code_encrypted` (encrypted with the app key) lets the buyer and the
 * recipient show the card's barcode in the app; printed cards carry their number on the card instead.
 * Until redeemed, the balance is a liability for the clinic, not revenue.
 */
class GiftCard extends Model
{
    public const TYPES = ['monetary', 'service', 'promo'];

    /**
     * pending_payment | awaiting_activation (bought in the app and paid; reception activates it) |
     * in_stock (printed, not sold) | active | redeemed (used up) | expired | void
     */
    public const STATUSES = ['pending_payment', 'awaiting_activation', 'in_stock', 'active', 'redeemed', 'expired', 'void'];

    public const DESIGNS = ['classic', 'gold', 'botanical', 'birthday', 'thank_you'];

    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'code_encrypted'];

    protected function casts(): array
    {
        return [
            'code_encrypted' => 'encrypted',
            'expires_at' => 'datetime',
            'deliver_at' => 'datetime',
            'delivered_at' => 'datetime',
            'physical' => 'boolean',
            'claimed_at' => 'datetime',
            'activated_at' => 'datetime',
            'initial_value' => 'integer',
            'balance' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'purchaser_client_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'recipient_client_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(GiftCardLedger::class)->latest('id');
    }

    /** The purchase bill (cards bought in the app or at the desk). */
    public function payment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable');
    }

    public function usable(): bool
    {
        return $this->status === 'active' && $this->balance > 0 && (! $this->expires_at || $this->expires_at->isFuture());
    }

    /** "•••• 7K2M" — how staff and the app show the code. */
    public function maskedCode(): string
    {
        return '•••• '.$this->code_last4;
    }

    public function label(): string
    {
        if ($this->status === 'in_stock') {
            return 'Blank printed card';
        }

        return match ($this->type) {
            'service' => ($this->service?->name ?? 'Treatment').' gift card',
            'promo' => 'Lavish promotional card',
            default => 'ETB '.number_format($this->initial_value).' gift card',
        };
    }
}
