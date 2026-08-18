"""
Generate UPDATE SQL for documents_journal and transactions.
Paste tab-separated amounts from Excel, enter partner id, get ready SQL.
"""

from datetime import date, timedelta

print("=" * 60)
print("  Interest Amount SQL Generator")
print("=" * 60)

partner_id = input("\nEnter debit_partner_id (e.g. 4): ").strip()

print("\nPaste the amounts from Excel (tab-separated), then press Enter:")
raw = input().strip()

# Handle both tab and multiple spaces as separator
amounts = [a.strip() for a in raw.replace("\t", " ").split() if a.strip()]

start_date = date(2026, 5, 1)

if not amounts:
    print("No amounts found. Exiting.")
    exit(1)

print(f"\nFound {len(amounts)} amounts for {len(amounts)} days starting {start_date}\n")

# Build CASE blocks
case_lines = []
for i, amt in enumerate(amounts):
    d = start_date + timedelta(days=i)
    case_lines.append(f"        WHEN '{d}' THEN {amt}")

case_block = "\n".join(case_lines)
end_date = start_date + timedelta(days=len(amounts) - 1)

# Build new-value CASE for the preview SELECT
new_val_lines = []
for i, amt in enumerate(amounts):
    d = start_date + timedelta(days=i)
    new_val_lines.append(f"        WHEN '{d}' THEN {amt}")
new_val_block = "\n".join(new_val_lines)

sql = f"""-- ============================================================
-- STEP 1: Preview — both tables at once (ordered by date)
-- ============================================================
SELECT
    'documents_journal'   AS source,
    id,
    `date`,
    amount_amd            AS current_amount,
    CASE `date`
{new_val_block}
    END                   AS new_amount
FROM documents_journal
WHERE document_type = 'Տոկոսային եկամուտ արդյունավետ'
  AND debit_partner_id = {partner_id}
  AND `date` BETWEEN '{start_date}' AND '{end_date}'
  AND deleted_at IS NULL

UNION ALL

SELECT
    'transactions'        AS source,
    id,
    `date`,
    amount_amd            AS current_amount,
    CASE `date`
{new_val_block}
    END                   AS new_amount
FROM transactions
WHERE document_type = 'Տոկոսային եկամուտ արդյունավետ'
  AND debit_partner_id = {partner_id}
  AND `date` BETWEEN '{start_date}' AND '{end_date}'
  AND deleted_at IS NULL

ORDER BY `date`, source;


-- ============================================================
-- STEP 2: Run updates
-- ============================================================

UPDATE documents_journal
SET
    amount_amd = CASE `date`
{case_block}
    END,
    updated_at = NOW()
WHERE document_type = 'Տոկոսային եկամուտ արդյունավետ'
  AND debit_partner_id = {partner_id}
  AND `date` BETWEEN '{start_date}' AND '{end_date}'
  AND deleted_at IS NULL;

UPDATE transactions
SET
    amount_amd = CASE `date`
{case_block}
    END,
    updated_at = NOW()
WHERE document_type = 'Տոկոսային եկամուտ արդյունավետ'
  AND debit_partner_id = {partner_id}
  AND `date` BETWEEN '{start_date}' AND '{end_date}'
  AND deleted_at IS NULL;


-- ============================================================
-- STEP 3: Verify — both tables at once after update
-- ============================================================
SELECT 'documents_journal' AS source, id, `date`, amount_amd AS saved_amount
FROM documents_journal
WHERE document_type = 'Տոկոսային եկամուտ արդյունավետ'
  AND debit_partner_id = {partner_id}
  AND `date` BETWEEN '{start_date}' AND '{end_date}'
  AND deleted_at IS NULL

UNION ALL

SELECT 'transactions' AS source, id, `date`, amount_amd AS saved_amount
FROM transactions
WHERE document_type = 'Տոկոսային եկամուտ արդյունավետ'
  AND debit_partner_id = {partner_id}
  AND `date` BETWEEN '{start_date}' AND '{end_date}'
  AND deleted_at IS NULL

ORDER BY `date`, source;
"""

print(sql)

# Also save to file
out_file = f"interest_update_partner_{partner_id}.sql"
with open(out_file, "w", encoding="utf-8") as f:
    f.write(sql)

print(f"SQL also saved to: {out_file}")
