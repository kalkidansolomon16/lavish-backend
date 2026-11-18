<?php

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 1. Splits the combined permissions (e.g. "staff.add" = add + edit + reset password) into one permission
 *    per action, keeping every role's current abilities (config permissions.renamed).
 * 2. Lavish Team is no longer a role/portal: it is a page every staff member sees. People who only had the
 *    "team" role move to the new Nurse role (change them on the Staff page if they are not nurses).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('roles')) {
            return;
        }
        $hadCatalogue = Permission::query()->exists();
        // What each role held before (by key).
        $before = Role::query()->with('permissions:id,key')->get()->mapWithKeys(fn (Role $r) => [$r->key => $r->permissions->pluck('key')->all()]);

        app(PermissionService::class)->syncCatalogue();

        if ($hadCatalogue) {
            $renamed = config('permissions.renamed');
            foreach (Role::all() as $role) {
                $old = $before[$role->key] ?? [];
                $keys = collect($old)->flatMap(fn ($k) => $renamed[$k] ?? [$k])->unique();
                $ids = Permission::query()->whereIn('key', $keys)->get()->filter(fn (Permission $p) => $p->appliesTo($role->key))->pluck('id');
                if ($role->key !== 'ceo' && $old !== []) {
                    $role->permissions()->syncWithoutDetaching($ids);
                }
            }
        }

        // Remove permissions no longer in the catalogue (old combined keys and page.team).
        $current = collect(config('permissions.catalogue'))->flatMap(fn ($items) => array_keys($items))->all();
        Permission::query()->whereNotIn('key', $current)->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });

        // Lavish Team role → Nurse.
        $team = Role::query()->where('key', 'team')->first();
        $nurse = Role::query()->where('key', 'nurse')->first();
        if ($team) {
            if ($nurse) {
                foreach ($team->users()->get() as $u) {
                    $u->roles()->syncWithoutDetaching([$nurse->id]);
                }
            }
            $team->users()->detach();
            $team->permissions()->detach();
            $team->delete();
        }
    }

    public function down(): void
    {
        // Not reversible: the old combined permissions are re-created by re-running an older seeder.
    }
};
