"""
schedule_engine.py — Independent reimplementation of the loan-software's
amortization schedule builders, payment recompute, and penalty formula.

Every function here mirrors a specific piece of the Laravel backend
(DC_New_backend) exactly, so the numbers can be cross-checked against what
the software actually stored instead of approximated:

  app/Services/ContractService.php
      buildAnnuitySchedule()              -> build_legacy_schedule()
      computeBrokenPeriodAnnuitySchedule() -> build_broken_period_schedule()
      getNextWorkingDay()                  -> next_working_day()
      excelPmt()                           -> excel_pmt()
  app/Services/PaymentService.php
      recalculateAmortizedInterestFromSchedule() -> recompute_open_rows()
  app/Traits/ContractTrait.php
      countPenalty()                       -> count_penalty()
      calcAmount()                         -> simple_interest()

Confirmed against real data (2026-07-29): both schedule builders reproduce
their respective DB source (contracts.payment_schedule / payments table) to
the cent for contracts 15 and 94. No fee/kasko handling — this portfolio
never uses either (confirmed by user).
"""

import calendar
from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Dict, List, Optional, Tuple

HOLIDAYS_MMDD = {
    "01-01", "01-02", "01-06", "01-27", "01-28", "03-08", "04-24", "05-01",
    "05-09", "05-28", "07-05", "09-21", "12-31",
}


def next_working_day(d: date) -> date:
    """Skip weekends and the fixed Armenian holiday list, forward only."""
    while d.strftime("%m-%d") in HOLIDAYS_MMDD or d.weekday() >= 5:
        d += timedelta(days=1)
    return d


def add_months(d: date, n: int) -> date:
    """Carbon's addMonths()/addMonthsNoOverflow(): clamps to the shorter month."""
    month_index = d.month - 1 + n
    year = d.year + month_index // 12
    month = month_index % 12 + 1
    day = min(d.day, calendar.monthrange(year, month)[1])
    return date(year, month, day)


def excel_pmt(rate: float, nper: int, pv: float) -> float:
    """Excel PMT, type=0, fv=0 — the fixed installment amount for an annuity."""
    if abs(rate) < 1e-12:
        return -pv / nper
    pw = (1 + rate) ** nper
    return -(rate * (pv * pw)) / (pw - 1)


def simple_interest(amount: float, days: float, rate_pct: float) -> float:
    """calcAmount(amount, days, rate) as used by countPenalty: days*rate/100*amount."""
    return days * rate_pct / 100 * amount


@dataclass
class ScheduleRow:
    seq: int
    due_date: date
    days: int
    interest: float
    principal: float
    amount: float
    balance_after: float
    is_broken_period: bool = False


def build_legacy_schedule(
    loan_amount: float,
    interest_rate_pct: float,
    months: int,
    start_date: date,
) -> List[ScheduleRow]:
    """
    Mirrors ContractService::buildAnnuitySchedule() — the path used when
    payment_day is not honoured (confirmed: this is what the `payments`
    table itself reflects, even for the payment_day-bearing contract 94).
    Fixed monthly PMT sized off a nominal 30.4-day month; interest recomputed
    each row off the REAL calendar days between working-day-adjusted dates.
    """
    annual_pct = interest_rate_pct * 365
    monthly_rate = annual_pct / 100 / 12
    pmt = -excel_pmt(monthly_rate, months, loan_amount)

    rows: List[ScheduleRow] = []
    balance = loan_amount
    for i in range(1, months + 1):
        is_last = i == months
        prev_raw = add_months(start_date, i - 1)
        raw_payment = add_months(start_date, i)
        prev_pay = next_working_day(prev_raw)
        payment_date = next_working_day(raw_payment)
        days_prev = prev_raw if i == 1 else prev_pay
        days_in_period = (payment_date - days_prev).days

        interest = days_in_period * (interest_rate_pct / 100) * balance
        principal = pmt - interest
        amount = pmt
        balance_after = balance - principal

        if is_last:
            amount = pmt + balance_after
            principal = principal + balance_after
            balance_after = 0.0

        rows.append(ScheduleRow(
            seq=i, due_date=payment_date, days=days_in_period,
            interest=round(interest, 2), principal=round(principal, 2),
            amount=round(amount, 2), balance_after=round(balance_after, 2),
        ))
        balance = balance_after
    return rows


