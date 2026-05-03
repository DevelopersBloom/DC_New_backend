#!/usr/bin/env python3
"""
Parse MySQL dump and replicate V03 Sheet3 column L (risk bucket 75) for a cutoff date.

  python3 scripts/v03_column_l_snapshot.py "diamond_credit_test (3).sql" 2026-04-01
  python3 scripts/v03_column_l_snapshot.py "diamond_credit_test (3).sql" 2026-04-01 --gl 16200NV
"""

from __future__ import annotations

import re
import sys
from collections import defaultdict
from datetime import date, datetime
from decimal import Decimal
from pathlib import Path
from typing import Any, Iterator


def parse_mysql_row(inner: str) -> list[str | None]:
    fields: list[str | None] = []
    buf: list[str] = []
    i = 0
    in_str = False
    while i < len(inner):
        c = inner[i]
        if in_str:
            if c == "\\" and i + 1 < len(inner):
                buf.append(c)
                buf.append(inner[i + 1])
                i += 2
                continue
            if c == "'":
                if i + 1 < len(inner) and inner[i + 1] == "'":
                    buf.append("'")
                    i += 2
                    continue
                in_str = False
                i += 1
                continue
            buf.append(c)
            i += 1
            continue
        if c == "'":
            in_str = True
            i += 1
            continue
        if c == ",":
            raw = "".join(buf).strip()
            fields.append(None if raw.upper() == "NULL" else raw)
            buf = []
            i += 1
            continue
        buf.append(c)
        i += 1
    raw = "".join(buf).strip()
    fields.append(None if raw.upper() == "NULL" else raw)
    return fields


def iter_insert_rows(sql: str, table: str) -> Iterator[list[str | None]]:
    token = f"INSERT INTO `{table}`"
    pos = 0
    while True:
        ins = sql.find(token, pos)
        if ins == -1:
            break
        v = sql.find("VALUES", ins)
        if v == -1:
            pos = ins + len(token)
            continue
        i = sql.find("(", v)
        if i == -1:
            break
        while i < len(sql) and sql[i] == "(":
            depth = 0
            start = i
            j = i
            while j < len(sql):
                ch = sql[j]
                if ch == "(":
                    depth += 1
                elif ch == ")":
                    depth -= 1
                    if depth == 0:
                        inner = sql[start + 1 : j]
                        yield parse_mysql_row(inner)
                        j += 1
                        while j < len(sql) and sql[j] in " \t\r\n":
                            j += 1
                        if j < len(sql) and sql[j] == ",":
                            j += 1
                            while j < len(sql) and sql[j] in " \t\r\n":
                                j += 1
                        if j < len(sql) and sql[j] == "(":
                            i = j
                            break
                        pos = j
                        i = -1
                        break
                j += 1
            if i == -1:
                break
            if j >= len(sql):
                pos = len(sql)
                break
        if i != -1 and i >= len(sql):
            break


def d(val: str | None) -> Decimal:
    if val is None or val.upper() == "NULL":
        return Decimal(0)
    return Decimal(str(val).strip().strip("'"))


def parse_date(val: str | None) -> date | None:
    if not val:
        return None
    s = str(val).strip().strip("'")[:10]
    try:
        return datetime.strptime(s, "%Y-%m-%d").date()
    except ValueError:
        return None


def parse_ts(val: str | None) -> datetime | None:
    if not val or str(val).upper() == "NULL":
        return None
    s = str(val).strip().strip("'")[:19]
    try:
        return datetime.strptime(s, "%Y-%m-%d %H:%M:%S")
    except ValueError:
        try:
            return datetime.strptime(s[:10], "%Y-%m-%d")
        except ValueError:
            return None


def insert_column_index(sql: str, table: str, column: str) -> int:
    token = f"INSERT INTO `{table}`"
    ins = sql.find(token)
    if ins == -1:
        raise SystemExit(f"No INSERT INTO `{table}` found")
    lp = sql.find("(", ins)
    rp = sql.find(")", ins)
    header = sql[lp + 1 : rp]
    cols = [c.strip().strip("`") for c in header.split(",")]
    return cols.index(column)


