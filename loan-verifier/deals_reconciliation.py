"""
deals_reconciliation.py — Phase 2: reconcile completed 'regular' payments
against the `deals` rows that actually settled them.

Why positional pairing instead of a date match: `payments.date` is the
*scheduled* due date, while `deals.date` is the *actual* cash date — these
diverge whenever a borrower pays late. One client visit settles exactly one
scheduled installment, in order, so the Nth completed 'regular' payment for
a contract is paired with the Nth settling deal (type='in',
filter_type in {'payment','full_payment','partial_payment'}), both sorted
by date.

What's asserted vs. just surfaced:
  - interest: deal.interest_amount should be >= payments.original_interest_payment
    (it can be higher — extra days of interest accrue when a payment is
    late — but never lower; a lower value is flagged as a real issue).
  - penalty: when deal.penalty > 0, there should be a sibling `payments` row
    with type='penalty' dated on the deal's actual date with a matching
    amount. This is an exact match in every case checked so far.
  - principal: deal.amount does not cleanly decompose into a principal
    figure that ties to payments.original_principal_payment (open question,
    see conversation) — so the difference is only ever reported for manual
    review, never asserted right or wrong.
"""

import logging
from datetime import date
from typing import List, Optional

import pandas as pd

from config import (
    COL_PAY_CONTRACT_ID, COL_PAY_ID, COL_PAY_DATE, COL_PAY_TYPE, COL_PAY_STATUS,
    COL_PAY_ORIGINAL_PRINCIPAL_PAYMENT, COL_PAY_ORIGINAL_INTEREST_PAYMENT,
    COL_PAY_DELETED_AT,
    COL_DEAL_CONTRACT_ID, COL_DEAL_ID, COL_DEAL_DATE, COL_DEAL_TYPE,
    COL_DEAL_AMOUNT, COL_DEAL_INTEREST_AMOUNT, COL_DEAL_PENALTY,
    COL_DEAL_FILTER_TYPE, COL_DEAL_DELETED_AT,
    DEAL_PAYMENT_FILTER_TYPES,
    PAYMENT_TYPE_REGULAR, PAYMENT_TYPE_PENALTY, PAYMENT_STATUS_COMPLETED,
    ROUNDING_DECIMALS, TOLERANCE,
)
from models import Contract, DealReconciliationRow

logger = logging.getLogger(__name__)