def build_broken_period_schedule(
    loan_amount: float,
    interest_rate_pct: float,
    months: int,
    disbursement_date: date,
    payment_day: int,
) -> List[ScheduleRow]:
    """
    Mirrors ContractService::computeBrokenPeriodAnnuitySchedule() — the path
    used only when payment_day is set AND actually honoured. Fixed monthly
    rate (no real day-count per row beyond the broken first period).
    """
    annual_pct = interest_rate_pct * 365
    monthly_rate = annual_pct / 100 / 12
    pmt = -excel_pmt(monthly_rate, months, loan_amount)

    if disbursement_date.day < payment_day:
        first_calendar = disbursement_date.replace(day=payment_day)
    else:
        first_calendar = add_months(disbursement_date, 1)
        first_calendar = first_calendar.replace(day=min(payment_day, calendar.monthrange(first_calendar.year, first_calendar.month)[1]))

    broken_days = (first_calendar - disbursement_date).days
    rows: List[ScheduleRow] = []
    balance = loan_amount
    seq = 0

    if broken_days > 0:
        daily_rate = interest_rate_pct / 100.0
        broken_interest = round(loan_amount * daily_rate * broken_days, 2)
        biz_date = next_working_day(first_calendar)
        seq += 1
        rows.append(ScheduleRow(
            seq=seq, due_date=biz_date, days=broken_days,
            interest=broken_interest, principal=0.0, amount=broken_interest,
            balance_after=loan_amount, is_broken_period=True,
        ))

    for i in range(1, months + 1):
        is_last = i == months
        calendar_date = add_months(first_calendar, i)
        biz_date = next_working_day(calendar_date)

        interest = round(balance * monthly_rate, 2)
        if is_last:
            principal = balance
            amount = round(principal + interest, 2)
        else:
            principal = round(pmt - interest, 2)
            amount = round(pmt, 2)
        balance = round(balance - principal, 2)

        seq += 1
        rows.append(ScheduleRow(
            seq=seq, due_date=biz_date, days=(calendar_date - add_months(first_calendar, i - 1)).days,
            interest=interest, principal=principal, amount=amount,
            balance_after=balance,
        ))
    return rows


@dataclass
class RealPayment:
    date: date
    principal: float = 0.0
    interest: float = 0.0
    penalty: float = 0.0
    source_deal_id: Optional[int] = None


@dataclass
class LedgerEntry:
    date: date
    principal: float
    interest: float
    penalty: float
    balance_after: float


def replay_real_payments(initial_balance: float, real_payments: List[RealPayment]) -> List[LedgerEntry]:
    """
    Walk the real cash trail (from deals/documents_journal) against the
    disbursed balance. This is the ground truth for "what the remaining
    principal debt should be right now" — independent of anything the
    `payments` or `contracts` tables currently say.
    """
    balance = initial_balance
    ledger: List[LedgerEntry] = []
    for p in sorted(real_payments, key=lambda x: x.date):
        balance = round(balance - p.principal, 2)
        if balance < 0:
            balance = 0.0
        ledger.append(LedgerEntry(
            date=p.date, principal=p.principal, interest=p.interest,
            penalty=p.penalty, balance_after=balance,
        ))
    return ledger


def _cascade(remaining: List[float], dated_amounts: List[Tuple[date, float]]) -> Tuple[int, List[float], List[Optional[date]]]:
    """
    Consumes `remaining[idx]` in order with each (date, amount) pair: an
    amount first clears the current row's own remaining figure; any excess
    rolls forward and is deducted from the NEXT row's figure directly —
    cascading further still if the excess is larger than that row's own
    figure too, potentially spanning several real payments before a row is
    actually cleared (a row's own payment can underpay it, leaving a small
    remainder that only gets cleared — with a large excess rolling further
    forward — by a LATER payment). Mutates `remaining` in place.

    Returns (completed_count, overflow_out, overflow_source_date) —
    completed_count is the number of rows fully cleared; overflow_out[i] is
    how much rolled OUT of row i into row i+1 (0.0 if row i was never
    overpaid); overflow_source_date[i] is the date of the real payment whose
    excess actually produced that overflow — which is often NOT row i's own
    due date, since an earlier underpayment can leave a small remainder that
    a later payment clears before rolling its own excess forward.
    """
    idx = 0
    overflow_out = [0.0] * len(remaining)
    overflow_source_date: List[Optional[date]] = [None] * len(remaining)
    for pay_date, amount in dated_amounts:
        amount_to_apply = amount
        while amount_to_apply > 1e-6 and idx < len(remaining):
            row_remaining = remaining[idx]
            if amount_to_apply >= row_remaining - 1e-6:
                overflow = round(amount_to_apply - row_remaining, 2)
                if overflow > 1e-6:
                    overflow_out[idx] = round(overflow_out[idx] + overflow, 2)
                    overflow_source_date[idx] = pay_date
                amount_to_apply = overflow
                remaining[idx] = 0.0
                idx += 1
            else:
                remaining[idx] = round(row_remaining - amount_to_apply, 2)
                amount_to_apply = 0.0
    return idx, overflow_out, overflow_source_date