def tx_account_balance(
    text: str,
    coa: dict[int, dict[str, Any]],
    acc_id: int,
    cutoff: date,
    *,
    partner_required: bool,
) -> Decimal:
    """Signed position on account from transactions (same CASE as V03)."""
    ACTIVE_TYPES = {"active", "expense", "off_balance"}

    def sd(aid: int, amount: Decimal) -> Decimal:
        t = coa.get(aid, {}).get("type", "")
        return amount if t in ACTIVE_TYPES else -amount

    def sc(aid: int, amount: Decimal) -> Decimal:
        t = coa.get(aid, {}).get("type", "")
        return -amount if t in ACTIVE_TYPES else amount

    tot = Decimal(0)
    for row in iter_insert_rows(text, "transactions"):
        if len(row) < 26:
            continue
        dt = parse_date(row[1])
        if dt is None or dt > cutoff:
            continue
        if row[21] and str(row[21]).upper() != "NULL":
            continue
        amount = d(row[14])
        dacc_raw, cacc_raw = row[4], row[9]
        dpart, cpart = row[5], row[10]
        if dacc_raw and str(dacc_raw).upper() != "NULL" and int(dacc_raw) == acc_id:
            if not partner_required or (dpart and str(dpart).upper() != "NULL"):
                tot += sd(acc_id, amount)
        if cacc_raw and str(cacc_raw).upper() != "NULL" and int(cacc_raw) == acc_id:
            if not partner_required or (cpart and str(cpart).upper() != "NULL"):
                tot += sc(acc_id, amount)
    return tot


