<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The role used to live in two places that drifted apart: the legacy
 * `users.role` column and Spatie's `model_has_roles`. Spatie is what the
 * `can:*` route middleware actually checks, so it is treated as the source
 * of truth and copied into the column.
 *
 * - User has a Spatie role            → users.role = that role name.
 * - No Spatie role, column is a valid role → Spatie role is assigned from the column
 *   (so nobody silently loses access).
 * - No Spatie role, column is not a valid role (e.g. the old 'user' default) → NULL.
 *
 * The column default changes from the non-existent 'user' role to NULL.
 */
return new class extends Migration
{
    private string $guard = 'api';
    private string $modelType = 'App\\Models\\User';

    public function up(): void
    {
        $roleIdsByName = DB::table('roles')
            ->where('guard_name', $this->guard)
            ->pluck('id', 'name');

        $spatieRoleByUser = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->modelType)
            ->where('roles.guard_name', $this->guard)
            ->orderBy('roles.id')
            ->get(['model_has_roles.model_id', 'roles.name'])
            ->groupBy('model_id')
            ->map(fn ($rows) => $rows->first()->name);

        DB::transaction(function () use ($roleIdsByName, $spatieRoleByUser) {
            foreach (DB::table('users')->get(['id', 'role']) as $user) {
                $spatieRole = $spatieRoleByUser->get($user->id);

                if ($spatieRole !== null) {
                    $role = $spatieRole;
                } elseif ($user->role !== null && $roleIdsByName->has($user->role)) {
                    $role = $user->role;
                    DB::table('model_has_roles')->insert([
                        'role_id' => $roleIdsByName->get($role),
                        'model_type' => $this->modelType,
                        'model_id' => $user->id,
                    ]);
                } else {
                    $role = null;
                }

                if ($user->role !== $role) {
                    DB::table('users')->where('id', $user->id)->update(['role' => $role]);
                }
            }
        });

        DB::statement('ALTER TABLE `users` MODIFY `role` VARCHAR(255) NULL DEFAULT NULL');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The copied role values are not reverted — they are the correct ones.
        DB::table('users')->whereNull('role')->update(['role' => 'user']);
        DB::statement("ALTER TABLE `users` MODIFY `role` VARCHAR(255) NOT NULL DEFAULT 'user'");
    }
};