@dataclass
class CascadeTouch:
    """One (payment, installment) touch — a single payment can touch more
    than one installment (finishing off a shortfall from a prior payment,
    then overflowing into the next), and a single installment can be
    touched by more than one payment (an underpayment leaves it partially
    open for the next payment to finish). This is the atomic fact that
    resolves "which payment produced this row's overflow" without forcing a
    row-level or payment-level view to guess at the other axis."""
    installment_due_date: date
    applied: float
    row_remaining_before: float
    fully_cleared: bool
    overflow_after: float


def _cascade_with_trace(remaining: List[float], due_dates: List[date], dated_amounts: List[Tuple[date, float]]) -> List[Dict]:
    """
    Same consumption logic as _cascade(), but grouped by PAYMENT instead of
    by row: for each real payment, returns the ordered list of installments
    it touched (usually one, sometimes several — one that it finishes off
    plus the start of the next). This is the natural unit for explaining
    "how much of payment X went where", instead of a row-level view that
    has to point backwards at whichever payment happened to clear it.
    """
    idx = 0
    trace = []
    for pay_date, amount in dated_amounts:
        amount_to_apply = amount
        touches: List[CascadeTouch] = []
        while amount_to_apply > 1e-6 and idx < len(remaining):
            row_remaining = remaining[idx]
            applied = round(min(amount_to_apply, row_remaining), 2)
            cleared = amount_to_apply >= row_remaining - 1e-6
            overflow = round(amount_to_apply - row_remaining, 2) if cleared else 0.0
            touches.append(CascadeTouch(
                installment_due_date=due_dates[idx], applied=applied,
                row_remaining_before=round(row_remaining, 2),
                fully_cleared=cleared, overflow_after=max(0.0, overflow),
            ))
            if cleared:
                amount_to_apply = overflow
                remaining[idx] = 0.0
                idx += 1
            else:
                remaining[idx] = round(row_remaining - amount_to_apply, 2)
                amount_to_apply = 0.0
        trace.append({"payment_date": pay_date, "amount": amount, "touches": touches})
    return trace


def apply_real_payments_to_schedule(
    original_rows: List[ScheduleRow],
    real_payments: List[RealPayment],
) -> Tuple[int, List[float], List[float], List[float], List[float], List[Optional[date]], List[Optional[date]]]:
    """
    Cascades each real payment's principal AND interest against the
    ORIGINAL schedule's rows in order, mirroring how the backend applies a
    real payment: it first clears the current row's own remaining
    principal/interest; any excess beyond that rolls forward and reduces
    the NEXT row's principal/interest directly — cascading further still
    if the excess is larger than that row's own figure too.

    Verified against contract 4:
    - Principal: row 1 owed 12,076.94; the first real payment's principal
      was 13,190.00 (1,113.06 over). Row 2's scheduled principal (4,265.33)
      minus that overflow = 3,152.27 — exactly the second real payment's
      principal, to the cent.
    - Interest: cascading the same way through all 4 real payments lands
      row 5's interest at 46,687.10 vs. the live system's 46,644.67 — a
      ~42 AMD (~0.09%) residual, far closer than treating interest as a
      fresh balance-recompute (which was off by ~4,852).

    Returns (completed_count, adjusted_principals, adjusted_interests,
    principal_overflow_out, interest_overflow_out, principal_overflow_source,
    interest_overflow_source) — the adjusted_* lists have one float per row
    in `original_rows` (unchanged for rows the cascade never reached,
    reduced for the row an overflow landed on, 0.0 for fully settled rows);
    the overflow_out lists show how much rolled OUT of each row into the
    next one; the overflow_source lists show which real payment's date
    actually produced that overflow — NOT necessarily that row's own due
    date (an earlier underpayment can leave a small remainder that only a
    LATER payment clears before rolling its own excess further forward — see
    contract 2: April's payment underpays April's own interest, and it's
    May's payment that both finishes off April's shortfall AND rolls a much
    bigger excess into May's own bucket). completed_count is the smaller of
    the principal- and interest-cascade indices — i.e. how many leading rows
    are fully cleared on BOTH counts (in every contract checked so far the
    two indices land on the same row every time).
    """
    remaining_principal = [r.principal for r in original_rows]
    remaining_interest = [r.interest for r in original_rows]
    payments_sorted = sorted(real_payments, key=lambda x: x.date)
    idx_principal, principal_overflow_out, principal_overflow_source = _cascade(
        remaining_principal, [(p.date, p.principal) for p in payments_sorted]
    )
    idx_interest, interest_overflow_out, interest_overflow_source = _cascade(
        remaining_interest, [(p.date, p.interest) for p in payments_sorted]
    )
    completed_count = min(idx_principal, idx_interest)
    return (
        completed_count, remaining_principal, remaining_interest,
        principal_overflow_out, interest_overflow_out,
        principal_overflow_source, interest_overflow_source,
    )


