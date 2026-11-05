<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A treatment clients can book (or the online consultation, is_online = true). */
class Service extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_online' => 'boolean', 'active' => 'boolean', 'price' => 'integer', 'duration_minutes' => 'integer'];
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public static function online(): self
    {
        return static::query()->where('is_online', true)->firstOrFail();
    }
}
