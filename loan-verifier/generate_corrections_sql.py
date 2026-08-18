#!/usr/bin/env python3
"""
generate_corrections_sql.py
Generate correction SQL for all mismatches and missing records, covering
both `transactions` and `documents_journal` tables, grouped by client ID.

Run:  python3 generate_corrections_sql.py
Output: corrections_by_client_YYYY-MM-DD.sql
"""

import logging
import re
from collections import defaultdict
from datetime import date
from typing import Dict, List, Optional, Tuple

import pandas as pd

import config
from calculator import verify_contract, _to_date
from db_loader import load_tables
from models import InterestRecord
from verify_loans import _build_contracts, _build_interest_records, _normalise_df, _parse_date_env

logging.basicConfig(level=config.LOG_LEVEL, format=config.LOG_FORMAT)
logger = logging.getLogger(__name__)

TABLE_DOC_JOURNAL = "documents_journal"

# Loss-interest accruals: posted instead of regular interest income once a
# contract goes into loss/overdue mode.  A "missing" regular record on/after
# this switch is a reclassification, not a gap — no INSERT must be generated.
DOC_TYPE_LOSS_NOMINAL   = "Կորստի անվանական տոկոս"
DOC_TYPE_LOSS_EFFECTIVE = "Կորստի արդյունավետ տոկոս"