def build_payment_ledger(original_rows: List[ScheduleRow], real_payments: List[RealPayment]) -> List[Dict]:
    """
    One entry per REAL PAYMENT (not per installment) — the natural unit for
    explaining where a payment's money went, since a row-per-installment
    view has to point backwards at whichever payment happened to clear it
    (confusing when that's a different payment than the one due that
    month). Each entry has the payment's own principal/interest touches:
    which installment(s) it applied to, how much of each installment's OWN
    need was still outstanding before this payment, whether it fully
    cleared that installment, and how much (if any) overflowed into the
    next one — all attributed to the payment that actually caused it.
    """
    due_dates = [r.due_date for r in original_rows]
    remaining_principal = [r.principal for r in original_rows]
    remaining_interest = [r.interest for r in original_rows]
    payments_sorted = sorted(real_payments, key=lambda x: x.date)

    principal_trace = _cascade_with_trace(remaining_principal, due_dates, [(p.date, p.principal) for p in payments_sorted])
    interest_trace = _cascade_with_trace(remaining_interest, due_dates, [(p.date, p.interest) for p in payments_sorted])

    ledger = []
    for p_entry, i_entry in zip(principal_trace, interest_trace):
        ledger.append({
            "date": p_entry["payment_date"],
            "principal_paid": p_entry["amount"],
            "principal_touches": p_entry["touches"],
            "interest_paid": i_entry["amount"],
            "interest_touches": i_entry["touches"],
        })
    return ledger


