<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Intake questionnaire: concern, skin type, allergies, medications… */
class ClientHistory extends Model
{
    protected $guarded = ['id'];

    public const FIELDS = ['source', 'concern', 'skin_type', 'allergies', 'medications', 'conditions', 'previous_treatments', 'pregnant'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
