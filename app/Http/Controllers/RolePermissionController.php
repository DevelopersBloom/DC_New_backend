<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    private const GUARD = 'api';

    public function index(): JsonResponse
    {
        $config = config('permissions_list');
        $permissionsByName = Permission::where('guard_name', self::GUARD)
            ->get()
            ->keyBy('name');

        $groups = [];
        foreach ($config['all_permissions'] as $key => $permissionNames) {
            $permissions = [];
            foreach ($permissionNames as $name) {
                $permission = $permissionsByName->get($name);
                if ($permission) {
                    $permissions[] = [
                        'id' => $permission->id,
                        'name' => $permission->name,
                    ];
                }
            }
            if (!empty($permissions)) {
                $groups[] = [
                    'key' => $key,
                    'permissions' => $permissions,
                ];
            }
        }

        $roles = Role::with('permissions')
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->get(['id', 'name']);

        $adminRole = $roles->firstWhere('name', 'admin');

        $rolePermissions = [];
        foreach ($roles as $role) {
            $rolePermissions[(string) $role->id] = $role->permissions
                ->pluck('id')
                ->values()
                ->all();
        }

        return response()->json([
            'roles' => $roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
            ])->values(),
            'groups' => $groups,
            'role_permissions' => $rolePermissions,
            'admin_role_id' => $adminRole?->id,
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->guard_name !== self::GUARD) {
            return response()->json(['message' => 'Role not found'], 404);
        }

        if ($role->name === 'admin') {
            return response()->json(['message' => 'Admin role permissions cannot be modified'], 403);
        }

        $validated = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer'],
        ]);

        $permissionIds = $validated['permission_ids'];
        $permissions = Permission::where('guard_name', self::GUARD)
            ->whereIn('id', $permissionIds)
            ->get();

        if ($permissions->count() !== count(array_unique($permissionIds))) {
            return response()->json(['message' => 'Invalid permission ids'], 422);
        }

        $role->syncPermissions($permissions);

        $role->load('permissions');

        return response()->json([
            'role_permissions' => [
                (string) $role->id => $role->permissions->pluck('id')->values()->all(),
            ],
        ]);
    }
}