def main(date_from: Optional[date] = None, date_to: Optional[date] = None) -> Tuple[str, str]:
    today = date.today()
    if date_from is None:
        date_from = _parse_date_env("DATE_FROM")
    if date_to is None:
        date_to = _parse_date_env("DATE_TO") or today
    logger.info("=" * 60)
    logger.info(f"Generating corrections SQL — {today}")
    logger.info(f"Date range: {date_from or 'contract start'} → {date_to}")
    logger.info("=" * 60)

    # Load tables including documents_journal
    all_tables = config.REQUIRED_TABLES + [TABLE_DOC_JOURNAL]
    tables = load_tables(config.SQL_FILE_PATH, all_tables)

    contracts_df = tables[config.TABLE_CONTRACTS]
    txn_df       = tables[config.TABLE_TRANSACTIONS]
    cah_df       = tables[config.TABLE_CONTRACT_AMOUNT_HISTORIES]
    clients_df   = tables[config.TABLE_CLIENTS]
    deals_df     = tables[config.TABLE_DEALS]
    dj_df        = tables[TABLE_DOC_JOURNAL]

    contracts = _build_contracts(contracts_df, clients_df)
    logger.info(f"Active contracts: {len(contracts)}")

    # Interest records from transactions
    txn_records: List[InterestRecord] = _build_interest_records(txn_df)

    # key: (contract_id, date, record_type) -> transaction row id / stored amount
    txn_id_lookup:     Dict[Tuple, int]   = {}
    txn_amount_lookup: Dict[Tuple, float] = {}
    for rec in txn_records:
        k = (rec.contract_id, rec.record_date, rec.record_type)
        txn_id_lookup[k]     = rec.transaction_id
        txn_amount_lookup[k] = rec.stored_amount

    # Interest records from documents_journal
    dj_id_lookup:     Dict[Tuple, int]   = {}
    dj_amount_lookup: Dict[Tuple, float] = {}
    _build_dj_lookup(dj_df, dj_id_lookup, dj_amount_lookup)
    logger.info(f"documents_journal interest rows indexed: {len(dj_id_lookup)}")

    # Normalise join keys
    cah_df   = _normalise_df(cah_df,   config.COL_CAH_CONTRACT_ID)
    deals_df = _normalise_df(deals_df, config.COL_DEAL_CONTRACT_ID)

    # Contracts in loss mode: earliest non-deleted loss-interest accrual date
    loss_since: Dict[int, date] = {}
    loss_mask = txn_df[config.COL_TXN_DOCUMENT_TYPE].isin(
        [DOC_TYPE_LOSS_NOMINAL, DOC_TYPE_LOSS_EFFECTIVE]
    ) & txn_df[config.COL_TXN_DELETED_AT].isna()
    id_pattern = re.compile(config.CONTRACT_ID_PATTERN)
    for _, lrow in txn_df[loss_mask].iterrows():
        m = id_pattern.search(str(lrow.get(config.COL_TXN_COMMENT) or ""))
        ld = _to_date(lrow.get(config.COL_TXN_DATE))
        lcid = int(m.group(1)) if m else (
            int(lrow["contract_id"]) if pd.notna(lrow.get("contract_id")) else None
        )
        if lcid is None or ld is None:
            continue
        if lcid not in loss_since or ld < loss_since[lcid]:
            loss_since[lcid] = ld
    if loss_since:
        logger.info(f"Contracts in loss mode (no INSERTs for them): {sorted(loss_since)}")

    # Template rows for INSERTs: latest existing accrual row per
    # (contract_id, record_type) — cloned server-side so account/partner
    # fields stay consistent with the software's own postings.
    txn_template: Dict[Tuple[int, str], Tuple[date, int]] = {}
    for rec in txn_records:
        k = (rec.contract_id, rec.record_type)
        cur = txn_template.get(k)
        if cur is None or rec.record_date > cur[0]:
            txn_template[k] = (rec.record_date, rec.transaction_id)

    dj_template: Dict[Tuple[int, str], Tuple[date, int]] = {}
    for (tpl_cid, tpl_date, tpl_type), tpl_id in dj_id_lookup.items():
        k = (tpl_cid, tpl_type)
        cur = dj_template.get(k)
        if cur is None or tpl_date > cur[0]:
            dj_template[k] = (tpl_date, tpl_id)

    # Per-client collections
    dj_update_lines:  Dict[int, List[str]] = defaultdict(list)
    txn_update_lines: Dict[int, List[str]] = defaultdict(list)
    insert_lines:     Dict[int, List[str]] = defaultdict(list)
    missing_lines:    Dict[int, List[str]] = defaultdict(list)
    verify_dj_ids:    Dict[int, List[int]] = defaultdict(list)
    verify_txn_ids:   Dict[int, List[int]] = defaultdict(list)
    verify_inserts:   Dict[int, List[Tuple[int, str, str]]] = defaultdict(list)  # (contract_id, date, doc_type)

    total_mismatches = 0
    total_missing    = 0

    for contract in contracts:
        recs = [r for r in txn_records if r.contract_id == contract.id]
        rows = verify_contract(contract, recs, cah_df, deals_df, date_to, date_from)

        for row in rows:
            if row.is_match:
                continue

            record_type = "nominal" if row.interest_type == "Nominal" else "effective"
            date_str    = row.record_date.isoformat()
            key         = (contract.id, row.record_date, record_type)
            cid         = contract.client_id
            calc        = row.calculated_interest

            if row.has_stored_record:
                # ---- MISMATCH: UPDATE both tables ----
                total_mismatches += 1

                txn_id = txn_id_lookup.get(key)
                if txn_id:
                    txn_update_lines[cid].append(
                        f"UPDATE transactions SET amount_amd = {calc:.2f}, updated_at = NOW() "
                        f"WHERE id = {txn_id};"
                        f"  -- contract={contract.num}  {date_str}  stored={row.stored_interest:.2f}  new={calc:.2f}"
                    )
                    verify_txn_ids[cid].append(txn_id)

                dj_id = dj_id_lookup.get(key)
                if dj_id:
                    dj_stored = dj_amount_lookup.get(key, 0.0)
                    if abs(dj_stored - calc) > config.TOLERANCE:
                        dj_update_lines[cid].append(
                            f"UPDATE documents_journal SET amount_amd = {calc:.2f}, updated_at = NOW() "
                            f"WHERE id = {dj_id};"
                            f"  -- contract={contract.num}  {date_str}  stored={dj_stored:.2f}  new={calc:.2f}"
                        )
                        verify_dj_ids[cid].append(dj_id)

            else:
                # ---- MISSING: INSERT into both tables (cloned from template) ----
                total_missing += 1

                if calc <= 0:
                    missing_lines[cid].append(
                        f"-- MISSING but calculated 0.00, no INSERT: {record_type}  "
                        f"{contract.num}  {date_str}"
                    )
                    continue

                loss_start = loss_since.get(contract.id)
                if loss_start is not None and row.record_date >= loss_start:
                    missing_lines[cid].append(
                        f"-- SKIPPED (loss mode since {loss_start}): {record_type}  "
                        f"{contract.num}  {date_str}  — accrued as Կորստի, no INSERT"
                    )
                    continue

                # documents_journal may still exist with a wrong value → UPDATE it
                dj_id = dj_id_lookup.get(key)
                if dj_id:
                    dj_stored = dj_amount_lookup.get(key, 0.0)
                    if abs(dj_stored - calc) > config.TOLERANCE:
                        dj_update_lines[cid].append(
                            f"UPDATE documents_journal SET amount_amd = {calc:.2f}, updated_at = NOW() "
                            f"WHERE id = {dj_id};"
                            f"  -- contract={contract.num}  {date_str}  stored={dj_stored:.2f}  new={calc:.2f}"
                        )
                        verify_dj_ids[cid].append(dj_id)

                tpl_key = (contract.id, record_type)
                txn_tpl = txn_template.get(tpl_key)
                dj_tpl  = dj_template.get(tpl_key)

                if txn_tpl is None or (dj_id is None and dj_tpl is None):
                    missing_lines[cid].append(
                        f"-- MISSING: {record_type}  {contract.num}  {date_str}  "
                        f"expected={calc:.2f}  → no template row found, INSERT manually"
                    )
                    continue

                insert_lines[cid].extend(
                    _missing_insert_sql(
                        contract_num=contract.num,
                        record_type=record_type,
                        date_str=date_str,
                        calc=calc,
                        txn_tpl_id=txn_tpl[1],
                        dj_tpl_id=None if dj_id else dj_tpl[1],
                        existing_dj_id=dj_id,
                    )
                )
                doc_type_arm = (
                    config.DOC_TYPE_NOMINAL if record_type == "nominal"
                    else config.DOC_TYPE_EFFECTIVE
                )
                verify_inserts[cid].append((contract.id, date_str, doc_type_arm))

    logger.info(f"Mismatches: {total_mismatches}, Missing: {total_missing}")

    # ---- Build SQL output ----
    all_clients = sorted(set(
        list(dj_update_lines)  + list(txn_update_lines) +
        list(insert_lines)     + list(missing_lines)    + list(verify_dj_ids)
    ))

    out_lines: List[str] = [
        f"-- Corrections generated {today}",
        f"-- Date range: {date_from or 'contract start'} → {date_to}",
        f"-- Total mismatches: {total_mismatches}  |  Total missing records: {total_missing}",
        "-- Paste this whole file into the SQL tab and run it once.",
        "-- All changes commit together; if any statement fails, everything rolls back.",
        "",
        "START TRANSACTION;",
        "",
    ]
    verify_lines: List[str] = [
        f"-- Verification queries for corrections generated {today}",
        f"-- Date range: {date_from or 'contract start'} → {date_to}",
        "-- Run this AFTER the corrections script has committed, to spot-check the results.",
        "",
    ]

    for cid in all_clients:
        out_lines += [
            f"-- {'=' * 60}",
            f"-- CLIENT {cid}",
            f"-- {'=' * 60}",
            "",
        ]

        if dj_update_lines.get(cid):
            out_lines.append(f"-- documents_journal  (client {cid})")
            out_lines.extend(dj_update_lines[cid])
            out_lines.append("")

        if txn_update_lines.get(cid):
            out_lines.append(f"-- transactions  (client {cid})")
            out_lines.extend(txn_update_lines[cid])
            out_lines.append("")

        if insert_lines.get(cid):
            out_lines.append(f"-- missing records — INSERTs  (client {cid})")
            out_lines.extend(insert_lines[cid])
            out_lines.append("")

        if missing_lines.get(cid):
            out_lines.extend(missing_lines[cid])
            out_lines.append("")

        # Verification SELECTs go to the separate verify file
        dj_ids  = verify_dj_ids.get(cid, [])
        txn_ids = verify_txn_ids.get(cid, [])
        ins_keys = verify_inserts.get(cid, [])
        if ins_keys or dj_ids or txn_ids:
            verify_lines += [
                f"-- {'=' * 60}",
                f"-- CLIENT {cid}",
                f"-- {'=' * 60}",
                "",
            ]
        if ins_keys:
            verify_lines.append(f"-- verify inserts for client {cid}")
            contract_ids = sorted({k[0] for k in ins_keys})
            dates        = sorted({k[1] for k in ins_keys})
            cid_list  = ", ".join(str(i) for i in contract_ids)
            date_list = ", ".join(f"'{d}'" for d in dates)
            for tbl in ("documents_journal", "transactions"):
                verify_lines.append(
                    f"SELECT id, contract_id, `date`, document_type, amount_amd FROM {tbl} "
                    f"WHERE contract_id IN ({cid_list}) AND `date` IN ({date_list}) "
                    f"AND deleted_at IS NULL ORDER BY contract_id, `date`;"
                )
            verify_lines.append("")
        if dj_ids or txn_ids:
            verify_lines.append(f"-- verify client {cid}")
            if dj_ids:
                id_list = ", ".join(str(i) for i in dj_ids)
                verify_lines.append(
                    f"SELECT id, `date`, amount_amd FROM documents_journal "
                    f"WHERE id IN ({id_list}) ORDER BY `date`;"
                )
            if txn_ids:
                id_list = ", ".join(str(i) for i in txn_ids)
                verify_lines.append(
                    f"SELECT id, `date`, amount_amd FROM transactions "
                    f"WHERE id IN ({id_list}) ORDER BY `date`;"
                )
            verify_lines.append("")

    out_lines.append("COMMIT;")

    out_file = f"corrections_by_client_{today}.sql"
    with open(out_file, "w", encoding="utf-8") as fh:
        fh.write("\n".join(out_lines))
    logger.info(f"Corrections SQL written: {out_file}")

    verify_file = f"corrections_by_client_{today}_verify.sql"
    with open(verify_file, "w", encoding="utf-8") as fh:
        fh.write("\n".join(verify_lines))
    logger.info(f"Verification SQL written: {verify_file}")

    return out_file, verify_file


