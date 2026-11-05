<?php

namespace App\Models;

use App\Enums\DoctorAccess;
use App\Enums\UserStatus;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * Everybody who can log in: staff (web), clients and students (mobile).
 *
 * @property UserType $type
 * @property UserStatus $status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'type', 'status', 'password', 'must_change_password',
        'phone_verified_at', 'email_verified_at', 'last_login_at', 'avatar_path', 'locale',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Permission keys, cached for the request. */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ---- relations ----

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    // ---- type helpers ----

    public function isStaff(): bool
    {
        return $this->type === UserType::Staff;
    }

    public function isClient(): bool
    {
        return $this->type === UserType::Client;
    }

    public function isStudent(): bool
    {
        return $this->type === UserType::Student;
    }

    /**
     * Can this account use the system right now? Suspended users and doctors without
     * active access are locked out (a doctor's access is managed separately from the account).
     */
    public function canSignIn(): bool
    {
        if ($this->status !== UserStatus::Active) {
            return false;
        }
        if ($this->isStaff() && $this->hasRole('doctor') && ! $this->hasRole('ceo', 'gm')) {
            return $this->doctor?->access === DoctorAccess::Active;
        }

        return true;
    }

    // ---- roles & permissions ----

    /** @return list<string> */
    public function roleKeys(): array
    {
        return $this->roles->pluck('key')->all();
    }

    public function hasRole(string ...$keys): bool
    {
        return (bool) array_intersect($keys, $this->roleKeys());
    }

    /** Hierarchy level: the highest of the person's roles (1 = CEO). Non-staff get 99. */
    public function level(): int
    {
        return (int) ($this->roles->min('level') ?? 99);
    }

    /** @return list<string> All permission keys granted by any of the user's roles. */
    public function permissionKeys(): array
    {
        if ($this->permissionCache === null) {
            $this->loadMissing('roles.permissions');
            $this->permissionCache = $this->roles
                ->flatMap(fn (Role $r) => $r->permissions->pluck('key'))
                ->unique()->values()->all();
        }

        return $this->permissionCache;
    }

    public function hasPermission(string ...$keys): bool
    {
        return (bool) array_intersect($keys, $this->permissionKeys());
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }

    /** Active staff members holding a permission — used to target staff notifications. */
    public function scopeWithPermission(Builder $q, string $key): Builder
    {
        return $q->where('type', UserType::Staff)
            ->where('status', UserStatus::Active)
            ->whereHas('roles.permissions', fn ($p) => $p->where('key', $key));
    }

    // ---- misc ----

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /** Used by the SMS notification channel. */
    public function routeNotificationForSms(): string
    {
        return $this->phone;
    }
}
