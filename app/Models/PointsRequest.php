<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member asking to use points: off a shop order, off a treatment, or for something else.
 * pending → (shop: forwarded to e-commerce) → completed | rejected; the client may cancel while pending.
 * Points are taken only on completion; until then they are held (not available for another request).
 */
class PointsRequest extends Model
{
    public const KINDS = ['shop', 'treatment', 'other'];

    public const OPEN = ['pending', 'forwarded'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'forwarded_at' => 'datetime',
            'handled_at' => 'datetime',
            'points' => 'integer',
            'value_etb' => 'integer',
            'points_used' => 'integer',
            'discount_etb' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function forwarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'shop' => 'Discount on shop products',
            'treatment' => 'Discount on a treatment',
            default => 'Other',
        };
    }
}
