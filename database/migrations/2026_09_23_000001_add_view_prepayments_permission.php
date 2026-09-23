<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Kept in sync with config/permissions_list.php ('loans_and_deals' group).
     */
    private string $name = 'view_prepayments';

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        Permission::firstOrCreate([
            'name' => $this->name,
            'guard_name' => $guard,
        ]);

        // Same roles that already carry view_deals / view_discounts.
        Role::where('guard_name', $guard)
            ->whereIn('name', ['admin', 'accountant', 'manager'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($this->name));
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('guard_name', 'api')
            ->where('name', $this->name)
            ->delete();
    }
};
