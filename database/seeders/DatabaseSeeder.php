<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan db:seed   → roles & permissions, branches, treatments, Lavish Rewards defaults and the CEO account.
     *                         No demo data — this is what a real installation starts with.
     *
     * Demo data (sample staff, clients, bookings, orders, academy…) only when asked for, never in production:
     *   php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(CoreSeeder::class);
        $this->call(AdminSeeder::class);
    }
}
