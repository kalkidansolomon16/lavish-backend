<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Log of every SMS and email sent. */
class OutboundMessage extends Model
{
    protected $guarded = ['id'];

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
