<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Role permissions with the hierarchy rule from the web prototype:
 * you may change a role's permission only if
 *   1. you hold permissions.manage,
 *   2. the role is below you (higher level number),
 *   3. you hold that permission yourself, and
 *   4. the permission applies to that role.
 * The CEO row always has every applicable permission and can't be edited.
 */
class PermissionService
{
    /** Creates/updates roles and the permission catalogue from config/permissions.php. */
    public function syncCatalogue(bool $resetGrants = false): void
    {
        DB::transaction(function () use ($resetGrants) {
            foreach (config('permissions.roles') as $key => $r) {
                Role::updateOrCreate(['key' => $key], [
                    'label' => $r['label'], 'level' => $r['level'],
                    'team_only' => $r['team_only'] ?? false, 'description' => $r['description'] ?? null,
                ]);
            }

            $sort = 0;
            foreach (config('permissions.catalogue') as $group => $items) {
                foreach ($items as $key => $def) {
                    Permission::updateOrCreate(['key' => $key], [
                        'label' => $def[0], 'group' => $group, 'only_roles' => $def[1] ?? null, 'sort' => $sort++,
                    ]);
                }
            }

            foreach (Role::all() as $role) {
                if ($resetGrants || $role->key === 'ceo' || $role->permissions()->doesntExist()) {
                    $this->applyDefaults($role);
                }
            }
        });
    }

    public function applyDefaults(Role $role): void
    {
        $keys = config("permissions.defaults.{$role->key}", []);
        $ids = Permission::query()->whereIn('key', $keys)->get()
            ->filter(fn (Permission $p) => $p->appliesTo($role->key))->pluck('id');
        $role->permissions()->sync($ids);
    }

    public function canGrant(User $actor, Role $role, Permission $permission): bool
    {
        return $role->key !== 'ceo'
            && $actor->hasPermission('permissions.manage')
            && $actor->level() < $role->level
            && $actor->hasPermission($permission->key)
            && $permission->appliesTo($role->key);
    }

    public function set(User $actor, Role $role, Permission $permission, bool $granted): void
    {
        if (! $this->canGrant($actor, $role, $permission)) {
            throw BusinessRuleException::make('cannot_grant', 'You can’t change that permission.', status: 403);
        }
        $granted
            ? $role->permissions()->syncWithoutDetaching([$permission->id])
            : $role->permissions()->detach($permission->id);
    }

    /** Restores defaults for every role below the actor. */
    public function resetBelow(User $actor): void
    {
        if (! $actor->hasPermission('permissions.manage')) {
            throw BusinessRuleException::make('cannot_grant', 'You can’t change permissions.', status: 403);
        }
        Role::query()->where('level', '>', $actor->level())->get()->each(fn (Role $r) => $this->applyDefaults($r));
    }

    /** Permissions page payload: catalogue + every role's grants + what the actor may edit. */
    public function matrix(User $actor): array
    {
        $permissions = Permission::query()->orderBy('sort')->get();
        $roles = Role::query()->with('permissions:id,key')->orderBy('level')->orderBy('id')->get();

        return [
            'groups' => $permissions->groupBy('group')->map(fn ($items, $group) => [
                'group' => $group,
                'items' => $items->map(fn (Permission $p) => ['key' => $p->key, 'label' => $p->label, 'only' => $p->only_roles])->values(),
            ])->values(),
            'roles' => $roles->map(fn (Role $r) => [
                'key' => $r->key,
                'label' => $r->label,
                'level' => $r->level,
                'team_only' => $r->team_only,
                'granted' => $r->permissions->pluck('key')->values(),
                'editable' => $permissions->filter(fn (Permission $p) => $this->canGrant($actor, $r, $p))->pluck('key')->values(),
            ]),
        ];
    }
}
