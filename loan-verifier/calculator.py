"""
calculator.py — Balance reconstruction and interest calculation logic.

Nominal interest:  balance × (daily_rate / 100)   — simple, no compounding
Effective interest: iterative compounding with principal reductions applied at
                    actual payment dates.
"""

import json
import logging
from datetime import date, timedelta
from typing import Dict, List, Optional, Tuple

import pandas as pd

from config import (
    COL_CAH_CONTRACT_ID, COL_CAH_AMOUNT_TYPE, COL_CAH_AMOUNT, COL_CAH_TYPE,
    COL_CAH_DATE, COL_CAH_DELETED_AT,
    CAH_AMOUNT_TYPE_PRINCIPAL,
    COL_PAY_CONTRACT_ID, COL_PAY_DATE, COL_PAY_TYPE, COL_PAY_STATUS,
    COL_PAY_DELETED_AT,
    PAYMENT_TYPE_REGULAR,
    COL_DEAL_CONTRACT_ID, COL_DEAL_DATE, COL_DEAL_TYPE, COL_DEAL_AMOUNT,
    COL_DEAL_INTEREST_AMOUNT, COL_DEAL_PENALTY, COL_DEAL_FILTER_TYPE, COL_DEAL_DELETED_AT,
    DEAL_PAYMENT_FILTER_TYPES,
    ROUNDING_DECIMALS,
    TOLERANCE,
)
from models import Contract, BalanceChange, InterestRecord, VerificationRow

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Balance reconstruction
# ---------------------------------------------------------------------------

def get_effective_reductions_from_deals(
    contract: Contract,
    deals_df: pd.DataFrame,
) -> List[Tuple[date, float]]:
    """
    Return a sorted list of (date, amount) reductions for the effective balance.

    Penalties (Տուգանքները) are excluded: only amount - penalty is deducted,
    since penalties do not reduce the outstanding principal/interest base.
    """
    mask = (
        (deals_df[COL_DEAL_CONTRACT_ID] == contract.id)
        & (deals_df[COL_DEAL_TYPE] == "in")
        & (deals_df[COL_DEAL_FILTER_TYPE].isin(DEAL_PAYMENT_FILTER_TYPES))
        & (deals_df[COL_DEAL_DELETED_AT].isna())
    )
    reductions: List[Tuple[date, float]] = []
    for _, row in deals_df[mask].iterrows():
        d = _to_date(row[COL_DEAL_DATE])
        if d is None:
            continue
        amount = float(row[COL_DEAL_AMOUNT] or 0)
        penalty = float(row[COL_DEAL_PENALTY] or 0) if COL_DEAL_PENALTY in deals_df.columns else 0.0
        amount -= penalty
        if amount > 0:
            reductions.append((d, amount))
    reductions.sort(key=lambda x: x[0])
    return reductions


def get_opening_balance(
    contract: Contract,
    interest_date: date,
    cah_df: pd.DataFrame,
) -> float:
    """
    Reconstruct the nominal opening balance for a given interest_date.

    Uses contract_amount_histories exclusively:
      balance = sum(CAH IN before date) - sum(CAH OUT before date)

    Strict < ensures a payment on the same day does not reduce that day's balance.
    """
    cah_mask = (
        (cah_df[COL_CAH_CONTRACT_ID] == contract.id)
        & (cah_df[COL_CAH_AMOUNT_TYPE] == CAH_AMOUNT_TYPE_PRINCIPAL)
        & (cah_df[COL_CAH_DELETED_AT].isna())
    )
    balance = 0.0
    for _, row in cah_df[cah_mask].iterrows():
        d = _to_date(row[COL_CAH_DATE])
        if d is None or d >= interest_date:
            continue
        amount = float(row[COL_CAH_AMOUNT])
        if row[COL_CAH_TYPE] == "in":
            balance += amount
        else:
            balance -= amount
    return max(balance, 0.0)


# ---------------------------------------------------------------------------
# Nominal interest
# ---------------------------------------------------------------------------

def calculate_nominal_interest(balance: float, daily_rate_pct: float) -> float:
    """balance × (daily_rate_pct / 100), rounded to ROUNDING_DECIMALS."""
    return round(balance * daily_rate_pct / 100, ROUNDING_DECIMALS)


# ---------------------------------------------------------------------------
# Effective interest
# ---------------------------------------------------------------------------

def calculate_effective_interest(
    contract: Contract,
    interest_date: date,
    cah_df: pd.DataFrame,
    deals_df: pd.DataFrame,
) -> float:
    """
    Compute effective interest for a single day using iterative compounding.

    The effective balance compounds daily from the disbursement date.
    Principal reductions come from the deals table (actual cash received from
    the borrower), so the compounding balance accurately reflects what was
    outstanding each day.

    For multi-tranche contracts, each additional CAH IN is added to the
    effective balance on the date it was disbursed, before compounding.

    interest(day N) = EB(N-1) × (effective_daily_rate / 100)

    where EB starts at the initial disbursement amount and compounds as:
        EB(d) = EB(d-1) + extra_disbursement(d) — on additional tranche days
        EB(d) = EB(d-1) × (1 + rate) — on non-reduction days
        EB(d) = EB(d-1) × (1 + rate) - reduction — on reduction days
    """
    if contract.provided_at is None:
        return 0.0

    rate = contract.effective_daily_rate / 100
    start = contract.provided_at
    reductions = get_effective_reductions_from_deals(contract, deals_df)

    # Build a dict: date → total reduction on that date
    reduction_map: Dict[date, float] = {}
    for d, amt in reductions:
        reduction_map[d] = reduction_map.get(d, 0.0) + amt

    # Build a dict: date → additional disbursement amount (2nd tranche, 3rd, …)
    extra_map: Dict[date, float] = {}
    for d, amt in _get_additional_disbursements(contract, cah_df):
        extra_map[d] = extra_map.get(d, 0.0) + amt

    # Walk from disbursement date up to (but not including) interest_date
    # to build EB(interest_date - 1 day), then multiply by rate.
    eb = _initial_disbursement(contract, cah_df)
    if eb <= 0:
        return 0.0

    current = start
    while current < interest_date:
        # Add any additional tranche disbursed on this day
        if current in extra_map:
            eb += extra_map[current]
        # Apply reduction BEFORE compounding if it falls on this day
        if current in reduction_map:
            eb -= reduction_map[current]
            eb = max(eb, 0.0)
        eb *= (1 + rate)
        current += timedelta(days=1)

    # EB is now the compounded balance at end of (interest_date - 1).
    # Interest for interest_date = EB(interest_date-1) × rate.
    # But we've already multiplied by (1+rate) above — divide out the +1:
    eb_prev = eb / (1 + rate)
    interest = eb_prev * rate
    return round(interest, ROUNDING_DECIMALS)


