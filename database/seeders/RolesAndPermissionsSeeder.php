<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
//    public function run()
//    {
//        app()[PermissionRegistrar::class]->forgetCachedPermissions();
//
//        $guardName = 'api';
//
//        $permissions = [
////            setting permissions
//            'view_personal_information',
//            'update_personal_information',
//            'upload_personal_information_file',
//            'download_personal_information_file',
//
//            'view_users',
//            'create_user',
//            'update_user',
//            'delete_user',
//
//            'view_categories',
//            'create_category_rate',
//            'delete_category_rate',
//            'view_lump_rates',
//            'create_lump_rate',
//            'update_lump_rate',
//            'delete_lump_rate',
//
//            'view_category_duration',
//            'create_category_duration',
//
//            'view_subcategories',
//            'create_subcategory',
//            'create_subcategory_item',
//            'delete_subcategory_item',
//
//            'view_pawnshops',
//            'update_pawnshop',
//
//            'view_deals',
//            'update_deals',
//            'delete_deal',
//
//            'view_discounts',
//            'respond_discount',
//            'delete_discount',
//
//            'view_chart_of_accounts',
//            'create_chart_of_account',
//            'update_chart_of_account',
//            'delete_chart_of_account',
//
//            'view_rules',
//            'create_rule',
//
//            'view_reminder_orders',
//            'create_reminder_order',
//
//            'view_transactions',
//            'export_transactions',
//
//            'view_account_balances',
//            'export_account_balances',
//
//            'view_partner_balances',
//            'export_partner_balances',
//
//            'view_document_journals',
//            'export_document_journals',
//            'update_document_journal',
//            'delete_document_journal',
//            'restore_document_journal',
//            'force_delete_document_journal',
//
//            'view_v01_report',
//            'view_v03_report',
//            'view_v05_report',
//            'view_v06_report',
//            'view_v07_report',
//            'view_v013_report',
//            'view_v17_report',
//
//            'export_transactions_report',
//            'search_account',
//            'view_children_accounts',
//
//            'view_contracts',
//            'view_logs',
//
//            'attach_loan_ndm',
//            'view_loan_attraction',
//            'update_loan_attraction',
//            'calculate_loan_interest',
//            'post_loan_interest',
//            'repay_loan_ndm',
//            'view_remaining_loan',
//            'view_loan_by_journal',
//
//            'view_files',
//            'upload_file',
//            'download_file',
//            'delete_file',
//
////            dashboard permissions
//            'view_cashbox_balance',
//            'create_expense',
//            'create_loan_ndm',
//            'add_funds',
//
////            clients permissions
//            'view_clients',
//            'view_client',
//            'create_client',
//            'update_client',
//            'classify_client',
//            'export_clients',
//
////            contracts permissions
//            'view_contract',
//            'create_contract',
//            'update_contract',
//
//            'calculate_contract_interest',
//            'confirm_calculated_interest',
//            'export_contracts',
//            'download_contract_file',
//            'download_all_contract_files',
//            'export_contracts_zip',
//
//            'make_contract_payment',
//            'make_full_contract_payment',
//            'make_partial_contract_payment',
//            'execute_contract_item',
//
//            'view_contract_history_details',
//            'pay_contract_amount',
//
//            'request_contract_discount',
//
////            notes permissions
//            'view_notes',
//            'create_note',
//            'update_note',
//            'delete_note',
//
////            financial indicators
//            'view_cashbox_summary',
//            'download_order',
//
//        ];
//
//        foreach ($permissions as $permission) {
//            Permission::firstOrCreate([
//                'name' => $permission,
//                'guard_name' => $guardName,
//            ]);
//        }
//
//        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guardName]);
//        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => $guardName]);
//        $accountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => $guardName]);
//
//        $accountantPermissions = [
//            'view_pawnshops',
//            'update_pawnshop',
//            'view_deals',
//            'view_discounts',
//            'view_chart_of_accounts',
//            'create_chart_of_account',
//            'update_chart_of_account',
//            'delete_chart_of_account',
//            'view_rules',
//            'create_rule',
//            'view_reminder_orders',
//            'create_reminder_order',
//            'view_transactions',
//            'export_transactions',
//            'view_account_balances',
//            'export_account_balances',
//            'view_partner_balances',
//            'export_partner_balances',
//            'view_document_journals',
//            'export_document_journals',
//            'update_document_journal',
//            'delete_document_journal',
//            'restore_document_journal',
//            'force_delete_document_journal',
//            'view_v01_report',
//            'view_v03_report',
//            'view_v05_report',
//            'view_v06_report',
//            'view_v07_report',
//            'view_v013_report',
//            'view_v17_report',
//            'export_transactions_report',
//            'search_account',
//            'view_children_accounts',
//            'view_contracts',
//            'attach_loan_ndm',
//            'view_loan_attraction',
//            'update_loan_attraction',
//            'calculate_loan_interest',
//            'post_loan_interest',
//            'repay_loan_ndm',
//            'view_remaining_loan',
//            'view_loan_by_journal',
//            'view_cashbox_balance',
//            'view_clients',
//            'view_client',
//            'view_contract',
//            'calculate_contract_interest',
//            'confirm_calculated_interest',
//            'export_contracts',
//            'download_contract_file',
//            'download_all_contract_files',
//            'export_contracts_zip',
//            'view_contract_history_details',
//            'view_notes',
//            'create_note',
//            'view_cashbox_summary',
//            'download_order',
//        ];
//        $managerPermissions = [
//            'view_categories',
//            'view_category_duration',
//            'view_subcategories',
//            'view_deals',
//            'view_discounts',
//            'attach_loan_ndm',
//            'view_loan_attraction',
//            'update_loan_attraction',
//            'calculate_loan_interest',
//            'post_loan_interest',
//            'repay_loan_ndm',
//            'view_remaining_loan',
//            'view_loan_by_journal',
//            'view_files',
//            'upload_file',
//            'download_file',
//            'view_cashbox_balance',
//            'create_expense',
//            'create_loan_ndm',
//            'add_funds',
//            'view_clients',
//            'view_client',
//            'create_client',
//            'view_contracts',
//            'view_contract',
//            'create_contract',
//            'calculate_contract_interest',
//            'confirm_calculated_interest',
//            'make_contract_payment',
//            'make_full_contract_payment',
//            'make_partial_contract_payment',
//            'execute_contract_item',
//            'view_contract_history_details',
//            'pay_contract_amount',
//            'request_contract_discount',
//            'view_notes',
//            'create_note',
//            'view_cashbox_summary',
//            'download_order',
//        ];
//        $admin->syncPermissions(
//            Permission::where('guard_name', $guardName)->get()
//        );
//
//        $accountant->syncPermissions(
//            Permission::whereIn('name', $accountantPermissions)
//                ->where('guard_name', $guardName)
//                ->get()
//        );
//
//        $manager->syncPermissions(
//            Permission::whereIn('name', $managerPermissions)
//                ->where('guard_name', $guardName)
//                ->get()
//        );
//
//
//    }
    public function run()
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guardName = 'api';
        $config = config('permissions_list');

        // 2. Ստացեք բոլոր թույլտվությունները հարթ զանգվածով
        $allPermissions = Arr::flatten($config['all_permissions']);

        // 3. Ստեղծեք թույլտվությունները հերթով (firstOrCreate)
        // Սա երաշխավորում է, որ եթե թույլտվությունը կա՝ չի կրկնվի, եթե չկա՝ կստեղծվի
        foreach ($allPermissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName
            ]);
        }

        // 4. Admin դերը
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guardName]);
        // Տալիս ենք բոլոր թույլտվությունները, որոնք հենց նոր ստեղծեցինք
        $admin->syncPermissions(Permission::where('guard_name', $guardName)->get());

        // 5. Մյուս դերերը (Manager, Accountant)
        foreach ($config['roles'] as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guardName]);

            // Այստեղ ստուգում ենք՝ արդյոք թույլտվությունները գոյություն ունեն բազայում նախքան կցելը
            $existingPermissions = Permission::whereIn('name', $rolePermissions)
                ->where('guard_name', $guardName)
                ->get();

            $role->syncPermissions($existingPermissions);
        }
    }
}
