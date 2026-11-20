<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A learning path. The General Manager defines it; teacher doctors fill in each module. */
class Course extends Model
{
    protected $guarded = ['id'];

    /** The longest a course can be (weeks). Each course sets its own length. */
    public const MAX_WEEKS = 52;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'active' => 'boolean', 'price' => 'integer', 'weeks' => 'integer', 'closed_at' => 'datetime'];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'course_teacher');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('week')->orderBy('id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class)->orderBy('starts_at');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isTaughtBy(?Doctor $doctor): bool
    {
        return $doctor !== null && $this->teachers()->whereKey($doctor->id)->exists();
    }
}
