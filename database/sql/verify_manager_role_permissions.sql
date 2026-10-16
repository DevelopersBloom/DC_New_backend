-- Read-only check for align_manager_role_permissions.sql. Safe to run any time.
-- Run it with the correct database selected (the one in .env DB_DATABASE).

SELECT DATABASE() AS current_database;

SET @manager := (SELECT id FROM roles WHERE name = 'manager' AND guard_name = 'api');

-- Expect 43 after the change (44 before).
SELECT COUNT(*) AS manager_permission_count FROM role_has_permissions WHERE role_id = @manager;

-- Expect 0 rows: accounting permissions that must be gone.
SELECT p.name AS still_present_should_be_removed
FROM role_has_permissions rhp
JOIN permissions p ON p.id = rhp.permission_id
WHERE rhp.role_id = @manager
  AND p.name IN (
    'view_chart_of_accounts','create_chart_of_account','view_transactions','export_transactions',
    'view_account_balances','view_partner_balances','view_document_journals','export_document_journals',
    'search_account','view_children_accounts','view_v01_report','view_v03_report','view_v05_report',
    'view_v06_report','view_v07_report','view_v13_report','view_v17_report',
    'export_transactions_report','view_reminder_orders'
  );

-- Expect 0 rows: manager permissions that are still missing.
SELECT p.name AS missing_should_be_added
FROM permissions p
WHERE p.guard_name = 'api'
  AND p.name IN (
    'attach_loan_ndm','update_loan_attraction','post_loan_interest','repay_loan_interest',
    'create_expense','create_loan_ndm','add_funds','create_client','create_contract',
    'calculate_contract_interest','confirm_calculated_interest','make_contract_payment',
    'make_full_contract_payment','make_partial_contract_payment','execute_contract_item',
    'pay_contract_amount','request_contract_discount','download_order'
  )
  AND p.id NOT IN (SELECT permission_id FROM role_has_permissions WHERE role_id = @manager);

-- One-line verdict: OK / NOT APPLIED.
SELECT IF(
  (SELECT COUNT(*) FROM role_has_permissions WHERE role_id = @manager) = 43
  AND EXISTS (SELECT 1 FROM role_has_permissions rhp JOIN permissions p ON p.id = rhp.permission_id
              WHERE rhp.role_id = @manager AND p.name = 'create_client')
  AND NOT EXISTS (SELECT 1 FROM role_has_permissions rhp JOIN permissions p ON p.id = rhp.permission_id
              WHERE rhp.role_id = @manager AND p.name = 'view_v01_report'),
  'OK - manager role updated', 'NOT APPLIED - manager role still has old permissions') AS verdict;
