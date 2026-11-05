<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+2519'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'type' => UserType::Client,
            'status' => UserStatus::Active,
            'phone_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function client(): static
    {
        return $this->state(['type' => UserType::Client])->afterCreating(function (User $u) {
            $u->client()->create(['name' => $u->name, 'phone' => $u->phone, 'email' => $u->email, 'source' => 'app']);
        });
    }

    public function student(): static
    {
        return $this->state(['type' => UserType::Student]);
    }

    /** A staff member holding the given portal roles (roles must already be seeded). */
    public function staff(string ...$roles): static
    {
        return $this->state(['type' => UserType::Staff])->afterCreating(function (User $u) use ($roles) {
            $u->roles()->attach(Role::query()->whereIn('key', $roles)->pluck('id'));
            $u->staffProfile()->create(['position' => ucfirst($roles[0] ?? 'Staff')]);
        });
    }
}