def _initial_disbursement(contract: Contract, cah_df: pd.DataFrame) -> float:
    """Return the first CAH IN amount for this contract (initial principal)."""
    mask = (
        (cah_df[COL_CAH_CONTRACT_ID] == contract.id)
        & (cah_df[COL_CAH_AMOUNT_TYPE] == CAH_AMOUNT_TYPE_PRINCIPAL)
        & (cah_df[COL_CAH_TYPE] == "in")
        & (cah_df[COL_CAH_DELETED_AT].isna())
    )
    rows = cah_df[mask]
    if rows.empty:
        return contract.provided_amount or 0.0
    # Use the earliest disbursement as the initial effective balance
    amounts = []
    for _, row in rows.iterrows():
        d = _to_date(row[COL_CAH_DATE])
        amounts.append((d, float(row[COL_CAH_AMOUNT])))
    amounts.sort(key=lambda x: (x[0] or date.min))
    return amounts[0][1]


def _get_additional_disbursements(
    contract: Contract,
    cah_df: pd.DataFrame,
) -> List[Tuple[date, float]]:
    """
    Return all CAH IN principal entries after the first, sorted by date.

    These are the extra tranches disbursed on top of the initial principal.
    Each entry is (disbursement_date, amount).
    """
    mask = (
        (cah_df[COL_CAH_CONTRACT_ID] == contract.id)
        & (cah_df[COL_CAH_AMOUNT_TYPE] == CAH_AMOUNT_TYPE_PRINCIPAL)
        & (cah_df[COL_CAH_TYPE] == "in")
        & (cah_df[COL_CAH_DELETED_AT].isna())
    )
    rows = cah_df[mask]
    if rows.empty:
        return []
    amounts: List[Tuple[date, float]] = []
    for _, row in rows.iterrows():
        d = _to_date(row[COL_CAH_DATE])
        if d is not None:
            amounts.append((d, float(row[COL_CAH_AMOUNT])))
    amounts.sort(key=lambda x: x[0])
    return amounts[1:]  # skip the first (already handled by _initial_disbursement)


# ---------------------------------------------------------------------------
# Verification logic
# ---------------------------------------------------------------------------

def verify_contract(
    contract: Contract,
    interest_records: List[InterestRecord],
    cah_df: pd.DataFrame,
    deals_df: pd.DataFrame,
    today: date,
    date_from: date = None,
) -> List[VerificationRow]:
    """
    Calculate nominal and effective interest for every day from the day after
    disbursement up to today (or date_from→today when a range is specified),
    comparing against stored transaction records.

    Days with no stored record are included with has_stored_record=False so
    gaps in the software's accruals are visible in the report.
    """
    if contract.provided_at is None:
        return []

    # Build lookup: (date, record_type) -> stored_amount
    stored: dict = {}
    for rec in interest_records:
        if rec.contract_id == contract.id:
            stored[(rec.record_date, rec.record_type)] = rec.stored_amount

    end_date = min(contract.closed_at, today) if contract.closed_at else today

    rows: List[VerificationRow] = []
    start_date = contract.provided_at + timedelta(days=1)
    if date_from is not None:
        start_date = max(start_date, date_from)
    current = start_date

    while current <= end_date:
        balance = get_opening_balance(contract, current, cah_df)

        for record_type, interest_label in (("nominal", "Nominal"), ("effective", "Effective")):
            if record_type == "nominal":
                calculated = calculate_nominal_interest(balance, contract.interest_rate)
                rate_used = contract.interest_rate
            else:
                calculated = calculate_effective_interest(
                    contract, current, cah_df, deals_df
                )
                rate_used = contract.effective_daily_rate

            stored_amount = stored.get((current, record_type))
            has_stored = stored_amount is not None
            stored_val = stored_amount if has_stored else 0.0
            difference = round(calculated - stored_val, ROUNDING_DECIMALS)
            is_match = has_stored and abs(difference) <= TOLERANCE

            rows.append(
                VerificationRow(
                    contract_id=contract.id,
                    contract_num=contract.num,
                    client_id=contract.client_id,
                    client_name=contract.client_name,
                    record_date=current,
                    interest_type=interest_label,
                    opening_balance=round(balance, ROUNDING_DECIMALS),
                    daily_rate_pct=rate_used,
                    calculated_interest=calculated,
                    stored_interest=stored_val,
                    difference=difference,
                    is_match=is_match,
                    has_stored_record=has_stored,
                )
            )

        current += timedelta(days=1)

    return rows


# ---------------------------------------------------------------------------
# Utility
# ---------------------------------------------------------------------------

def _to_date(value) -> Optional[date]:
    """Convert a string or date-like value to a date object, or None."""
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
