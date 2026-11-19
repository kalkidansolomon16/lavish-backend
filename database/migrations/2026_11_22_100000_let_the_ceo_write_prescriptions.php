<?php

use App\Enums\DoctorAccess;
use App\Enums\DoctorAvailability;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The CEO is a doctor (medical director): she can write prescriptions for any client.
 * Gives the CEO role "Write prescriptions" and each CEO a doctor profile (prescriptions are signed by a doctor).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }
        app(PermissionService::class)->syncCatalogue(); // CEO always holds every permission that applies to them

        $ceo = Role::query()->where('key', 'ceo')->first();
        foreach ($ceo?->users()->with('doctor')->get() ?? [] as $user) {
            if (! $user->doctor) {
                $user->doctor()->create([
                    'specialty' => 'Medical Director',
                    'access' => DoctorAccess::Active,
                    'availability' => DoctorAvailability::Available,
                ]);
            }
        }
    }

    public function down(): void {}
};