# ---------------------------------------------------------------------------
# Missing-record INSERT builder
# ---------------------------------------------------------------------------

# Column lists (id excluded — auto-increment on the server)
DJ_COLS = (
    "`date`, operation_number, document_number, document_type, amount_amd, "
    "currency_id, amount_currency, debit_partner_id, deal_id, contract_id, "
    "credit_partner_id, debit_account_id, credit_account_id, cash, "
    "ndm_repayment_id, pawnshop_id, comment, user_id, journalable_type, "
    "journalable_id, created_at, updated_at, deleted_at"
)
TXN_COLS = (
    "`date`, document_number, document_type, debit_account_id, debit_partner_id, "
    "debit_partner_code, debit_partner_name, debit_currency_id, credit_account_id, "
    "credit_partner_id, credit_partner_code, credit_partner_name, credit_currency_id, "
    "amount_amd, amount_currency, amount_currency_id, comment, disbursement_date, "
    "user_id, is_system, deleted_at, created_at, updated_at, transactionable_type, "
    "transactionable_id, contract_id"
)


def _missing_insert_sql(
    contract_num: str,
    record_type: str,
    date_str: str,
    calc: float,
    txn_tpl_id: int,
    dj_tpl_id: Optional[int],
    existing_dj_id: Optional[int],
) -> List[str]:
    """
    Paired INSERTs for one missing daily accrual: documents_journal first,
    then transactions pointing at it via LAST_INSERT_ID().  Both rows are
    cloned from the contract's latest existing accrual row of the same type,
    overriding only date, amount and timestamps.  If the documents_journal
    row already exists (only the transaction is missing), link to it instead.
    """
    lines: List[str] = [
        f"-- missing {record_type}: {contract_num}  {date_str}  amount={calc:.2f}"
    ]

    if existing_dj_id:
        lines.append(f"SET @dj_id = {existing_dj_id};")
    else:
        lines.append(
            f"INSERT INTO documents_journal ({DJ_COLS}) "
            f"SELECT '{date_str}', operation_number, document_number, document_type, "
            f"{calc:.2f}, currency_id, amount_currency, debit_partner_id, deal_id, "
            f"contract_id, credit_partner_id, debit_account_id, credit_account_id, "
            f"cash, ndm_repayment_id, pawnshop_id, comment, user_id, journalable_type, "
            f"journalable_id, NOW(), NOW(), NULL "
            f"FROM documents_journal WHERE id = {dj_tpl_id};"
        )
        lines.append("SET @dj_id = LAST_INSERT_ID();")

    lines.append(
        f"INSERT INTO transactions ({TXN_COLS}) "
        f"SELECT '{date_str}', document_number, document_type, debit_account_id, "
        f"debit_partner_id, debit_partner_code, debit_partner_name, debit_currency_id, "
        f"credit_account_id, credit_partner_id, credit_partner_code, credit_partner_name, "
        f"credit_currency_id, {calc:.2f}, amount_currency, amount_currency_id, comment, "
        f"disbursement_date, user_id, is_system, NULL, NOW(), NOW(), "
        f"transactionable_type, @dj_id, contract_id "
        f"FROM transactions WHERE id = {txn_tpl_id};"
    )
    return lines


