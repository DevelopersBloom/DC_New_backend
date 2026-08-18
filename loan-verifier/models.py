"""
models.py — Data structures used throughout the verification script.
"""

from dataclasses import dataclass, field
from datetime import date
from typing import Optional, List, Dict, Any


@dataclass
class Contract:
    """One row from the contracts table."""
    id: int
    num: str                            # Contract number e.g. "A-26-00001"
    client_id: int
    client_name: str                    # Joined from clients table
    estimated_amount: float             # Face/contracted amount — basis for interest
    provided_amount: float              # Actual cash disbursed (after lump fee)
    interest_rate: float                # Nominal daily rate in % (0.11 = 0.11%/day)
    effective_daily_rate: float         # Effective daily rate in %
    status: str                         # 'initial', 'completed', 'executed'
    provided_at: Optional[date]         # Disbursement date
    deadline: Optional[date]            # Maturity/end date
    closed_at: Optional[date]           # Date the loan was fully closed (None = still open)
    payment_type: str                   # 'classic' or 'amortized'
    payment_schedule: List[Dict[str, Any]] = field(default_factory=list)
    # payment_schedule is the parsed JSON array from the contracts table.
    # Each entry looks like:
    #   {"date": "2026-03-23", "principal": 24181.117, "interest": 11710.417, "balance": 325818.883}


@dataclass
class InterestRecord:
    """One interest accrual entry from the transactions table."""
    transaction_id: int
    contract_id: int                    # Extracted from comment via regex
    record_date: date                   # The date this interest applies to
    record_type: str                    # 'nominal' or 'effective'
    stored_amount: float                # AMD amount stored by the software


@dataclass
class BalanceChange:
    """One row from contract_amount_histories (provided_amount type only)."""
    contract_id: int
    change_date: date
    amount: float
    direction: str                      # 'in' (disbursement) or 'out' (repayment)


@dataclass
class ScheduledPayment:
    """One row from the payments table."""
    payment_id: int
    contract_id: int
    payment_date: date
    payment_type: str                   # 'regular', 'partial_payment', 'full_payment', etc.
    status: str                         # 'completed' or 'initial'
    paid: Optional[float]               # NULL means not yet paid


@dataclass
class PaymentCheckRow:
    """One row in the payments-table self-consistency report (Phase 1)."""
    contract_id: int
    contract_num: str
    client_id: int
    client_name: str
    payment_id: int
    payment_date: date
    payment_type: str
    status: str
    amount: float                   # payments.amount — current total still due
    principal_payment: float        # payments.principal_payment — current outstanding principal
    interest_payment: float         # payments.interest_payment — current outstanding interest
    calc_amount: float              # principal_payment + interest_payment
    amount_diff: float              # amount - calc_amount
    amount_match: bool              # True if |amount_diff| <= TOLERANCE
    paid: Optional[float]           # payments.paid
    status_ok: bool                 # False if status='completed' but paid IS NULL
    remaining: float                # payments.remaining
    prev_remaining: Optional[float] # remaining of the previous row for this contract
    remaining_increased: bool       # True if remaining rose vs. the previous row (needs review)
    is_match: bool                  # Overall: amount_match and status_ok and not remaining_increased


@dataclass
class DealReconciliationRow:
    """
    One row reconciling a completed 'regular' payment against the deal that
    actually settled it (Phase 2). Payments and deals are paired positionally
    per contract — the Nth completed installment ↔ the Nth settling deal —
    because payments.date is the *scheduled* due date while deals.date is the
    *actual* cash date, so a direct date match misses late payments.
    """
    contract_id: int
    contract_num: str
    client_id: int
    client_name: str
    sequence_index: int              # 1-based pairing position within the contract
    payment_id: Optional[int]        # None if this deal has no paired payment
    payment_date: Optional[date]     # Scheduled due date
    deal_id: Optional[int]           # None if this payment has no paired deal
    deal_date: Optional[date]        # Actual cash date
    original_interest_payment: Optional[float]  # payments.original_interest_payment
    deal_interest_amount: Optional[float]       # deals.interest_amount
    interest_diff: Optional[float]   # deal_interest_amount - original_interest_payment
    interest_flag: bool              # True if deal interest is LESS than recorded paid interest (real issue)
    original_principal_payment: Optional[float]  # payments.original_principal_payment
    deal_amount: Optional[float]                 # deals.amount
    deal_penalty: Optional[float]                # deals.penalty
    principal_diff: Optional[float]  # (deal_amount - deal_interest_amount - deal_penalty) - original_principal_payment
    penalty_payment_id: Optional[int]   # matched sibling payments row of type='penalty', if any
    penalty_payment_amount: Optional[float]
    penalty_match: bool              # True if deal_penalty == 0, or matched exactly to a sibling penalty row
    unpaired: bool                   # True if payment_id or deal_id is missing (sequence-count mismatch)


@dataclass
class VerificationRow:
    """One row in the final verification report."""
    contract_id: int
    contract_num: str
    client_id: int
    client_name: str
    record_date: date
    interest_type: str                  # 'Nominal' or 'Effective'
    opening_balance: float              # Balance used for the calculation
    daily_rate_pct: float               # Rate in % (as stored in contracts table)
    calculated_interest: float          # Our re-calculated value
    stored_interest: float              # Value stored by the software (0.0 if missing)
    difference: float                   # calculated - stored (positive = software under-reports)
    is_match: bool                      # True if |difference| <= TOLERANCE
    has_stored_record: bool = True      # False if no transaction record exists for this day
