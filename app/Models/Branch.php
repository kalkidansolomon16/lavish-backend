<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }

    /** Branches in stock-picking order: the main branch first. */
    public static function ordered()
    {
        return static::query()->orderByDesc('is_main')->orderBy('sort')->orderBy('id')->get();
    }

    public static function main(): self
    {
        return static::query()->where('is_main', true)->first() ?? static::query()->orderBy('id')->firstOrFail();
    }
}
