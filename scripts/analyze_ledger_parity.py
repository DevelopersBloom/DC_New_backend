#!/usr/bin/env python3
"""Analyze transactions vs documents_journal parity from phpMyAdmin SQL dump."""

from __future__ import annotations

import re
import sys
from collections import defaultdict
from datetime import date
from decimal import Decimal
from pathlib import Path
from typing import Any

DJ_CLASS = "App\\Models\\DocumentJournal"
PERIODS = {
    "2026-02": ("2026-02-01", "2026-02-28"),
    "2026-03": ("2026-03-01", "2026-03-31"),
    "2026-04": ("2026-04-01", "2026-04-30"),
    "Feb-Apr": ("2026-02-01", "2026-04-30"),
    "all": (None, None),
}


def split_sql_values(inner: str) -> list[str | None]:
    parts: list[str | None] = []
    buf: list[str] = []
    in_str = False
    i = 0
    while i < len(inner):
        ch = inner[i]
        if in_str:
            if ch == "\\" and i + 1 < len(inner):
                buf.append(inner[i : i + 2])
                i += 2
                continue
            if ch == "'":
                if i + 1 < len(inner) and inner[i + 1] == "'":
                    buf.append("''")
                    i += 2
                    continue
                in_str = False
                i += 1
                continue
            buf.append(ch)
            i += 1
            continue
        if ch == "'":
            in_str = True
            i += 1
            continue
        if ch == ",":
            token = "".join(buf).strip()
            parts.append(None if token.upper() == "NULL" else token)
            buf = []
            i += 1
            continue
        buf.append(ch)
        i += 1
    token = "".join(buf).strip()
    parts.append(None if token.upper() == "NULL" else token)
    return parts


def parse_rows(block: str) -> list[tuple[str, ...]]:
    rows: list[tuple[str, ...]] = []
    depth = 0
    start = None
    for i, ch in enumerate(block):
        if ch == "(":
            if depth == 0:
                start = i + 1
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0 and start is not None:
                inner = block[start:i]
                rows.append(tuple(split_sql_values(inner)))
                start = None
    return rows


def load_table(path: Path, table: str) -> list[dict[str, Any]]:
    text = path.read_text(encoding="utf-8", errors="replace")
    pattern = re.compile(
        rf"INSERT INTO `{re.escape(table)}` \(([^)]+)\) VALUES\s*(.*?);",
        re.DOTALL,
    )
    records: list[dict[str, Any]] = []
    for m in pattern.finditer(text):
        cols = [c.strip().strip("`") for c in m.group(1).split(",")]
        for row in parse_rows(m.group(2)):
            if len(row) != len(cols):
                continue
            records.append(dict(zip(cols, row)))
    return records


def as_date(s: str | None) -> date | None:
    if not s:
        return None
    return date.fromisoformat(s[:10])


def as_decimal(s: str | None) -> Decimal:
    if s is None:
        return Decimal("0")
    return Decimal(s)


def in_period(d: date | None, start: str | None, end: str | None) -> bool:
    if d is None:
        return False
    if start and d < date.fromisoformat(start):
        return False
    if end and d > date.fromisoformat(end):
        return False
    return True


def norm_type(t: str | None) -> str | None:
    if t is None:
        return None
    return t.replace("\\\\", "\\")


