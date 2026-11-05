<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Phone;
use Illuminate\Console\Command;

/** First account on a fresh production database: php artisan lavish:create-ceo */
class CreateCeo extends Command
{
    protected $signature = 'lavish:create-ceo {--name=} {--phone=} {--email=}';

    protected $description = 'Create the CEO account (top of the permission hierarchy)';

    public function handle(PermissionService $permissions): int
    {
        $permissions->syncCatalogue();

        $name = $this->option('name') ?: $this->ask('Full name');
        $phone = Phone::normalize($this->option('phone') ?: $this->ask('Phone number'));
        $email = $this->option('email') ?: $this->ask('Email (optional)', '');
        if (! $phone) {
            $this->error('That phone number is not valid.');

            return self::FAILURE;
        }
        if (User::withTrashed()->where('phone', $phone)->exists()) {
            $this->error('A user with this phone already exists.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (min 8 characters)');
        if (strlen((string) $password) < 8) {
            $this->error('Password too short.');

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name, 'phone' => $phone, 'email' => $email ?: null, 'type' => UserType::Staff,
            'status' => UserStatus::Active, 'password' => $password, 'phone_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('key', 'ceo')->value('id'));
        $user->staffProfile()->create(['position' => 'Chief Executive Officer']);

        $this->info("CEO account created for {$name} ({$phone}). Sign in on the web portal.");

        return self::SUCCESS;
    }
}
