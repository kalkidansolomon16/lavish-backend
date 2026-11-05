<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan db:seed           → roles & permissions + demo data (local only)
     * In production only the roles & permissions are seeded; create the first CEO with
     * `php artisan lavish:create-ceo`.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
