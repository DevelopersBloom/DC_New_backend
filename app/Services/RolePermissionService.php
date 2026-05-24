<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RolePermissionService
{
    private const GUARD = 'api';

    private function tableRoles(): string
    {
        return config('permission.table_names.roles', 'roles');
    }

    private function tableRoleHasPermissions(): string
    {
        return config('permission.table_names.role_has_permissions', 'role_has_permissions');
    }

    private function tablePermissions(): string
    {
        return config('permission.table_names.permissions', 'permissions');
    }

    public function getRolePermissionsPayload(): array
    {
        $catalog = $this->permissionCatalog();

        $roles = DB::table($this->tableRoles())
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->get(['id', 'name']);

        $adminRole = $roles->firstWhere('name', 'admin');

        return [
            'roles' => $roles->map(fn ($role) => [
                'id' => (int) $role->id,
                'name' => $role->name,
            ])->values()->all(),
            'groups' => $catalog['groups'],
            'role_has_permissions' => $this->roleHasPermissionsByRole($roles->pluck('id')),
            'admin_role_id' => $adminRole ? (int) $adminRole->id : null,
        ];
    }

    /**
     * @param  int[]  $permissionIds
     */
    public function syncRolePermissions(int $roleId, array $permissionIds): array
    {
        $role = DB::table($this->tableRoles())
            ->where('id', $roleId)
            ->where('guard_name', self::GUARD)
            ->first();

        if (!$role) {
            throw new HttpException(404, 'Role not found');
        }

        if ($role->name === 'admin') {
            throw new HttpException(403, 'Admin role permissions cannot be modified');
        }

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));
        $validIds = $this->permissionCatalog()['valid_ids'];

        $invalid = array_diff($permissionIds, $validIds);
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'permission_ids' => ['Invalid permission ids: ' . implode(', ', $invalid)],
            ]);
        }

        $pivot = $this->tableRoleHasPermissions();

        DB::transaction(function () use ($pivot, $roleId, $permissionIds) {
            DB::table($pivot)->where('role_id', $roleId)->delete();

            if ($permissionIds !== []) {
                $rows = array_map(
                    fn (int $permissionId) => [
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ],
                    $permissionIds
                );
                DB::table($pivot)->insert($rows);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'role_has_permissions' => [
                (string) $roleId => $permissionIds,
            ],
        ];
    }

    /**
     * @return array{groups: array<int, array{key: string, permissions: array<int, array{id: int, name: string}>}>, valid_ids: int[]}
     */
    private function permissionCatalog(): array
    {
        $namesToId = $this->permissionNamesToIds();

        $config = config('permissions_list');
        $groups = [];
        $validIds = [];

        foreach ($config['all_permissions'] as $key => $permissionNames) {
            $permissions = [];
            foreach ($permissionNames as $name) {
                $id = $namesToId[$name] ?? null;
                if ($id === null) {
                    continue;
                }
                $permissions[] = [
                    'id' => $id,
                    'name' => $name,
                ];
                $validIds[] = $id;
            }
            if ($permissions !== []) {
                $groups[] = [
                    'key' => $key,
                    'permissions' => $permissions,
                ];
            }
        }

        return [
            'groups' => array_values($groups),
            'valid_ids' => array_values(array_unique($validIds)),
        ];
    }

    /**
     * Real ids from permissions table (Spatie); matches role_has_permissions.permission_id.
     *
     * @return array<string, int>
     */
    private function permissionNamesToIds(): array
    {
        $table = $this->tablePermissions();

        if (!Schema::hasTable($table)) {
            return $this->permissionNamesToIdsFromConfigFallback();
        }

        return DB::table($table)
            ->where('guard_name', self::GUARD)
            ->pluck('id', 'name')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Fallback only when permissions table is missing (legacy).
     *
     * @return array<string, int>
     */
    private function permissionNamesToIdsFromConfigFallback(): array
    {
        $map = [];
        $id = 1;
        foreach (config('permissions_list.all_permissions') as $permissionNames) {
            foreach ($permissionNames as $name) {
                $map[$name] = $id++;
            }
        }

        return $map;
    }

    /**
     * @param  Collection<int, int|string>  $roleIds
     * @return array<string, int[]>
     */
    private function roleHasPermissionsByRole(Collection $roleIds): array
    {
        if ($roleIds->isEmpty()) {
            return [];
        }

        $pivot = $this->tableRoleHasPermissions();
        $roles = $this->tableRoles();

        $rows = DB::table("{$pivot} as rhp")
            ->join("{$roles} as r", 'r.id', '=', 'rhp.role_id')
            ->where('r.guard_name', self::GUARD)
            ->whereIn('rhp.role_id', $roleIds->all())
            ->orderBy('rhp.role_id')
            ->orderBy('rhp.permission_id')
            ->get(['rhp.role_id', 'rhp.permission_id']);

        $map = [];
        foreach ($roleIds as $roleId) {
            $map[(string) $roleId] = [];
        }

        foreach ($rows as $row) {
            $map[(string) $row->role_id][] = (int) $row->permission_id;
        }

        return $map;
    }
}
