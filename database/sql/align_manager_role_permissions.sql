-- Align the `manager` role (guard `api`) with config/permissions_list.php.
-- Only touches table `role_has_permissions`, only for the manager role.
-- Run `php artisan permission:cache-reset` afterwards.

START TRANSACTION;

SET @manager := (SELECT id FROM roles WHERE name = 'manager' AND guard_name = 'api');

-- 1) BEFORE
SELECT COUNT(*) AS before_count FROM role_has_permissions WHERE role_id = @manager;

-- 2) Remove the 19 accounting permissions
DELETE rhp FROM role_has_permissions rhp
JOIN permissions p ON p.id = rhp.permission_id AND p.guard_name = 'api'
WHERE rhp.role_id = @manager
  AND p.name IN (
    'view_chart_of_accounts','create_chart_of_account','view_transactions','export_transactions',
    'view_account_balances','view_partner_balances','view_document_journals','export_document_journals',
    'search_account','view_children_accounts','view_v01_report','view_v03_report','view_v05_report',
    'view_v06_report','view_v07_report','view_v13_report','view_v17_report',
    'export_transactions_report','view_reminder_orders'
  );

-- 3) Add the 18 manager permissions (skips ones already linked)
INSERT IGNORE INTO role_has_permissions (permission_id, role_id)
SELECT p.id, @manager FROM permissions p
WHERE p.guard_name = 'api' AND @manager IS NOT NULL
  AND p.name IN (
    'attach_loan_ndm','update_loan_attraction','post_loan_interest','repay_loan_interest',
    'create_expense','create_loan_ndm','add_funds','create_client','create_contract',
    'calculate_contract_interest','confirm_calculated_interest','make_contract_payment',
    'make_full_contract_payment','make_partial_contract_payment','execute_contract_item',
    'pay_contract_amount','request_contract_discount','download_order'
  );

-- 4) AFTER (expect 43)
SELECT COUNT(*) AS after_count FROM role_has_permissions WHERE role_id = @manager;

-- Check the numbers, then run COMMIT; (or ROLLBACK; to undo)
-- COMMIT;
