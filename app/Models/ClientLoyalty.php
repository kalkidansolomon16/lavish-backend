<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's Lavish Rewards membership: cached balance, 12-month points and tier. */
class ClientLoyalty extends Model
{
    protected $table = 'client_loyalty';

    protected $primaryKey = 'client_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'requested_at' => 'datetime',
            'declined_at' => 'datetime',
            'tier_changed_at' => 'datetime',
            'tier_up_seen' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function enroller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'tier_id');
    }
}
