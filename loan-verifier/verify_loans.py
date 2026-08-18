"""
verify_loans.py — Main entry point.

Run:  python verify_loans.py

No arguments required.  Reads the SQL dump from config.SQL_FILE_PATH,
verifies every nominal/effective interest record for every active contract,
and writes an Excel report to config.REPORT_FOLDER.

Payments-table self-consistency checks and deals reconciliation live in the
separate verify_payments.py script/report.
"""

import json
import logging
import os
import re
import sys
from datetime import date

from typing import Dict, List, Optional

import pandas as pd

import config
from calculator import verify_contract, _to_date
from db_loader import load_tables
from models import Contract, InterestRecord
from reporter import build_report
from config import COL_DEAL_CONTRACT_ID

# ---------------------------------------------------------------------------
# Logging setup
# ---------------------------------------------------------------------------

logging.basicConfig(level=config.LOG_LEVEL, format=config.LOG_FORMAT)
logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def _parse_date_env(var: str) -> Optional[date]:
    raw = os.environ.get(var, "").strip()
    if not raw:
        return None
    for fmt in ("%Y-%m-%d", "%d.%m.%Y", "%d/%m/%Y"):
        try:
            return date.fromisoformat(raw) if fmt == "%Y-%m-%d" else \
                   __import__("datetime").datetime.strptime(raw, fmt).date()
        except ValueError:
            continue
    logger.warning(f"Could not parse {var}={raw!r}; ignoring.")
    return None


def main() -> None:
    today = date.today()

    date_from: Optional[date] = _parse_date_env("DATE_FROM")
    date_to:   Optional[date] = _parse_date_env("DATE_TO") or today

    logger.info("=" * 60)
    logger.info(f"Loan interest verification — {today}")
    if date_from or date_to != today:
        logger.info(f"Date range: {date_from or 'contract start'} → {date_to}")
    logger.info("=" * 60)

    # 1. Load all required tables from the SQL dump
    tables = load_tables(config.SQL_FILE_PATH, config.REQUIRED_TABLES)

    contracts_df   = tables[config.TABLE_CONTRACTS]
    txn_df         = tables[config.TABLE_TRANSACTIONS]
    cah_df         = tables[config.TABLE_CONTRACT_AMOUNT_HISTORIES]
    clients_df     = tables[config.TABLE_CLIENTS]
    deals_df       = tables[config.TABLE_DEALS]  # 'in' deals reduce the effective balance

    logger.info(
        f"Loaded: contracts={len(contracts_df)}, transactions={len(txn_df)}, "
        f"cah={len(cah_df)}, clients={len(clients_df)}, deals={len(deals_df)}"
    )

    # 2. Build domain objects
    contracts = _build_contracts(contracts_df, clients_df)
    logger.info(f"Active contracts to verify: {len(contracts)}")

    interest_records = _build_interest_records(txn_df)
    logger.info(f"Interest records found: {len(interest_records)}")

    # Normalise column types for fast filtering
    cah_df   = _normalise_df(cah_df,   config.COL_CAH_CONTRACT_ID)
    deals_df = _normalise_df(deals_df, COL_DEAL_CONTRACT_ID)

    # 3. Verify each contract
    all_rows = []
    mismatches = 0
    for contract in contracts:
        recs = [r for r in interest_records if r.contract_id == contract.id]

        rows = verify_contract(contract, recs, cah_df, deals_df, date_to, date_from)
        all_rows.extend(rows)

        missing  = sum(1 for r in rows if not r.has_stored_record)
        mismatch = sum(1 for r in rows if r.has_stored_record and not r.is_match)
        contract_issues = missing + mismatch
        mismatches += contract_issues
        if contract_issues:
            logger.warning(
                f"  Contract {contract.num} ({contract.client_name}): "
                f"{mismatch} mismatch(es), {missing} missing record(s) "
                f"out of {len(rows)} days"
            )

    # 4. Write report
    logger.info(f"\nTotal records checked : {len(all_rows)}")
    logger.info(f"Total mismatches      : {mismatches}")

    report_path = build_report(all_rows)
    print(f"\nReport written: {report_path}")

    import generate_corrections_sql
    sql_path, verify_sql_path = generate_corrections_sql.main(date_from, date_to)
    print(f"Corrections SQL: {sql_path}")
    print(f"Verification SQL: {verify_sql_path}")

    if mismatches:
        print(f"WARNING: {mismatches} interest mismatch(es) found — review the Details sheet.")
        sys.exit(1)
    else:
        print("All interest records match. No discrepancies found.")


# ---------------------------------------------------------------------------
# Data builders
# ---------------------------------------------------------------------------

