<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A web portal: ceo, gm, reception, ecommerce, doctor, teacher, team. */
class Role extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['team_only' => 'boolean', 'level' => 'integer'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
