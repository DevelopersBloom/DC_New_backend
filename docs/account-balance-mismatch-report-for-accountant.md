# Հաշվեշիռ Mismatch — Short Report for Accounting

**Date:** 29 May 2026 | **DB:** diamond_credit_test snapshot

---

## Bottom line

- **Transactions are OK** (double-entry is correct). **Do not reverse** May reserve entries because of this report.
- **The report number is wrong** — software formula error, not bad postings.
- **Class 8 accounts (`8*`)** should be excluded from the check (rule confirmed; code not updated yet).

---

## What is wrong in the system

**Should be:**  
`Assets − Liabilities − Equity − Income + Expense = 0`

**System does:**  
`Assets − Liabilities − (Equity + Income + Expense + off_balance)`

**Result:** Every **expense ↔ asset** line (e.g. **73015 → 16605PS**) counts **twice** against Հաշվեշիռ (~**−2 × amount**).

---

## Real example — 21 May 2026

**Txn 9616** — Reserve for contract #36

| | |
|---|---|
| Amount | 257,434.81 AMD |
| Entry | **Dr 73015** (expense) / **Cr 16605PS** (active) |
| Correct check | **0** |
| System shows | **−514,869.62** |

Same day **9615** (247,315.14 AMD, same accounts) → **−494,630.28** in the report.

**Txn 10620** (283,364.84 AMD, **16605PS → 63015** income) → **0** in report.  
→ Bug hits **expense + active**, not income pairs.

**21 May total (wrong formula):** about **−1.22M** extra from reserve volume — not from bad entries.

---

## After formula fix + exclude `8*`

| Date | Հաշվեշիռ |
|------|----------|
| 18–20 May | ~0 |
| 21 May | **−420.95** |
| 28 May | **+32,659** |

**21 May −420.95** = only 3 lines, all **86000/86001 → 16605PS** (Class 8 debit excluded, credit counted):

| ID | Amount | Note |
|----|--------|------|
| 9623 | 8.61 | Loss eff. interest, contract #2 |
| 9624 | 329.84 | Loss nominal interest, contract #2 |
| 9745 | 82.50 | Loss nominal interest, contract #55 |

**26 May +33k** — recoveries **11089/11090** (16605PS Dr, 86000/86001 Cr) — same one-sided counting.

---

## Action

| Who | What |
|-----|------|
| **Accounting** | Do not use Հաշվեշիռ for period close until fixed. Confirm: Class 8 fully out of check? |
| **IT** | Fix formula; exclude `8*`; agree rule for 86xxx ↔ 16605PS cross-postings |

---

## Accounts (quick ref)

| Code | Type |
|------|------|
| 73015 | Expense — reserve allocation |
| 16605PS | Active — special reserve |
| 63015 | Income — recovery |
| 86000 / 86001 | Off-balance (Class 8) |

*Questions: send transaction ID (e.g. 9616).*
