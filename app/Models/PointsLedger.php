<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a client's points. Positive rows (earn, positive adjust) are "lots":
 * `remaining` counts down as points are spent or expire, oldest lot first.
 */
class PointsLedger extends Model
{
    protected $table = 'points_ledger';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'remaining' => 'integer',
            'expires_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
