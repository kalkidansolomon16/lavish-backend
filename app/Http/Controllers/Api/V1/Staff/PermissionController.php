<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends StaffController
{
    public function __construct(private PermissionService $permissions) {}

    /** The Permissions page: catalogue, each role's grants and which cells you may toggle. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->permissions->matrix($request->user())]);
    }

    /** PUT /staff/permissions/{role}/{permission}  body: granted=true|false */
    public function update(Request $request, Role $role, Permission $permission): JsonResponse
    {
        $data = $request->validate(['granted' => ['required', 'boolean']]);
        $this->permissions->set($request->user(), $role, $permission, $data['granted']);

        return response()->json(['data' => $this->permissions->matrix($request->user())]);
    }

    /** Restores the default grants of every role below you. */
    public function reset(Request $request): JsonResponse
    {
        $this->permissions->resetBelow($request->user());

        return response()->json(['data' => $this->permissions->matrix($request->user()), 'message' => 'Permissions reset to defaults.']);
    }
}
