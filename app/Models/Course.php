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

    /** Every course is config('lavish.course_weeks') weeks long (5), whatever is sent. */
    protected static function booted(): void
    {
        static::saving(fn (Course $c) => $c->weeks = (int) config('lavish.course_weeks'));
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'active' => 'boolean', 'price' => 'integer', 'weeks' => 'integer'];
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
