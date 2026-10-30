<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Admin-only: rebuild the open part of a contract's repayment schedule after a principal payment. */
    private array $names = [
        'regenerate_contract_schedule',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($this->names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $admin = Role::where('name', 'admin')->where('guard_name', 'api')->first();
        if ($admin) {
            $admin->givePermissionTo($this->names);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('guard_name', 'api')->whereIn('name', $this->names)->delete();
    }
};
