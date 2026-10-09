# /reports — source of truth

Every number on `/reports` has one business definition, one authoritative source, one date rule and one formula.
Machine-readable definitions of the Financial Indicators figures: `App\Services\Reports\ReportKpiRegistry` (the UI
tooltips are read from it through the API). Validated on the `lomb37` dump (2026-10-05).

## 1. Table-by-table source map

| Table | Authoritative for | Must NOT be used for |
|---|---|---|
| `contract_amount_histories` (`amount_type`, `type` in/out, `category_id`, `deal_id`, `date` DATE, `deleted_at`, `pawnshop_id`) | Estimated collateral and outstanding principal **as of any date** (signed in − out); first disbursement / top-up events; category of a *movement* | Cash movement; repayment split into principal/interest/penalty; rows whose `deal_id` points at a soft-deleted deal are not reversed automatically (reported, see §6) |
| `deals` (`type`, `filter_type`, `cash`, `date` **varchar**, `deleted_at`, `pawnshop_id`) | Operational cash and bank movement; customer receipts (`in` + `payment`/`full_payment`); repayment deal totals (PaymentHistoryResolver) | Disbursement amount/date (the history is: the deal date can differ from the logical disbursement date); bank-statement balance (no opening balance, no bank-statement import) |
| `contracts` (`provided_amount`, `estimated_amount`, `status`, `closed_at`, `deadline`, `category_id`) | Contract identity, client, `deadline`, `closed_at`, current category; **current** state | Any historical figure. `provided_amount` is overwritten by every top-up/repayment/closure (set to 0 on full payment) and `estimated_amount` by revaluations: **UNSAFE FOR HISTORICAL REPORTING**, used only to reconcile today's history net |
| `payments` | The repayment **schedule** (`date` = due date, `principal_payment`, `interest_payment`, `original_*`) | Actual payments (`paid/status/remaining/amount` are current state; rows are zeroed in place and rebuilt by soft-delete) |
| `payment_entries` | Actual payments from mid-July 2026 (`date`, `principal/interest/penalty_amount`, `payment_id`, `deal_id`) | Payments before mid-July (no entries); not soft-deleted, so a reversed entry cannot be reconstructed |
| `deal_actions` | Pre-payment schedule state (`history.payment_changes[]`) and allocation for repayments recorded before entries existed | Anything outside `PaymentHistoryResolver` (it is the only reader; do not re-implement) |
| `categories` | Category title/name (`id` 1 gold, 2 `electronics` = "Անշարժ գույք", 3 car, 4 car-purchase) | Classification of a movement (the history row's `category_id` does that) |
| `chart_of_accounts` + `transactions` | Accounting ledger (turnover, T-account, forms) | Operational cash/bank on `/reports`: no `pawnshop_id` (cannot be tenant-scoped), different concept (bank = account 10210) |
| `orders`, `histories` | Receipts/documents, history events | Amounts on `/reports` |

## 2. Figure inventory — Financial Indicators tab (rebuilt)

| Figure (UI) | Class | OLD source / formula | NEW source / formula | Why |
|---|---|---|---|---|
| Գնահատված գրավ | A stock | history `estimated_amount` in−out ≤ D (same) | same, via `stockByCategory` opening + daily deltas | already correct; shared with management summary |
| Տրամադրված → **Վարկային պորտֆելի մնացորդ** | A stock | history `provided_amount` in−out ≤ D, labelled "disbursed" | same figure, renamed | it was a stock called by a flow's name |
| *(new)* Վարկ / գրավ % | H | – | principal ÷ collateral × 100 | same ratio as summary |
| Դրամարկղ | A stock | **all** `in` deals − all `out/expense/cost_out` deals, cash **and** bank, no flag | `cash=1` deals only (in − out/expense/cost_out), valid date, not deleted | old figure = cash + bank (−38.6M on 30 Sep) |
| Անկանխիկ Դր. → **Անկանխիկ (գործառնական)** | A stock | `cash=0` subset (same) | same, labelled operational | not a bank balance: ledger 10210 is +71.5M, see §6 |
| Անշարժ գույք (category 2) | A stock | `= 8,970,000` constant from 2025‑08‑01 (overwrote the real value) | history estimated net by category | real value on 30 Sep: 70,070,000 |
| Մեքենա (category 3) | A stock | history net **+ 21,880,000** constant | history estimated net by category | constant removed; 199,600,000 (was 221,480,000) |
| Ոսկի, Մեքենայի ձեռք բերման վարկ | A stock | no column | columns from `categories` (+ "Առանց տեսակի" when a row has no category) | category totals now sum to the total |
| *(new)* Նոր վարկեր / Top-up / Օրվա տրամադրում | B flow | – | `disbursementEvents` (first vs top-up, same-deal rows folded) | same events as management summary |
| *(new)* Ստացված վճարումներ | E | – | `deals` in + `payment`/`full_payment` | repayment deals as in PaymentHistoryResolver; total only |
| *(new)* Մայր գումարի նվազում | B flow | – | history `provided_amount` out | roll-forward: opening + disbursement − reduction = closing |
| Export "Ներգրավված", "Ապպա" | – | `item.ndm`, `item.appa`: **never returned by the API → blank columns** | removed | no real data behind them |

Category columns can show three different things (selector above the table, never mixed in one header):
estimated collateral (default, the old meaning), outstanding principal, day's disbursement.

## 3. Figure inventory — Management summary (Tranches 1–4.1, validated, reused)

| Block | Class | Authoritative source | Date rule |
|---|---|---|---|
| Estimated collateral, outstanding principal, ratio, category stock | A | history in−out | ≤ end date |
| Cash, bank | A | deals by `cash` flag (`dealBalances`) | `deals.date` ≤ end date |
| Total / new / top-up disbursement, counts, borrowers, average, median, shares, trend, drivers, borrower mix | B/F/G | `disbursementEvents` + `flow()` | logical disbursement date |
| Disbursement method (cash / non-cash) | B | `deals` out/contract by `cash` | `deals.date`; reconciled to history |
| Origination LTV | H | first disbursement ÷ estimate in force that day | logical disbursement date |
| Active portfolio, maturity, age, concentration, exceptions (T3) | A/C/J | history net per contract + `contracts.closed_at/deadline/status` | as of end date |
| Delinquency, PAR, DPD, collection rate, overdue roll-forward (T4) | D/E | `payments` schedule + `payment_entries` / `deal_actions` via `PaymentHistoryResolver`, `OverdueScheduleService` | as of date; only rows existing at D |

No hard-coded financial amount is left in the report path. The only constants are documented thresholds:
`MATERIALITY_AMD` (100), `OVERDUE_MIN_AMOUNT` (Form 6 / `Contract::is_overdue`), `OverdueScheduleService::NOISE` (0.5).

## 4. Definitions

* **Stock** = value as of date D (never summed across days). **Flow** = sum over the days (never replaced by a closing balance).
* **Outstanding principal(D)** = Σ `provided_amount` in − Σ out, history date ≤ D, not soft-deleted, pawnshop.
* **Estimated collateral(D)** = same on `estimated_amount`.
* **Cash(D)** = Σ deals(cash=1): `in` − (`out`+`expense`+`cost_out`); **Bank(D)** same with cash=0. Includes the cash→bank
  transfer legs of `addCashbox` (an `in` bank + an `out` cash deal with no `filter_type`) so a transfer moves money between
  the two without changing the total.
* **Disbursement** = first-disbursement events + top-ups (history), dated by the first history row of the logical deal.
* **Repayment (receipts)** = deals `in` with `filter_type` payment / full_payment. Principal/interest/penalty split is **not**
  shown daily: it exists only for fully settled rows (PaymentHistoryResolver); a partial settlement has no split and is never estimated.

## 5. Deal classification (deals.type + filter_type, lomb37)

| type / filter_type | Event | Cash/bank | Disbursement | Receipt |
|---|---|---|---|---|
| in / payment, full_payment | Repayment | + (by flag) | – | yes |
| in / partial_payment | Legacy partial repayment (1 deleted row) | + | – | no |
| in / ndm | Borrowed funds received | + bank | – | no |
| in / NULL | Cashbox/bank top-up, unknown sender | + | – | no |
| in / contract | One-off fee (600 AMD ×6) | + | – | no |
| in / expense | Refund of a wrongly charged amount | + | – | no |
| out / contract | Loan disbursement (opening, top-up) | − | evidence only | – |
| out / NULL | Cashbox → bank transfer (cash leg) | − cash | – | – |
| out / refund_lump | Refund of a lump payment | − | – | – |
| cost_out / expense | Expense | − | – | – |
| cost_out / ndm | Borrowed funds repaid | − | – | – |

## 6. Findings (reported, nothing was changed in the data)

1. **Bank is a net operational flow, not a balance.** 30 Sep: deals bank = −38.9M, ledger 10210 = +71.5M (system data starts 2026-02, no opening balance; funding injections are not all booked as `in/NULL` deals). Cash: deals 306,670 vs ledger 10000 ≈ 0.78M (2026‑10‑05: 724,650 vs 779,167).
2. 7 active history rows are tied to soft-deleted deals (e.g. history 12/13 → deal 10; 76 → deal 46; 726 → deal 653): the reversal did not reverse them. They are still counted (same as the management summary) and surfaced in `reconciliation.data_quality.history_on_deleted_deals`.
3. History vs `contracts.provided_amount` (today): 15 contracts differ, Σ −1,798,153.52 (cents-level residues plus the rows above).
4. Real-estate contract: principal exists 2026‑04‑28 but the estimate row only on 2026‑05‑22 → category 2 shows principal without collateral in April/early May.
5. 2 history rows carry a category different from their contract's.
6. 3 `out/contract` deals have no history row (disbursement not in the stock).
7. `PUT /contract-amount-histories/{id}` resolves `contract_num` without a pawnshop filter (tenant-isolation gap, not touched here).
8. Pre-existing failures outside reports: `CountPenaltyTest` (2, `payment_entries.user_id` has no default), `DealsTableOnlyUpdateServiceTest` (1).

## 7. Reconciliation (returned by the API as `reconciliation`)

Closing row vs `stockByCategory`/`dealBalances` at the end date; Σ daily disbursement vs `CreditActivityReportService::flow`;
Σ categories vs totals (a NULL category is its own bucket); cash/bank breakdown by type/filter_type (included and excluded);
history net vs `contracts.provided_amount` (as of today, diagnostic only); data-quality counters. Tolerance 0.05 AMD
(rounded daily rows); values are rounded only when emitted.
