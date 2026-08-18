"""
contract_report.py — Assembles the full per-contract validation report:
- two independently rebuilt initial schedules (day-count model vs.
  constant-monthly-rate model — see schedule_engine.py for why both exist)
- what `contracts.payment_schedule` actually stores right now
- what the `payments` table actually stores right now (the live operational
  schedule that drives billing)
- the real cash trail from `deals` -> `documents_journal` (joined via
  deal_id, since documents_journal.contract_id is only ~56% populated)
- a penalty cross-check (countPenalty formula) against contracts.penalty_amount
- a final debt cross-check: replayed balance vs. contracts.left/provided_amount
"""

from datetime import date
from typing import Dict, Optional

import pandas as pd

import config
from schedule_engine import (
    RealPayment,
    apply_real_payments_to_schedule,
    build_broken_period_schedule,
    build_legacy_schedule,
    build_payment_ledger,
    count_penalty,
    recompute_open_rows,
    replay_real_payments,
)


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


def build_contract_report(tables: Dict[str, pd.DataFrame], contract_id: int, as_of: date) -> Dict:
    contracts = tables[config.TABLE_CONTRACTS].copy()
    contracts[config.COL_CONTRACT_ID] = pd.to_numeric(contracts[config.COL_CONTRACT_ID], errors="coerce")
    crow_df = contracts[contracts[config.COL_CONTRACT_ID] == contract_id]
    if crow_df.empty:
        return {"error": f"Contract ID {contract_id} not found in this SQL dump."}
    crow = crow_df.iloc[0]

    clients = tables[config.TABLE_CLIENTS].copy()
    clients[config.COL_CLIENT_ID] = pd.to_numeric(clients[config.COL_CLIENT_ID], errors="coerce")
    client_id = int(crow.get(config.COL_CONTRACT_CLIENT_ID) or 0)
    cl = clients[clients[config.COL_CLIENT_ID] == client_id]
    client_name = f"Client #{client_id}"
    if not cl.empty:
        clr = cl.iloc[0]
        parts = [
            str(clr.get(config.COL_CLIENT_SURNAME) or "").strip(),
            str(clr.get(config.COL_CLIENT_NAME) or "").strip(),
            str(clr.get(config.COL_CLIENT_MIDDLE_NAME) or "").strip(),
        ]
        joined = " ".join(p for p in parts if p)
        if joined:
            client_name = joined

    provided_amount = float(crow.get(config.COL_CONTRACT_PROVIDED_AMOUNT) or 0)
    interest_rate = float(crow.get(config.COL_CONTRACT_INTEREST_RATE) or 0)
    penalty_rate = float(crow.get(config.COL_CONTRACT_PENALTY) or 0)
    deadline_days = int(crow.get(config.COL_CONTRACT_DEADLINE_DAYS) or 0) or 1
    payment_day_raw = crow.get(config.COL_CONTRACT_PAYMENT_DAY)
    payment_day = int(payment_day_raw) if pd.notna(payment_day_raw) else None
    provided_at = _to_date(crow.get(config.COL_CONTRACT_PROVIDED_AT)) or _to_date(crow.get(config.COL_CONTRACT_DATE))
    contract_left = float(crow.get(config.COL_CONTRACT_LEFT) or 0)
    collected_raw = crow.get(config.COL_CONTRACT_COLLECTED)
    contract_collected = float(collected_raw) if pd.notna(collected_raw) else None
    contract_penalty_amount = float(crow.get(config.COL_CONTRACT_PENALTY_AMOUNT) or 0)

    import json as _json
    raw_schedule = crow.get(config.COL_CONTRACT_PAYMENT_SCHEDULE)
    stored_schedule = []
    if raw_schedule and not (isinstance(raw_schedule, float) and pd.isna(raw_schedule)):
        try:
            stored_schedule = _json.loads(str(raw_schedule))
        except ValueError:
            stored_schedule = []

    if provided_at is None:
        info = {
            "id": contract_id, "num": str(crow.get(config.COL_CONTRACT_NUM) or ""),
            "client_id": client_id, "client_name": client_name,
        }
        return {"error": f"Contract {contract_id} has no disbursement date — cannot build a schedule.", "info": info}

    # --- Real disbursement amount, from documents_journal (NOT contracts.provided_amount,
    # which is a live running balance the system decrements on every real principal
    # repayment — using it as the schedule's starting principal would understate the
    # original loan for any contract that has already had payments). Joined via
    # deals.contract_id -> deal_id, same pattern as the real-payment trail below,
    # since documents_journal.contract_id itself is unreliably populated.
    deals_df = tables[config.TABLE_DEALS].copy()
    deals_df[config.COL_DEAL_CONTRACT_ID] = pd.to_numeric(deals_df[config.COL_DEAL_CONTRACT_ID], errors="coerce")
    contract_deals = deals_df[deals_df[config.COL_DEAL_CONTRACT_ID] == contract_id]
    deal_ids = set(int(x) for x in contract_deals[config.COL_DEAL_ID].dropna())

    dj_df = tables[config.TABLE_DOCUMENTS_JOURNAL].copy()
    dj_df[config.COL_DJ_DEAL_ID] = pd.to_numeric(dj_df[config.COL_DJ_DEAL_ID], errors="coerce")
    dj_df[config.COL_DJ_AMOUNT_AMD] = pd.to_numeric(dj_df[config.COL_DJ_AMOUNT_AMD], errors="coerce")
    via_deal = dj_df[
        dj_df[config.COL_DJ_DEAL_ID].isin(deal_ids) & dj_df[config.COL_DJ_DELETED_AT].isna()
    ].copy()
    via_deal["_date"] = via_deal[config.COL_DJ_DATE].apply(_to_date)

    disbursement_rows = via_deal[via_deal[config.COL_DJ_DOCUMENT_TYPE] == config.DOC_TYPE_DISBURSEMENT]
    disbursement_amount = float(disbursement_rows[config.COL_DJ_AMOUNT_AMD].sum())
    if disbursement_amount <= 0:
        # Fallback for a dump/contract where the disbursement posting is missing —
        # provided_amount is only correct here if no payment has reduced it yet.
        disbursement_amount = provided_amount

    info = {
        "id": contract_id,
        "num": str(crow.get(config.COL_CONTRACT_NUM) or ""),
        "client_id": client_id,
        "client_name": client_name,
        "provided_amount": provided_amount,
        "disbursement_amount": disbursement_amount,
        "interest_rate": interest_rate,
        "penalty_rate": penalty_rate,
        "deadline_days": deadline_days,
        "payment_day": payment_day,
        "provided_at": provided_at,
        "left": contract_left,
        "collected": contract_collected,
        "penalty_amount": contract_penalty_amount,
        "payment_type": str(crow.get(config.COL_CONTRACT_PAYMENT_TYPE) or ""),
    }

    legacy_schedule = build_legacy_schedule(disbursement_amount, interest_rate, deadline_days, provided_at)
    broken_schedule = build_broken_period_schedule(
        disbursement_amount, interest_rate, deadline_days, provided_at, payment_day or provided_at.day
    )

    # --- Current `payments` table rows (the live operational schedule) ---
    pay = tables[config.TABLE_PAYMENTS].copy()
    pay[config.COL_PAY_CONTRACT_ID] = pd.to_numeric(pay[config.COL_PAY_CONTRACT_ID], errors="coerce")
    for c in (config.COL_PAY_AMOUNT, config.COL_PAY_PRINCIPAL_PAYMENT, config.COL_PAY_INTEREST_PAYMENT,
              config.COL_PAY_ORIGINAL_PRINCIPAL_PAYMENT, config.COL_PAY_ORIGINAL_INTEREST_PAYMENT,
              config.COL_PAY_REMAINING, config.COL_PAY_PAID):
        pay[c] = pd.to_numeric(pay[c], errors="coerce")
    contract_payments = pay[
        (pay[config.COL_PAY_CONTRACT_ID] == contract_id) & pay[config.COL_PAY_DELETED_AT].isna()
    ].copy()
    contract_payments["_date"] = contract_payments[config.COL_PAY_DATE].apply(_to_date)
    contract_payments = contract_payments.sort_values(["_date", config.COL_PAY_ID])

    regular_rows = contract_payments[contract_payments[config.COL_PAY_TYPE] == config.PAYMENT_TYPE_REGULAR]
    penalty_rows = contract_payments[contract_payments[config.COL_PAY_TYPE] == config.PAYMENT_TYPE_PENALTY]

    current_rows = []
    for _, r in regular_rows.iterrows():
        current_rows.append({
            "date": r["_date"],
            "status": r[config.COL_PAY_STATUS],
            "principal": float(r[config.COL_PAY_PRINCIPAL_PAYMENT] or 0),
            "interest": float(r[config.COL_PAY_INTEREST_PAYMENT] or 0),
            "amount": float(r[config.COL_PAY_AMOUNT] or 0),
            "remaining": float(r[config.COL_PAY_REMAINING] or 0),
            "paid": float(r[config.COL_PAY_PAID]) if pd.notna(r[config.COL_PAY_PAID]) else None,
        })

    # --- Real payment trail: same deals -> documents_journal join used above for
    # the disbursement amount, now filtered to the repayment document types.
    # documents_journal is the precise source (2-decimal) when present, but it
    # has real gaps: confirmed on contract 4 that 3 of 4 payments' deals.penalty
    # (1041 / 147 / 285 AMD) had NO matching "Տուգանքի մարում" posting at all,
    # and on contract 2 that deal 87's deals.interest_amount (10,970 AMD) had NO
    # matching "Տոկոսի մարում" posting either. deals.interest_amount/penalty are
    # the complete source but only whole-AMD precision (both columns are `int`
    # in the schema) — so they're used ONLY as a fallback when documents_journal
    # is missing that posting entirely, never overriding a real journal figure.
    real_by_date: Dict[date, Dict[str, float]] = {}
    for _, r in via_deal.iterrows():
        d = r["_date"]
        if d is None or d > as_of:
            continue
        doc_type = str(r[config.COL_DJ_DOCUMENT_TYPE] or "").strip()
        amt = float(r[config.COL_DJ_AMOUNT_AMD] or 0)
        bucket = real_by_date.setdefault(d, {"principal": 0.0, "interest": 0.0, "penalty": 0.0})
        if doc_type in config.DOC_TYPE_PAY_MOTHER:
            bucket["principal"] += amt
        elif doc_type in config.DOC_TYPE_PAY_INTEREST:
            bucket["interest"] += amt
        elif doc_type in config.DOC_TYPE_PAY_PENALTY:
            bucket["penalty"] += amt

    missing_penalty_postings = []
    payment_deals = contract_deals[
        (contract_deals[config.COL_DEAL_TYPE] == "in")
        & (contract_deals[config.COL_DEAL_FILTER_TYPE].isin(config.DEAL_PAYMENT_FILTER_TYPES))
    ]
    for _, r in payment_deals.iterrows():
        d = _to_date(r[config.COL_DEAL_DATE])
        if d is None or d > as_of:
            continue
        bucket = real_by_date.setdefault(d, {"principal": 0.0, "interest": 0.0, "penalty": 0.0})
        for field, col in (("interest", config.COL_DEAL_INTEREST_AMOUNT), ("penalty", config.COL_DEAL_PENALTY)):
            raw = r.get(col)
            deal_value = float(raw) if pd.notna(raw) else 0.0
            if deal_value <= 0:
                continue
            journal_value = bucket[field]
            if journal_value <= 0.01:
                bucket[field] = deal_value
                missing_penalty_postings.append({
                    "date": d, "field": field, "deal_amount": deal_value, "journal_amount": journal_value,
                    "missing": round(deal_value - journal_value, 2),
                })

    real_payments = [
        RealPayment(date=d, principal=v["principal"], interest=v["interest"], penalty=v["penalty"])
        for d, v in sorted(real_by_date.items())
    ]

    ledger = replay_real_payments(disbursement_amount, real_payments)
    calculated_balance = ledger[-1].balance_after if ledger else disbursement_amount
    # Balance right before the LAST real payment — needed for the late-payment
    # interest split (days late still accrue on the pre-payment balance).
    balance_before_last_payment = ledger[-2].balance_after if len(ledger) >= 2 else disbursement_amount

    # "Still open" = fully settled by cascading each real payment's principal
    # AND interest against the original schedule (overflow on either rolls
    # into the next row directly) — NOT "due date in the future". An
    # installment can be overdue and still unpaid and must still show up as
    # an open row.
    completed_count, recomputed_legacy = recompute_open_rows(
        legacy_schedule, calculated_balance, real_payments, interest_rate, balance_before_last_payment
    )
    _, recomputed_broken = recompute_open_rows(
        broken_schedule, calculated_balance, real_payments, interest_rate, balance_before_last_payment
    )

    # Cross-check: does the cascade-derived completed count match how many
    # rows the live payments table itself marks 'completed'?
    live_completed_count = int((regular_rows[config.COL_PAY_STATUS] == config.PAYMENT_STATUS_COMPLETED).sum())

    # --- Worked example: spell out exactly how the FIRST still-open row's
    # numbers were produced, using this contract's own real figures ---
    first_open_explainer = None
    if recomputed_legacy:
        first = recomputed_legacy[0]
        original_row = legacy_schedule[completed_count] if completed_count < len(legacy_schedule) else None
        prev_due_date = legacy_schedule[completed_count - 1].due_date if completed_count > 0 else provided_at
        principal_overflow = round((original_row.principal - first.principal), 2) if original_row else 0.0
        interest_overflow = round((original_row.interest - first.interest), 2) if original_row else 0.0
        first_open_explainer = {
            "due_date": first.due_date,
            "prev_due_date": prev_due_date,
            "days": first.days,
            "balance": round(calculated_balance, 2),
            "rate": interest_rate,
            "original_interest": original_row.interest if original_row else first.interest,
            "interest": first.interest,
            "interest_overflow": interest_overflow,
            "original_principal": original_row.principal if original_row else first.principal,
            "principal": first.principal,
            "principal_overflow": principal_overflow,
            "amount": first.amount,
            "last_real_payment_date": ledger[-1].date if ledger else provided_at,
        }

    # --- Payment ledger: one entry per REAL payment, showing exactly which
    # installment(s) its principal/interest applied to and any overflow it
    # produced — attributed to the payment that caused it, not guessed at
    # from the installment side (which payment cleared a row it didn't
    # itself fall due on was a recurring point of confusion).
    payment_ledger = build_payment_ledger(legacy_schedule, real_payments)

    # --- Combined timeline: real cash paid so far, then projected forward to
    # payoff, one continuous running balance ---
    timeline = []
    for e in ledger:
        timeline.append({
            "kind": "paid", "date": e.date, "days": None,
            "principal": e.principal, "interest": e.interest, "penalty": e.penalty,
            "amount": round(e.principal + e.interest + e.penalty, 2),
            "balance_after": e.balance_after,
        })
    for r in recomputed_legacy:
        timeline.append({
            "kind": "projected", "date": r.due_date, "days": r.days,
            "principal": r.principal, "interest": r.interest, "penalty": 0.0,
            "amount": r.amount, "balance_after": r.balance_after,
        })

    # --- Penalty cross-check (countPenalty formula) ---
    open_installments = []
    for _, r in regular_rows.iterrows():
        if r[config.COL_PAY_STATUS] != "initial":
            continue
        d = r["_date"]
        if d is None or d >= as_of:
            continue
        amt = float(r[config.COL_PAY_AMOUNT] or 0)
        if amt <= 0:
            continue
        pid = int(r[config.COL_PAY_ID])
        linked = penalty_rows[penalty_rows.get("parent_id") == pid] if "parent_id" in penalty_rows.columns else penalty_rows.iloc[0:0]
        penalty_paid = float(pd.to_numeric(linked[config.COL_PAY_PAID], errors="coerce").fillna(0).sum()) if not linked.empty else 0.0
        last_penalty_date = None
        if not linked.empty:
            dts = [x for x in linked[config.COL_PAY_DATE].apply(_to_date) if x is not None]
            last_penalty_date = max(dts) if dts else None
        open_installments.append({
            "due_date": d, "amount": amt, "penalty_paid": penalty_paid,
            "last_penalty_paid_date": last_penalty_date,
        })

    penalty_result = count_penalty(open_installments, penalty_rate, as_of)

    return {
        "info": info,
        "stored_schedule": stored_schedule,
        "legacy_schedule": legacy_schedule,
        "broken_schedule": broken_schedule,
        "current_rows": current_rows,
        "real_payments": real_payments,
        "ledger": ledger,
        "calculated_balance": round(calculated_balance, 2),
        "recomputed_legacy": recomputed_legacy,
        "recomputed_broken": recomputed_broken,
        "completed_count": completed_count,
        "live_completed_count": live_completed_count,
        "first_open_explainer": first_open_explainer,
        "payment_ledger": payment_ledger,
        "timeline": timeline,
        "penalty_result": penalty_result,
        "missing_penalty_postings": sorted(missing_penalty_postings, key=lambda x: x["date"]),
    }
