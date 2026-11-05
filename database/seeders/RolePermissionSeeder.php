<?php

namespace Database\Seeders;

use App\Services\PermissionService;
use Illuminate\Database\Seeder;

/**
 * Syncs roles and the permission catalogue from config/permissions.php.
 * Safe to run again: existing grants are kept (except the CEO, who always gets everything).
 */
class RolePermissionSeeder extends Seeder
{
    public function run(PermissionService $permissions): void
    {
        $permissions->syncCatalogue();
    }
}