def main() -> None:
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)
    path = Path(sys.argv[1])
    cutoff = datetime.strptime(sys.argv[2], "%Y-%m-%d").date()
    gl_code = None
    if len(sys.argv) >= 5 and sys.argv[3] == "--gl":
        gl_code = sys.argv[4]
    elif len(sys.argv) >= 4 and sys.argv[3].startswith("--gl="):
        gl_code = sys.argv[3].split("=", 1)[1]
    text = path.read_text(encoding="utf-8", errors="replace")

    coa: dict[int, dict[str, Any]] = {}
    for row in iter_insert_rows(text, "chart_of_accounts"):
        if len(row) < 11:
            continue
        aid = int(row[0] or 0)
        code = (row[1] or "").strip().strip("'")
        typ = (row[3] or "").strip().strip("'")
        rw_raw = row[10]
        risk_w = None
        if rw_raw is not None and str(rw_raw).upper() != "NULL":
            try:
                risk_w = float(d(rw_raw))
            except Exception:
                risk_w = None
        coa[aid] = {"code": code, "type": typ, "risk_weight": risk_w}

    # Match V03Export: only 16200, 16200NV, 16201NI for the transactions partner block.
    acc16_ids = set()
    for code in ("16200", "16200NV", "16201NI"):
        for aid, v in coa.items():
            if v["code"] == code:
                acc16_ids.add(aid)
                break
    acc2100 = next((i for i, v in coa.items() if v["code"] == "2100"), None)
    acc2101 = next((i for i, v in coa.items() if v["code"] == "2101"), None)
    acc2200 = next((i for i, v in coa.items() if v["code"] == "2200"), None)
    acc2211 = next((i for i, v in coa.items() if v["code"] == "2211"), None)

    idx_cc_id = insert_column_index(text, "clients", "classification_id")

    class_rw: dict[int, int] = {}
    class_reserve: dict[int, float] = {}
    # INSERT: id, title, name, order, reserve_percent, risk_weight, created_at, updated_at
    for row in iter_insert_rows(text, "clients_classification"):
        if len(row) < 6:
            continue
        cid = int(row[0] or 0)
        class_reserve[cid] = float(d(row[4]))
        class_rw[cid] = int(float(d(row[5])))

    client_to_clid: dict[int, int] = {}
    for row in iter_insert_rows(text, "clients"):
        if len(row) <= idx_cc_id:
            continue
        cid = int(row[0] or 0)
        raw = row[idx_cc_id]
        if raw and str(raw).upper() != "NULL":
            client_to_clid[cid] = int(raw)

    hist_by_client: dict[int, list[tuple[datetime, int, float]]] = defaultdict(list)
    for row in iter_insert_rows(text, "classification_histories"):
        if len(row) < 11:
            continue
        client_id = int(row[1] or 0)
        rw = int(float(d(row[3])))
        rp = float(d(row[4]))
        dt = parse_ts(row[9])
        if dt:
            hist_by_client[client_id].append((dt, rw, rp))

    def client_rw_rp(client_id: int) -> tuple[int, float]:
        cands = [(dt, rw, rp) for dt, rw, rp in hist_by_client.get(client_id, []) if dt.date() <= cutoff]
        if cands:
            cands.sort(key=lambda x: x[0])
            _, rw, rp = cands[-1]
            return rw, rp
        clid = client_to_clid.get(client_id)
        if clid is not None:
            return class_rw.get(clid, 0), class_reserve.get(clid, 0.0)
        return 0, 0.0

    # Path A: documents_journal
    dj_rows: list[tuple[date, int | None, int | None, Decimal]] = []
    for row in iter_insert_rows(text, "documents_journal"):
        if len(row) < 23:
            continue
        dt = parse_date(row[1])
        if dt is None or dt > cutoff:
            continue
        if row[22] and str(row[22]).upper() != "NULL":
            continue
        amt = d(row[5])
        dacc = int(row[11]) if row[11] and str(row[11]).upper() != "NULL" else None
        cacc = int(row[12]) if row[12] and str(row[12]).upper() != "NULL" else None
        dj_rows.append((dt, dacc, cacc, amt))

    def dj_balance(acc_id: int) -> Decimal:
        deb = sum(r[3] for r in dj_rows if r[1] == acc_id)
        cred = sum(r[3] for r in dj_rows if r[2] == acc_id)
        return deb - cred

    path_a_accounts: dict[int, Decimal] = {}
    path_a_total = Decimal(0)
    path_a_reserve = Decimal(0)
    for acc_id, meta in coa.items():
        if meta["type"] != "active" or meta["risk_weight"] is None:
            continue
        if int(meta["risk_weight"]) != 75:
            continue
        bal = dj_balance(acc_id)
        if acc_id == acc2100 and acc2101:
            bal -= dj_balance(acc2101)
        if acc_id == acc2200 and acc2211:
            bal -= dj_balance(acc2211)
        if bal != 0:
            path_a_accounts[acc_id] = bal
            path_a_total += bal
            path_a_reserve += bal * Decimal("0.01")

    ACTIVE_TYPES = {"active", "expense", "off_balance"}

    def signed_debit(acc_id: int, amount: Decimal) -> Decimal:
        t = coa.get(acc_id, {}).get("type", "")
        if t in ACTIVE_TYPES:
            return amount
        return -amount

    def signed_credit(acc_id: int, amount: Decimal) -> Decimal:
        t = coa.get(acc_id, {}).get("type", "")
        if t in ACTIVE_TYPES:
            return -amount
        return amount

    partner_contrib: dict[int, dict[int, Decimal]] = defaultdict(lambda: defaultdict(Decimal))
    for row in iter_insert_rows(text, "transactions"):
        if len(row) < 26:
            continue
        dt = parse_date(row[1])
        if dt is None or dt > cutoff:
            continue
        if row[21] and str(row[21]).upper() != "NULL":
            continue
        # INSERT order: debit_account_id=4, debit_partner_id=5, credit_account_id=9, credit_partner_id=10, amount_amd=14, deleted_at=21
        amount = d(row[14])
        dacc_raw, cacc_raw = row[4], row[9]
        dpart, cpart = row[5], row[10]

        if dacc_raw and str(dacc_raw).upper() != "NULL":
            dacc = int(dacc_raw)
            if dacc in acc16_ids and dpart and str(dpart).upper() != "NULL":
                pid = int(dpart)
                partner_contrib[pid][dacc] += signed_debit(dacc, amount)
        if cacc_raw and str(cacc_raw).upper() != "NULL":
            cacc = int(cacc_raw)
            if cacc in acc16_ids and cpart and str(cpart).upper() != "NULL":
                pid = int(cpart)
                partner_contrib[pid][cacc] += signed_credit(cacc, amount)

    path_b_by_account: dict[int, Decimal] = defaultdict(Decimal)
    path_b_total = Decimal(0)
    path_b_reserve = Decimal(0)
    partners_in_l: list[tuple[int, Decimal, float]] = []
    for partner_id, acc_map in partner_contrib.items():
        bal = sum(acc_map.values(), start=Decimal(0))
        if bal <= 0:
            continue
        rw, rp = client_rw_rp(partner_id)
        if rw != 75:
            continue
        partners_in_l.append((partner_id, bal, rp))
        path_b_total += bal
        path_b_reserve += bal * Decimal(str(rp)) / Decimal(100)
        for aid, part in acc_map.items():
            path_b_by_account[aid] += part

    print(f"Cutoff: {cutoff} inclusive | loan GL ids (16200, 16200NV, 16201NI): {len(acc16_ids)}")
    print()
    print("=== Path A: document_journals, COA type=active AND risk_weight=75 ===")
    if not path_a_accounts:
        print("  (none or all zero)")
    else:
        for aid in sorted(path_a_accounts, key=lambda x: coa[x]["code"]):
            print(f"  {coa[aid]['code']:14} id={aid:<5}  net DJ={path_a_accounts[aid]}")
    print(f"  Amount subtotal AMD: {path_a_total}")
    print(f"  Reserve (1%):        {path_a_reserve}")
    print()
    print("=== Path B: transactions on 16200/16200NV/16201NI + partner, net>0, int(risk_weight)==75 ===")
    print(f"  Partner count: {len(partners_in_l)}")
    for pid, bal, rp in sorted(partners_in_l, key=lambda x: -x[1])[:40]:
        print(f"  client_id={pid:<4}  net_16={bal}  reserve%={rp}")
    if len(partners_in_l) > 40:
        print(f"  ... +{len(partners_in_l) - 40} more")
    print("  Per-account signed contribution (sum over L-bucket partners):")
    for aid, val in sorted(path_b_by_account.items(), key=lambda x: -abs(float(x[1]))):
        if val == 0:
            continue
        c = coa.get(aid, {}).get("code", "?")
        print(f"    {c:14} id={aid:<5}  contrib={val}")
    print(f"  Amount subtotal AMD: {path_b_total}")
    print(f"  Reserve (class %):   {path_b_reserve}")
    print()
    grand = path_a_total + path_b_total
    grand_r = path_a_reserve + path_b_reserve
    print("=== Sheet3 column L (amount) = (A + B) / 1000 ===")
    print(f"  Total AMD:     {grand}")
    print(f"  L column (/1000): {grand / Decimal(1000)}")
    print(f"  M column reserve (/1000): {grand_r / Decimal(1000)}")

    # --- One-page summary (matches V03Export + Client::classification fallback) ---
    print()
    print("=" * 60)
    print(f"  April {cutoff.day}, {cutoff.year} — Sheet3 column L (risk bucket 75)")
    print("=" * 60)
    print(f"  L column (AMD, before ÷1000): {grand}")
    print(f"  L column (÷1000, Excel cell): {grand / Decimal(1000)}")
    print(f"  M column reserve (÷1000):    {grand_r / Decimal(1000)}")
    print()
    print("  Accounts (16200 / 16200NV / 16201NI path — signed sum over partners in L bucket):")
    for aid, val in sorted(path_b_by_account.items(), key=lambda x: -abs(float(x[1]))):
        if val == 0:
            continue
        c = coa.get(aid, {}).get("code", "?")
        print(f"    {c} = {val}")
    if path_a_total != 0:
        print("  Plus Path A (active GL with COA.risk_weight=75, journals):")
        for aid, val in path_a_accounts.items():
            print(f"    {coa[aid]['code']} = {val}")
    print("=" * 60)

    if gl_code:
        aid = next((i for i, v in coa.items() if v["code"] == gl_code), None)
        if aid is None:
            print(f"\nUnknown account code: {gl_code}")
            return
        dj_b = dj_balance(aid)
        tx_all = tx_account_balance(text, coa, aid, cutoff, partner_required=False)
        tx_part = tx_account_balance(text, coa, aid, cutoff, partner_required=True)
        slice_r75 = path_b_by_account.get(aid, Decimal(0))
        print()
        print(f"=== GL reconcile: {gl_code} (id={aid}) as of {cutoff} ===")
        print(f"  document_journals net (debit − credit, non-deleted): {dj_b}")
        print(f"  transactions signed (ALL lines on this account):       {tx_all}")
        print(f"  transactions signed (partner NOT NULL on that side): {tx_part}")
        print(f"  V03 L-slice only (risk_weight 75 partners, loan GL logic): {slice_r75}")
        print(
            f"  Δ (DJ − TX all): {dj_b - tx_all}  |  excluded from V03 partner net (TX all − TX partner): {tx_all - tx_part}"
        )

        # --- Gap: global GL on this 16-account vs V03 L attribution for same account ---
        null_nv = Decimal(0)
        partner_nv_only: dict[int, Decimal] = defaultdict(Decimal)
        for row in iter_insert_rows(text, "transactions"):
            if len(row) < 26:
                continue
            dt = parse_date(row[1])
            if dt is None or dt > cutoff:
                continue
            if row[21] and str(row[21]).upper() != "NULL":
                continue
            amount = d(row[14])
            dacc_raw, cacc_raw = row[4], row[9]
            dpart, cpart = row[5], row[10]
            if dacc_raw and str(dacc_raw).upper() != "NULL" and int(dacc_raw) == aid:
                c = signed_debit(aid, amount)
                if dpart and str(dpart).upper() != "NULL":
                    partner_nv_only[int(dpart)] += c
                else:
                    null_nv += c
            if cacc_raw and str(cacc_raw).upper() != "NULL" and int(cacc_raw) == aid:
                c = signed_credit(aid, amount)
                if cpart and str(cpart).upper() != "NULL":
                    partner_nv_only[int(cpart)] += c
                else:
                    null_nv += c

        rows_detail: list[tuple[str, int, Decimal, Decimal, int, str]] = []
        # (status, client_id, nv_on_account, net_16_all, risk_weight, reason)
        all_pids = set(partner_nv_only) | set(partner_contrib.keys())
        for pid in sorted(all_pids):
            nv = partner_nv_only.get(pid, Decimal(0))
            if nv == 0:
                continue
            net16 = sum(partner_contrib.get(pid, {}).values(), start=Decimal(0))
            rw, _rp = client_rw_rp(pid)
            if net16 > 0 and rw == 75:
                st = "IN_L"
                reason = "included"
            elif net16 <= 0:
                st = "EXCL"
                reason = "net_16<=0"
            else:
                st = "EXCL"
                reason = f"risk_weight={rw}"
            rows_detail.append((st, pid, nv, net16, rw, reason))

        sum_in = sum(r[2] for r in rows_detail if r[0] == "IN_L")
        sum_excl = sum(r[2] for r in rows_detail if r[0] == "EXCL")
        gap_calc = null_nv + sum_excl
        gap_reported = tx_all - slice_r75

        print()
        print(f"=== {gl_code}: why global ({tx_all}) ≠ V03 L slice ({slice_r75}) ===")
        print(f"  Lines with NULL partner on the 16200NV side: {null_nv}")
        print(f"  Sum NV on partners IN L bucket:               {sum_in}")
        print(f"  Sum NV on partners EXCLUDED from L bucket:    {sum_excl}")
        print(f"  Check NULL + excluded = gap:                  {gap_calc} (expect {gap_reported})")

        excl_sorted = [r for r in rows_detail if r[0] == "EXCL" and r[2] != 0]
        excl_sorted.sort(key=lambda r: -abs(float(r[2])))
        print()
        print("  Excluded clients with non-zero signed movement on this account (top):")
        print(f"  {'client_id':>10}  {'NV_on_acc':>16}  {'net_16':>16}  {'rw':>4}  reason")
        for _st, pid, nv, net16, rw, reason in excl_sorted[:25]:
            print(f"  {pid:>10}  {nv:>16}  {net16:>16}  {rw:>4}  {reason}")
        if len(excl_sorted) > 25:
            tail = sum(r[2] for r in excl_sorted[25:])
            print(f"  ... +{len(excl_sorted) - 25} more clients, combined NV on account: {tail}")

        in_sorted = [r for r in rows_detail if r[0] == "IN_L" and r[2] != 0]
        in_sorted.sort(key=lambda r: -abs(float(r[2])))
        small_nv_in = [r for r in in_sorted if abs(float(r[2])) < Decimal("0.01")]
        if small_nv_in:
            print(f"  (IN_L clients with ~0 NV on {gl_code}: {len(small_nv_in)})")


if __name__ == "__main__":
    main()
