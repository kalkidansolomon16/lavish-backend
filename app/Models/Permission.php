<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['only_roles' => 'array'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** Does this permission make sense for the role (e.g. "Mark treatments as done" is doctors only)? */
    public function appliesTo(string $roleKey): bool
    {
        return empty($this->only_roles) || in_array($roleKey, $this->only_roles, true);
    }
}
