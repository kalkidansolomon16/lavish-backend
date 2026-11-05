<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['purpose' => OtpPurpose::class, 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