def recompute_open_rows(
    original_rows: List[ScheduleRow],
    starting_balance: float,
    real_payments: List[RealPayment],
    interest_rate_pct: Optional[float] = None,
    balance_before_last_payment: Optional[float] = None,
) -> Tuple[int, List[ScheduleRow]]:
    """
    "Still open" is determined by how many installments have actually been
    fully settled by real cash (via apply_real_payments_to_schedule's
    cascade), NOT by comparing due dates to today — an installment can be
    overdue (due date already passed) and still unpaid, and a naive
    "due_date > as_of" filter would wrongly drop it.

    Each open row keeps its OWN day-count from the original schedule (mirrors
    `payments.from_date`, fixed at schedule creation). Its PRINCIPAL reflects
    any cascade overflow from the real payments applied so far; rows the
    cascade never reached keep their pure original scheduled values, exactly
    as they were built at origination.

    Late-payment correction: the real payment date that finally cleared cash
    through `completed_count` splits ITS SPAN's interest into two balances —
    days late still accrue on the balance as it stood BEFORE that payment,
    the rest of the days accrue on the balance AFTER it. That split only
    makes sense for whichever row's own [prev_due, next_due] span actually
    CONTAINS the last real payment date — usually completed_count itself,
    but a row can be cash-wise "still open" while its own due date has
    already passed (see above: overdue rows stay open), in which case the
    payment lands after that row's span has already fully elapsed entirely
    at the OLD balance, and the true straddle point is in a LATER row's
    span instead. We walk forward from completed_count to find it, rather
    than assuming it's always the first open row — every row after that one
    keeps using the plain schedule day-count untouched, since only one real
    payment date exists to measure a split against.

    Verified against contract 4: row 4 (due 2026-06-25) was paid 4 days late
    on 2026-06-29 (balance before: 1,471,017.96; balance after: 1,462,964.25).
    Row 5's interest = 1,471,017.96×0.11%×4 + 1,462,964.25×0.11%×28 =
    51,531.76, minus the 4,809.24 the plain dollar-cascade already carried
    forward from earlier rows = 46,722.52 (vs. 46,687.10 without this
    correction, and the live system's 46,644.67).

    Returns (completed_count, recomputed_open_rows).
    """
    completed_count, adjusted_principals, adjusted_interests, _, interest_overflow_out, _, _ = apply_real_payments_to_schedule(original_rows, real_payments)

    if (
        interest_rate_pct is not None
        and balance_before_last_payment is not None
        and real_payments
        and 0 < completed_count < len(original_rows)
    ):
        last_payment_date = max(p.date for p in real_payments)
        split_idx = completed_count
        while split_idx < len(original_rows) and original_rows[split_idx].due_date < last_payment_date:
            split_idx += 1
        if split_idx < len(original_rows):
            prev_due_date = original_rows[split_idx - 1].due_date
            next_due_date = original_rows[split_idx].due_date
            late_days = (last_payment_date - prev_due_date).days
            if late_days > 0:
                rest_days = (next_due_date - last_payment_date).days
                late_split = (
                    simple_interest(balance_before_last_payment, late_days, interest_rate_pct)
                    + simple_interest(starting_balance, rest_days, interest_rate_pct)
                )
                # Net off EVERYTHING the cascade already applied to this row's own
                # interest need — not just overflow rolled in from the previous row.
                # A real payment can land exactly on split_idx's own due date and pay
                # it down directly (see contract 2: the 06-30 payment paid split_idx's
                # interest straight, with zero overflow from the prior row) — using
                # only interest_overflow_out[split_idx-1] in that case ignored the
                # direct payment entirely and re-displayed already-paid interest as
                # still outstanding.
                already_applied = round(original_rows[split_idx].interest - adjusted_interests[split_idx], 2)
                adjusted_interests[split_idx] = max(0.0, round(late_split - already_applied, 2))

    recomputed: List[ScheduleRow] = []
    balance = starting_balance
    rows_zip = zip(
        original_rows[completed_count:],
        adjusted_principals[completed_count:],
        adjusted_interests[completed_count:],
    )
    for row, principal, interest in rows_zip:
        amount = round(interest + principal, 2)
        balance_after = round(max(0.0, balance - principal), 2)
        recomputed.append(ScheduleRow(
            seq=row.seq, due_date=row.due_date, days=row.days,
            interest=interest, principal=principal, amount=amount,
            balance_after=balance_after,
        ))
        balance = balance_after
    return completed_count, recomputed


def count_penalty(
    open_installments: List[Dict],
    penalty_rate_pct: float,
    as_of: date,
) -> Dict:
    """
    Mirrors ContractTrait::countPenalty(): per still-open installment whose
    due date has passed, penalty accrues on that installment's OWN
    outstanding `amount` from (due_date, or the last penalty payment against
    it if later) to `as_of`, simple daily rate, minus any penalty already
    paid against it.

    open_installments: [{due_date, amount, penalty_paid, last_penalty_paid_date}]
    """
    total_penalty = 0.0
    max_delay_days = 0
    detail = []
    for inst in open_installments:
        due_date = inst["due_date"]
        penalty_start = due_date
        last_paid_date = inst.get("last_penalty_paid_date")
        if last_paid_date and last_paid_date > penalty_start:
            penalty_start = last_paid_date

        if as_of <= penalty_start:
            continue

        delay_days = (as_of - penalty_start).days
        max_delay_days = max(max_delay_days, delay_days)
        current_penalty = simple_interest(inst["amount"], delay_days, penalty_rate_pct)
        penalty_paid = inst.get("penalty_paid", 0.0)
        row_penalty = current_penalty - penalty_paid
        total_penalty += row_penalty
        detail.append({
            "due_date": due_date, "amount": inst["amount"], "delay_days": delay_days,
            "accrued": round(current_penalty, 2), "paid": penalty_paid,
            "outstanding": round(row_penalty, 2),
        })

    return {
        "penalty_amount": round(max(0.0, total_penalty), 2),
        "delay_days": max_delay_days,
        "detail": detail,
    }