# ---------------------------------------------------------------------------
# documents_journal interest-record indexer
# ---------------------------------------------------------------------------

def _build_dj_lookup(
    dj_df: pd.DataFrame,
    id_lookup:     Dict[Tuple, int],
    amount_lookup: Dict[Tuple, float],
) -> None:
    """
    Index documents_journal rows by (contract_id, date, record_type).
    Uses the same comment regex as transactions to identify the contract.
    """
    if dj_df.empty:
        return

    doc_type_map = {
        config.DOC_TYPE_NOMINAL:   "nominal",
        config.DOC_TYPE_EFFECTIVE: "effective",
    }
    id_pattern = re.compile(config.CONTRACT_ID_PATTERN)

    for _, row in dj_df.iterrows():
        doc_type = str(row.get("document_type") or "").strip()
        if doc_type not in doc_type_map:
            continue

        deleted_at = row.get("deleted_at")
        if deleted_at is not None and not (
            isinstance(deleted_at, float) and pd.isna(deleted_at)
        ):
            continue

        comment = str(row.get("comment") or "")
        m = id_pattern.search(comment)
        if not m:
            continue

        contract_id = int(m.group(1))
        d = _to_date(row.get("date"))
        if d is None:
            continue

        dj_id  = _safe_int(row.get("id"))     or 0
        amount = _safe_float(row.get("amount_amd")) or 0.0
        key    = (contract_id, d, doc_type_map[doc_type])

        id_lookup[key]     = dj_id
        amount_lookup[key] = amount


def _safe_int(value) -> Optional[int]:
    if value is None:
        return None
    try:
        return int(value)
    except (ValueError, TypeError):
        return None


def _safe_float(value) -> Optional[float]:
    if value is None:
        return None
    try:
        return float(value)
    except (ValueError, TypeError):
        return None


if __name__ == "__main__":
    main()
