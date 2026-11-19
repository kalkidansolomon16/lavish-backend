<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Seeder;

/**
 * The first CEO (admin) account, so the web portal can be signed in to on a fresh database.
 * Defaults to the existing admin login; override with LAVISH_ADMIN_NAME / _PHONE / _EMAIL / _PASSWORD in .env.
 * Does nothing if a user with that phone already exists. CHANGE THE PASSWORD after the first sign-in in production.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $phone = Phone::normalize(env('LAVISH_ADMIN_PHONE', '+251911001122'));
        if (User::withTrashed()->where('phone', $phone)->exists()) {
            return;
        }
        $user = User::create([
            'name' => env('LAVISH_ADMIN_NAME', 'Dr. Melat Solomon'),
            'phone' => $phone,
            'email' => strtolower(env('LAVISH_ADMIN_EMAIL', 'ceo@lavish.et')),
            'type' => UserType::Staff,
            'status' => UserStatus::Active,
            'password' => env('LAVISH_ADMIN_PASSWORD', 'password'),
            'phone_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('key', 'ceo')->value('id'));
        $user->staffProfile()->create(['position' => 'Chief Executive Officer']);
        // The CEO is also a doctor (medical director): she writes prescriptions.
        $user->doctor()->create(['specialty' => 'Medical Director', 'access' => \App\Enums\DoctorAccess::Active, 'availability' => \App\Enums\DoctorAvailability::Available]);
    }
}
