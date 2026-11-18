<?php

use App\Services\PermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** New Treatments page in the portal: adds its permissions (CEO always; General Manager by default). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getSchemaBuilder()->hasTable('roles')) {
            app(PermissionService::class)->syncCatalogue();
        }
    }

    public function down(): void {}
};
