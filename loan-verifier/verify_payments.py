"""
verify_payments.py — Payments-table verifier entry point.

Run:  python verify_payments.py

No arguments required.  Reads the SQL dump from config.SQL_FILE_PATH and runs:
  Phase 1 (payments_checker.py)      — self-consistency checks on the
                                        `payments` table (amount split,
                                        status/paid, remaining balance).
  Phase 2 (deals_reconciliation.py)  — reconciles completed 'regular'
                                        payments against the `deals` rows
                                        that actually settled them.

Writes a separate Excel report to config.REPORT_FOLDER. This is independent
of verify_loans.py, which only checks nominal/effective interest.
"""

import logging
import sys
from datetime import date

from typing import Optional

import config
from db_loader import load_tables
from deals_reconciliation import check_deals_reconciliation
from payments_checker import check_payments
from reporter import build_payments_report
from config import COL_DEAL_CONTRACT_ID
from verify_loans import _build_contracts, _normalise_df, _parse_date_env

logging.basicConfig(level=config.LOG_LEVEL, format=config.LOG_FORMAT)
logger = logging.getLogger(__name__)


def main() -> None:
    today = date.today()

    date_from: Optional[date] = _parse_date_env("DATE_FROM")
    date_to:   Optional[date] = _parse_date_env("DATE_TO") or today

    logger.info("=" * 60)
    logger.info(f"Payments-table verification — {today}")
    if date_from or date_to != today:
        logger.info(f"Date range: {date_from or 'contract start'} → {date_to}")
    logger.info("=" * 60)

    # 1. Load required tables from the SQL dump
    tables = load_tables(config.SQL_FILE_PATH, config.REQUIRED_TABLES)

    contracts_df = tables[config.TABLE_CONTRACTS]
    clients_df   = tables[config.TABLE_CLIENTS]
    payments_df  = tables[config.TABLE_PAYMENTS]
    deals_df     = tables[config.TABLE_DEALS]

    logger.info(
        f"Loaded: contracts={len(contracts_df)}, clients={len(clients_df)}, "
        f"payments={len(payments_df)}, deals={len(deals_df)}"
    )

    # 2. Build domain objects
    contracts = _build_contracts(contracts_df, clients_df)
    logger.info(f"Active contracts to verify: {len(contracts)}")

    # Normalise column types for fast filtering
    payments_df = _normalise_df(payments_df, config.COL_PAY_CONTRACT_ID)
    deals_df    = _normalise_df(deals_df,    COL_DEAL_CONTRACT_ID)

    # 3. Phase 1 — payments-table self-consistency checks
    payment_rows = check_payments(contracts, payments_df, date_to)
    payment_issues = sum(
        1 for r in payment_rows
        if not r.amount_match or not r.status_ok or r.remaining_increased
    )
    logger.info(f"Payment rows checked  : {len(payment_rows)}")
    logger.info(f"Payment issues found  : {payment_issues}")

    # 4. Phase 2 — reconcile completed regular payments against deals
    deal_rows = check_deals_reconciliation(contracts, payments_df, deals_df, date_to)
    deal_issues = sum(1 for r in deal_rows if r.unpaired or r.interest_flag or not r.penalty_match)
    logger.info(f"Deal reconciliation rows  : {len(deal_rows)}")
    logger.info(f"Deal reconciliation issues: {deal_issues}")

    # 5. Write report
    report_path = build_payments_report(payment_rows, deal_rows)
    print(f"\nReport written: {report_path}")

    if payment_issues or deal_issues:
        print(
            f"WARNING: {payment_issues} payment issue(s), "
            f"{deal_issues} deal reconciliation issue(s) found — review the report sheets."
        )
        sys.exit(1)
    else:
        print("All payment rows and deal reconciliations match. No discrepancies found.")


if __name__ == "__main__":
    main()
