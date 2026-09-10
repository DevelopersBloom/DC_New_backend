<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The five loan-application permissions plus the admin-files visibility gate.
     * Kept in sync with config/permissions_list.php ('loan_applications' group).
     */
    private array $names = [
        'create_loan_application',
        'view_loan_applications',
        'estimate_loan_application_collateral',
        'decide_loan_application',
        'view_loan_application_admin_files',
        'convert_loan_application',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        foreach ($this->names as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }

        // Admin gets everything.
        $admin = Role::where('name', 'admin')->where('guard_name', $guard)->first();
        if ($admin) {
            $admin->givePermissionTo($this->names);
        }

        // Manager runs intake, estimation and conversion; deciding and admin-only
        // files stay with the approver (admin) until dedicated roles exist.
        $manager = Role::where('name', 'manager')->where('guard_name', $guard)->first();
        if ($manager) {
            $manager->givePermissionTo([
                'create_loan_application',
                'view_loan_applications',
                'estimate_loan_application_collateral',
                'convert_loan_application',
            ]);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('guard_name', 'api')
            ->whereIn('name', $this->names)
            ->delete();
    }
};
