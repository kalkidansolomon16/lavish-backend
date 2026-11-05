<?php

namespace Tests;

use App\Models\User;
use App\Support\Phone;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /** Roles & permissions + the demo data (same people as the prototypes). */
    protected function seedDemo(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    /** Acts as the demo user with this phone number. */
    protected function as(string $phone): User
    {
        $user = User::query()->where('phone', Phone::normalize($phone))->firstOrFail();
        Sanctum::actingAs($user, [$user->type->value]);

        return $user;
    }

    // Demo accounts (see DemoSeeder)
    protected const CEO = '+251911001122';

    protected const GM = '+251911009900';

    protected const RECEPTION = '+251911003344';

    protected const ECOMMERCE = '+251911112233';

    protected const DR_MERON = '+251911500001';   // doctor + teacher

    protected const DR_YONAS = '+251911500002';

    protected const NURSE = '+251911445566';      // Lavish Team only

    protected const TIGIST = '+251911223344';     // client with the app

    protected const EDEN = '+251912456789';       // student (activated)
}