def main() -> int:
    dump = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("diamond_credit_test (12).sql")
    if not dump.exists():
        print(f"File not found: {dump}", file=sys.stderr)
        return 1

    journals = load_table(dump, "documents_journal")
    txns = load_table(dump, "transactions")

    dj_by_id = {}
    for j in journals:
        if j.get("deleted_at"):
            continue
        jid = int(j["id"])
        dj_by_id[jid] = j

    txn_by_journal: dict[int, dict] = {}
    txn_orphans: list[dict] = []
    for t in txns:
        if t.get("deleted_at"):
            continue
        tt = norm_type(t.get("transactionable_type"))
        tid = t.get("transactionable_id")
        if tt == DJ_CLASS and tid:
            txn_by_journal[int(tid)] = t
        else:
            txn_orphans.append(t)

    journals_without_txn = [j for jid, j in dj_by_id.items() if jid not in txn_by_journal]

    mismatches = []
    for jid, j in dj_by_id.items():
        t = txn_by_journal.get(jid)
        if not t:
            continue
        issues = []
        if as_decimal(j.get("amount_amd")) != as_decimal(t.get("amount_amd")):
            issues.append("amount_amd")
        if (j.get("debit_account_id") or "") != (t.get("debit_account_id") or ""):
            issues.append("debit_account_id")
        if (j.get("credit_account_id") or "") != (t.get("credit_account_id") or ""):
            issues.append("credit_account_id")
        if (j.get("date") or "")[:10] != (t.get("date") or "")[:10]:
            issues.append("date")
        if issues:
            mismatches.append((jid, issues, j, t))

    print(f"Dump: {dump.name}")
    print(f"Active documents_journal rows: {len(dj_by_id)}")
    print(f"Active transactions rows: {sum(1 for t in txns if not t.get('deleted_at'))}")
    print(f"Transactions linked to DocumentJournal: {len(txn_by_journal)}")
    print()
    print("=== Global ===")
    print(f"Journals without transaction: {len(journals_without_txn)}")
    print(f"Transactions without journal link: {len(txn_orphans)}")
    print(f"Linked pairs with field mismatch: {len(mismatches)}")
    print()

    # Sample journal-only types
    if journals_without_txn:
        type_counts: dict[str, int] = defaultdict(int)
        for j in journals_without_txn:
            type_counts[j.get("document_type") or "?"] += 1
        print("Journal-only document_type (top 10):")
        for dt, c in sorted(type_counts.items(), key=lambda x: -x[1])[:10]:
            print(f"  {c:5d}  {dt}")
        print()

    for label, (start, end) in PERIODS.items():
        j_in = [j for j in dj_by_id.values() if in_period(as_date(j.get("date")), start, end)]
        t_in = [
            t
            for t in txns
            if not t.get("deleted_at") and in_period(as_date(t.get("date")), start, end)
        ]
        j_no_tx = [j for j in j_in if int(j["id"]) not in txn_by_journal]
        t_no_j = [
            t
            for t in t_in
            if norm_type(t.get("transactionable_type")) != DJ_CLASS
            or not t.get("transactionable_id")
            or int(t["transactionable_id"]) not in dj_by_id
        ]
        mm = [
            m
            for m in mismatches
            if in_period(as_date(m[2].get("date")), start, end)
        ]
        print(f"=== Period: {label} ({start or '…'} → {end or '…'}) ===")
        print(f"  Journals: {len(j_in)} | Transactions: {len(t_in)}")
        print(f"  Journals without txn: {len(j_no_tx)}")
        print(f"  Txns without journal: {len(t_no_j)}")
        print(f"  Mismatched linked pairs: {len(mm)}")
        if j_no_tx[:3]:
            print("  Sample journal-only ids:", ", ".join(j["id"] for j in j_no_tx[:5]))
        if mm[:3]:
            print(
                "  Sample mismatch ids:",
                ", ".join(f"{jid}({','.join(iss)})" for jid, iss, _, _ in mm[:5]),
            )
        print()

    # Account 50000 balance compare at 2026-04-30 (V07-style vs no type rule)
    acc50000 = None
    coa = load_table(dump, "chart_of_accounts")
    for row in coa:
        if row.get("code") == "50000" and not row.get("deleted_at"):
            acc50000 = row.get("id")
            break

    if acc50000:
        cutoff = date(2026, 4, 30)
        dj_cr = dj_dr = Decimal(0)
        tx_cr = tx_dr = Decimal(0)
        for j in dj_by_id.values():
            d = as_date(j.get("date"))
            if d and d <= cutoff:
                if j.get("credit_account_id") == acc50000:
                    dj_cr += as_decimal(j.get("amount_amd"))
                if j.get("debit_account_id") == acc50000:
                    dj_dr += as_decimal(j.get("amount_amd"))
        for t in txns:
            if t.get("deleted_at"):
                continue
            d = as_date(t.get("date"))
            if d and d <= cutoff:
                if t.get("credit_account_id") == acc50000:
                    tx_cr += as_decimal(t.get("amount_amd"))
                if t.get("debit_account_id") == acc50000:
                    tx_dr += as_decimal(t.get("amount_amd"))
        print("=== Account 50000 balance @ 2026-04-30 (journal: credit - debit) ===")
        print(f"  documents_journal: {dj_cr - dj_dr}")
        print(f"  transactions (raw sum): {tx_cr - tx_dr}")
        print(f"  difference: {(dj_cr - dj_dr) - (tx_cr - tx_dr)}")
        print()

    # Feb-Apr: volume only in one table
    start, end = PERIODS["Feb-Apr"]
    j_only_amt = sum(
        as_decimal(j.get("amount_amd"))
        for j in dj_by_id.values()
        if in_period(as_date(j.get("date")), start, end)
        and int(j["id"]) not in txn_by_journal
    )
    t_only_amt = sum(
        as_decimal(t.get("amount_amd"))
        for t in txn_orphans
        if in_period(as_date(t.get("date")), start, end)
    )
    mm_amt = Decimal(0)
    for jid, issues, j, t in mismatches:
        if not in_period(as_date(j.get("date")), start, end):
            continue
        if "amount_amd" in issues:
            mm_amt += abs(as_decimal(j.get("amount_amd")) - as_decimal(t.get("amount_amd")))

    print("=== Feb-Apr impact (AMD totals) ===")
    print(f"  Sum on journals with NO transaction: {j_only_amt:,.2f}")
    print(f"  Sum on orphan transactions (no journal): {t_only_amt:,.2f}")
    print(f"  Sum |amount diff| on mismatched pairs: {mm_amt:,.2f}")
    print()

    if txn_orphans:
        print("Orphan transactions (no active journal):")
        for t in txn_orphans[:15]:
            print(
                f"  id={t['id']} date={t.get('date')} "
                f"type={t.get('document_type')} amt={t.get('amount_amd')} "
                f"link={norm_type(t.get('transactionable_type'))}#{t.get('transactionable_id')}"
            )
        print()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