def check_deals_reconciliation(
    contracts: List[Contract],
    payments_df: pd.DataFrame,
    deals_df: pd.DataFrame,
    today: date,
) -> List[DealReconciliationRow]:
    """Pair completed 'regular' payments with settling deals, per contract."""
    pay = payments_df.copy()
    pay[COL_PAY_CONTRACT_ID] = pd.to_numeric(pay[COL_PAY_CONTRACT_ID], errors="coerce")
    for col in (COL_PAY_ORIGINAL_PRINCIPAL_PAYMENT, COL_PAY_ORIGINAL_INTEREST_PAYMENT):
        pay[col] = pd.to_numeric(pay[col], errors="coerce")
    pay["parsed_date"] = pay[COL_PAY_DATE].apply(_to_date)

    deals = deals_df.copy()
    deals[COL_DEAL_CONTRACT_ID] = pd.to_numeric(deals[COL_DEAL_CONTRACT_ID], errors="coerce")
    for col in (COL_DEAL_AMOUNT, COL_DEAL_INTEREST_AMOUNT, COL_DEAL_PENALTY):
        deals[col] = pd.to_numeric(deals[col], errors="coerce")
    deals["parsed_date"] = deals[COL_DEAL_DATE].apply(_to_date)

    rows: List[DealReconciliationRow] = []

    for contract in contracts:
        p_mask = (
            (pay[COL_PAY_CONTRACT_ID] == contract.id)
            & (pay[COL_PAY_DELETED_AT].isna())
            & (pay[COL_PAY_TYPE] == PAYMENT_TYPE_REGULAR)
            & (pay[COL_PAY_STATUS] == PAYMENT_STATUS_COMPLETED)
            & pay["parsed_date"].notna()
            & (pay["parsed_date"] <= today)
        )
        contract_payments = pay[p_mask].sort_values(["parsed_date", COL_PAY_ID])

        d_mask = (
            (deals[COL_DEAL_CONTRACT_ID] == contract.id)
            & (deals[COL_DEAL_DELETED_AT].isna())
            & (deals[COL_DEAL_TYPE] == "in")
            & (deals[COL_DEAL_FILTER_TYPE].isin(DEAL_PAYMENT_FILTER_TYPES))
            & deals["parsed_date"].notna()
            & (deals["parsed_date"] <= today)
        )
        contract_deals = deals[d_mask].sort_values(["parsed_date", COL_DEAL_ID])

        # Penalty-type payment rows for this contract, keyed by actual date,
        # used to check deal.penalty against a sibling record.
        penalty_mask = (
            (pay[COL_PAY_CONTRACT_ID] == contract.id)
            & (pay[COL_PAY_DELETED_AT].isna())
            & (pay[COL_PAY_TYPE] == PAYMENT_TYPE_PENALTY)
            & pay["parsed_date"].notna()
        )
        penalty_by_date = {}
        for _, pr in pay[penalty_mask].iterrows():
            penalty_by_date.setdefault(pr["parsed_date"], []).append(pr)

        if contract_payments.empty and contract_deals.empty:
            continue

        n = max(len(contract_payments), len(contract_deals))
        payment_list = list(contract_payments.itertuples())
        deal_list = list(contract_deals.itertuples())

        for i in range(n):
            p_row = payment_list[i] if i < len(payment_list) else None
            d_row = deal_list[i] if i < len(deal_list) else None
            unpaired = p_row is None or d_row is None

            original_principal = getattr(p_row, COL_PAY_ORIGINAL_PRINCIPAL_PAYMENT) if p_row else None
            original_interest = getattr(p_row, COL_PAY_ORIGINAL_INTEREST_PAYMENT) if p_row else None
            payment_id = int(getattr(p_row, COL_PAY_ID)) if p_row else None
            payment_date = getattr(p_row, "parsed_date") if p_row else None

            deal_id = int(getattr(d_row, COL_DEAL_ID)) if d_row else None
            deal_date = getattr(d_row, "parsed_date") if d_row else None
            deal_amount = float(getattr(d_row, COL_DEAL_AMOUNT)) if d_row and pd.notna(getattr(d_row, COL_DEAL_AMOUNT)) else None
            deal_interest = float(getattr(d_row, COL_DEAL_INTEREST_AMOUNT)) if d_row and pd.notna(getattr(d_row, COL_DEAL_INTEREST_AMOUNT)) else None
            deal_penalty = float(getattr(d_row, COL_DEAL_PENALTY)) if d_row and pd.notna(getattr(d_row, COL_DEAL_PENALTY)) else 0.0

            interest_diff = None
            interest_flag = False
            if original_interest is not None and deal_interest is not None:
                interest_diff = round(deal_interest - float(original_interest), ROUNDING_DECIMALS)
                interest_flag = interest_diff < -TOLERANCE  # deal shows LESS interest than recorded paid

            principal_diff = None
            if original_principal is not None and deal_amount is not None and deal_interest is not None:
                implied_principal = deal_amount - deal_interest - deal_penalty
                principal_diff = round(implied_principal - float(original_principal), ROUNDING_DECIMALS)

            penalty_payment_id = None
            penalty_payment_amount = None
            penalty_match = True
            if deal_penalty and deal_penalty > TOLERANCE and deal_date is not None:
                candidates = penalty_by_date.get(deal_date, [])
                match = next(
                    (c for c in candidates if abs(float(getattr(c, "amount", 0) or 0) - deal_penalty) <= TOLERANCE),
                    None,
                )
                if match is not None:
                    penalty_payment_id = int(getattr(match, COL_PAY_ID))
                    penalty_payment_amount = float(getattr(match, "amount"))
                    penalty_match = True
                else:
                    penalty_match = False

            rows.append(
                DealReconciliationRow(
                    contract_id=contract.id,
                    contract_num=contract.num,
                    client_id=contract.client_id,
                    client_name=contract.client_name,
                    sequence_index=i + 1,
                    payment_id=payment_id,
                    payment_date=payment_date,
                    deal_id=deal_id,
                    deal_date=deal_date,
                    original_interest_payment=float(original_interest) if original_interest is not None else None,
                    deal_interest_amount=deal_interest,
                    interest_diff=interest_diff,
                    interest_flag=interest_flag,
                    original_principal_payment=float(original_principal) if original_principal is not None else None,
                    deal_amount=deal_amount,
                    deal_penalty=deal_penalty,
                    principal_diff=principal_diff,
                    penalty_payment_id=penalty_payment_id,
                    penalty_payment_amount=penalty_payment_amount,
                    penalty_match=penalty_match,
                    unpaired=unpaired,
                )
            )

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
