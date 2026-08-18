"""
payments_checker.py — Phase 1 self-consistency checks for the `payments` table.

These checks are self-contained (no cross-table join to `deals` /
`documents_journal` required) and only apply to payment rows dated on or
before the report date, for contracts currently active (status='initial').

Checks per row (checks 1 and 3 only apply to type='regular' installment rows —
'penalty' and other row types track a different balance and don't follow the
same formulas):
  1. amount == principal_payment + interest_payment
     (principal_payment/interest_payment are the CURRENT outstanding split
     for that installment; both go to 0 once the row is 'completed')
  2. status == 'completed'  =>  paid IS NOT NULL  (applies to all row types)
  3. remaining must not increase vs. the previous 'regular' row for the same
     contract (a real disbursement/top-up would explain an increase, but
     that isn't checked here — flagged rows should be reviewed against
     contract_amount_histories)
"""

import logging
from datetime import date
from typing import List, Optional

import pandas as pd

from config import (
    COL_PAY_CONTRACT_ID, COL_PAY_ID, COL_PAY_DATE, COL_PAY_AMOUNT,
    COL_PAY_PRINCIPAL_PAYMENT, COL_PAY_INTEREST_PAYMENT,
    COL_PAY_TYPE, COL_PAY_STATUS, COL_PAY_PAID, COL_PAY_REMAINING,
    COL_PAY_DELETED_AT,
    PAYMENT_STATUS_COMPLETED, PAYMENT_TYPE_REGULAR,
    ROUNDING_DECIMALS, TOLERANCE,
)
from models import Contract, PaymentCheckRow

logger = logging.getLogger(__name__)


def check_payments(
    contracts: List[Contract],
    payments_df: pd.DataFrame,
    today: date,
) -> List[PaymentCheckRow]:
    """Run Phase 1 checks for every payment row of every active contract."""
    df = payments_df.copy()
    df[COL_PAY_CONTRACT_ID] = pd.to_numeric(df[COL_PAY_CONTRACT_ID], errors="coerce")
    for col in (COL_PAY_AMOUNT, COL_PAY_PRINCIPAL_PAYMENT, COL_PAY_INTEREST_PAYMENT,
                COL_PAY_PAID, COL_PAY_REMAINING):
        df[col] = pd.to_numeric(df[col], errors="coerce")

    rows: List[PaymentCheckRow] = []

    for contract in contracts:
        mask = (
            (df[COL_PAY_CONTRACT_ID] == contract.id)
            & (df[COL_PAY_DELETED_AT].isna())
        )
        contract_payments = df[mask].copy()
        if contract_payments.empty:
            continue

        contract_payments["_date"] = contract_payments[COL_PAY_DATE].apply(_to_date)
        contract_payments = contract_payments[
            contract_payments["_date"].notna() & (contract_payments["_date"] <= today)
        ]
        contract_payments = contract_payments.sort_values(["_date", COL_PAY_ID])

        prev_remaining: Optional[float] = None  # tracked across 'regular' rows only
        for _, r in contract_payments.iterrows():
            amount = float(r[COL_PAY_AMOUNT] or 0.0)
            principal_payment = float(r[COL_PAY_PRINCIPAL_PAYMENT] or 0.0)
            interest_payment = float(r[COL_PAY_INTEREST_PAYMENT] or 0.0)
            remaining = float(r[COL_PAY_REMAINING] or 0.0)
            paid = r[COL_PAY_PAID]
            paid = float(paid) if pd.notna(paid) else None
            status = str(r[COL_PAY_STATUS] or "").strip()
            payment_type = str(r[COL_PAY_TYPE] or "").strip()
            is_regular = payment_type == PAYMENT_TYPE_REGULAR

            calc_amount = round(principal_payment + interest_payment, ROUNDING_DECIMALS)
            amount_diff = round(amount - calc_amount, ROUNDING_DECIMALS)
            # amount == principal+interest only holds for 'regular' installment rows
            amount_match = (not is_regular) or abs(amount_diff) <= TOLERANCE

            status_ok = not (status == PAYMENT_STATUS_COMPLETED and paid is None)

            remaining_increased = (
                is_regular
                and prev_remaining is not None
                and remaining > prev_remaining + TOLERANCE
            )

            rows.append(
                PaymentCheckRow(
                    contract_id=contract.id,
                    contract_num=contract.num,
                    client_id=contract.client_id,
                    client_name=contract.client_name,
                    payment_id=int(r[COL_PAY_ID]),
                    payment_date=r["_date"],
                    payment_type=payment_type,
                    status=status,
                    amount=amount,
                    principal_payment=principal_payment,
                    interest_payment=interest_payment,
                    calc_amount=calc_amount,
                    amount_diff=amount_diff,
                    amount_match=amount_match,
                    paid=paid,
                    status_ok=status_ok,
                    remaining=remaining,
                    prev_remaining=prev_remaining if is_regular else None,
                    remaining_increased=remaining_increased,
                    is_match=amount_match and status_ok and not remaining_increased,
                )
            )
            if is_regular:
                prev_remaining = remaining

    return rows


def _to_date(value) -> Optional[date]:
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return None
    if isinstance(value, date):
        return value
    s = str(value).strip()
    if not s or s.lower() in ("none", "null", "nat"):
        return None
    try:
        return date.fromisoformat(s[:10])
    except ValueError:
        return None