def _build_contracts(
    contracts_df: pd.DataFrame,
    clients_df: pd.DataFrame,
) -> List[Contract]:
    """
    Parse the contracts table into Contract dataclass instances.
    Only includes contracts with status in ACTIVE_STATUSES.
    """
    # Build client name lookup
    client_names: Dict[int, str] = {}
    for _, row in clients_df.iterrows():
        cid = _int(row.get(config.COL_CLIENT_ID))
        if cid is None:
            continue
        parts = [
            str(row.get(config.COL_CLIENT_SURNAME) or "").strip(),
            str(row.get(config.COL_CLIENT_NAME) or "").strip(),
            str(row.get(config.COL_CLIENT_MIDDLE_NAME) or "").strip(),
        ]
        client_names[cid] = " ".join(p for p in parts if p)

    contracts: List[Contract] = []
    for _, row in contracts_df.iterrows():
        status = str(row.get(config.COL_CONTRACT_STATUS) or "").strip()
        if status not in config.ACTIVE_STATUSES:
            continue

        cid = _int(row.get(config.COL_CONTRACT_ID))
        if cid is None:
            continue

        client_id = _int(row.get(config.COL_CONTRACT_CLIENT_ID)) or 0

        payment_schedule = _parse_payment_schedule(
            row.get(config.COL_CONTRACT_PAYMENT_SCHEDULE)
        )

        contracts.append(
            Contract(
                id=cid,
                num=str(row.get(config.COL_CONTRACT_NUM) or "").strip(),
                client_id=client_id,
                client_name=client_names.get(client_id, f"Client #{client_id}"),
                estimated_amount=_float(row.get(config.COL_CONTRACT_ESTIMATED_AMOUNT)) or 0.0,
                provided_amount=_float(row.get(config.COL_CONTRACT_PROVIDED_AMOUNT)) or 0.0,
                interest_rate=_float(row.get(config.COL_CONTRACT_INTEREST_RATE)) or 0.0,
                effective_daily_rate=_float(row.get(config.COL_CONTRACT_EFFECTIVE_DAILY_RATE)) or 0.0,
                status=status,
                provided_at=_to_date(row.get(config.COL_CONTRACT_PROVIDED_AT)),
                deadline=_to_date(row.get(config.COL_CONTRACT_DEADLINE)),
                closed_at=_to_date(row.get(config.COL_CONTRACT_CLOSED_AT)),
                payment_type=str(row.get(config.COL_CONTRACT_PAYMENT_TYPE) or "").strip(),
                payment_schedule=payment_schedule,
            )
        )

    return contracts


def _build_interest_records(txn_df: pd.DataFrame) -> List[InterestRecord]:
    """
    Extract nominal and effective daily interest rows from the transactions table.
    Only rows that are NOT soft-deleted (deleted_at IS NULL) and match the two
    known Armenian document type strings are included.
    """
    doc_type_map = {
        config.DOC_TYPE_NOMINAL:   "nominal",
        config.DOC_TYPE_EFFECTIVE: "effective",
    }
    id_pattern = re.compile(config.CONTRACT_ID_PATTERN)

    records: List[InterestRecord] = []
    for _, row in txn_df.iterrows():
        doc_type = str(row.get(config.COL_TXN_DOCUMENT_TYPE) or "").strip()
        if doc_type not in doc_type_map:
            continue

        deleted_at = row.get(config.COL_TXN_DELETED_AT)
        if deleted_at is not None and not (
            isinstance(deleted_at, float) and pd.isna(deleted_at)
        ):
            continue  # soft-deleted

        comment = str(row.get(config.COL_TXN_COMMENT) or "")
        m = id_pattern.search(comment)
        if not m:
            logger.debug(f"Could not extract contract ID from comment: {comment!r}")
            continue

        contract_id = int(m.group(1))
        txn_date = _to_date(row.get(config.COL_TXN_DATE))
        if txn_date is None:
            continue

        amount = _float(row.get(config.COL_TXN_AMOUNT_AMD))
        if amount is None:
            amount = 0.0

        records.append(
            InterestRecord(
                transaction_id=_int(row.get(config.COL_TXN_ID)) or 0,
                contract_id=contract_id,
                record_date=txn_date,
                record_type=doc_type_map[doc_type],
                stored_amount=amount,
            )
        )

    return records


# ---------------------------------------------------------------------------
# Utility helpers
# ---------------------------------------------------------------------------

def _parse_payment_schedule(raw) -> list:
    """Parse payment_schedule JSON string into a Python list of dicts."""
    if raw is None or (isinstance(raw, float) and pd.isna(raw)):
        return []
    s = str(raw).strip()
    if not s or s in ("NULL", "null", ""):
        return []
    try:
        result = json.loads(s)
        return result if isinstance(result, list) else []
    except (json.JSONDecodeError, ValueError):
        logger.debug(f"Could not parse payment_schedule JSON: {s[:80]!r}")
        return []


def _normalise_df(df: pd.DataFrame, id_col: str) -> pd.DataFrame:
    """Ensure the contract-ID column is integer for fast equality checks."""
    if id_col in df.columns:
        df = df.copy()
        df[id_col] = pd.to_numeric(df[id_col], errors="coerce")
    return df


def _int(value) -> Optional[int]:
    if value is None:
        return None
    try:
        return int(value)
    except (ValueError, TypeError):
        return None


def _float(value) -> Optional[float]:
    if value is None:
        return None
    try:
        return float(value)
    except (ValueError, TypeError):
        return None


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    main()
