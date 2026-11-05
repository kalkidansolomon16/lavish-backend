<?php

namespace App\Models;

use App\Enums\DoctorAccess;
use App\Enums\DoctorAvailability;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A doctor is a staff user with clinical details. Teachers at the academy are doctors too
 * (course_teacher → doctors), so one calendar covers treatments and classes.
 */
class Doctor extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'access' => DoctorAccess::class,
            'availability' => DoctorAvailability::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DoctorReview::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_teacher');
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'teacher_id');
    }

    /** Doctors clients can see and book: access granted and the account isn't suspended. */
    public function scopeBookable(Builder $q): Builder
    {
        return $q->where('access', DoctorAccess::Active)
            ->whereHas('user', fn ($u) => $u->where('status', UserStatus::Active));
    }

    public function getNameAttribute(): string
    {
        return $this->user?->name ?? 'Former doctor';
    }

    public function branch(): ?Branch
    {
        return $this->user?->staffProfile?->branch;
    }

    public function photoUrl(): ?string
    {
        $path = $this->photo_path ?? $this->user?->avatar_path;

        return $path ? Storage::disk('public')->url($path) : null;
    }

    /** Every doctor gives online consultations; in-clinic services must be in their skills. */
    public function offers(Service $service): bool
    {
        return $service->is_online || $this->services->contains('id', $service->id);
    }
}
