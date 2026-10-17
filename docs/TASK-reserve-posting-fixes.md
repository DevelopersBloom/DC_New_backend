# Task: fix client reserve postings (16605PC / 16605PS)

## Goal
At every moment, for every client:

    total reserve = classification % x (16200NV + 16201NI + 16200)        (net, per client)

- Standard client: all reserve on `16605PC`, `16605PS` = 0.
- Non-standard client: all reserve on `16605PS`, `16605PC` = 0.
- Percents come from the classification (`standard` 1, `monitored` 10, `substandard` 20, `suspicious` 50, `loss` 100).
- Example: net 100,000 AMD, standard -> reserve 1,000. After a 20,000 principal payment -> net 80,000, reserve 800.

Findings and evidence: [RESERVE-BUGS.md](RESERVE-BUGS.md). Today 71 of 91 clients differ from the rule.

## Posting rules (all amounts AMD, `documents_journal` + `transactions`)

| Action | Entry |
|---|---|
| Increase standard reserve | Dr `73015` / Cr `16605PC` (rule `reserve_general_amount`) |
| Increase non-standard reserve | Dr `73015` / Cr `16605PS` (rule `reserve_special_amount`) |
| Release standard reserve | Dr `16605PC` / Cr `63015` (rule `provide_general_amount_change`) |
| Release non-standard reserve | Dr `16605PS` / Cr `63015` (rule `provide_special_amount_change`) |

A classification change is **only** "release the old account to zero" + "set the new account to % x net".
There is no transfer between `16605PC` and `16605PS`, and no extra "reserve for contract".

Reserve account balance helper: `getClientReserveBalance` (credit minus debit, per client).
The routine that already does the right thing is `CorrectReserveTrait::correctClientReserveBalance`
([CorrectReserveTrait.php:137](../app/Traits/CorrectReserveTrait.php)). The fixes below make it the single place that sets the reserve.

---

## Bug 1 - Classification change posts reserve twice (second amount is inflated)

**What goes wrong.** `ClientClassificationService::applyClassificationIfWorse` calls `correctClientReserveBalance` (correct), then the
per-contract loop posts one more "Reserve for contract #X due to classification change" with
`amount = (provided_amount + SUM of gross earlier reserve/effective docs) x (newPercent - oldPercent)`.
The base ignores releases and grows with every classification flip. The same duplicate code is in `ClientControllerNew.php` (~lines 450-600).

**Real example - client 131, 23 Sep 2026, standard -> monitored (10%).**
Before: net 2,568,491.84, `16605PC` 25,684.91, `16605PS` 0.

Wrong (what the job posted):

| Doc id | Entry | Amount | OK? |
|---|---|---|---|
| 41424 | Dr 16605PC / Cr 63015 | 25,684.91 | yes |
| 41425 | Dr 73015 / Cr 16605PS | 256,849.18 | yes (10% of net) |
| 41426 | Dr 73015 / Cr 16605PS | **258,903.98** | **no - duplicate, wrong base** |
| 41427 | Dr 16605PC / Cr 16605PS | **25,684.91** | **no - see Bug 2** |

Result: `16605PC` -25,684.91, `16605PS` 541,438.07. Total reserve 515,753.16 instead of 256,849.18.

Right (exactly two entries):

| Entry | Amount |
|---|---|
| Dr 16605PC / Cr 63015 | 25,684.91 |
| Dr 73015 / Cr 16605PS | 256,849.18 |

Result: `16605PC` 0, `16605PS` 256,849.18.

**How to fix.**
- In `ClientClassificationService::applyClassificationIfWorse` (lines ~150-330) remove the posting of the "Reserve for contract ... due to classification change" journal and its transaction.
  Keep the `contract_reserve_histories` row, the `classification_histories` row and the RISK `Modification`.
  Store the real numbers in the history (`reserve_amount` = the target from `correctClientReserveBalance`, `meta.old_*` = balances before).
- Same cleanup in `ClientControllerNew.php` (manual classification change, ~lines 450-600, and the "(manual)" entries near line 902-922).
- Remove the now-dead `$reserveAmount` gross sum (lines ~165-175).

---

## Bug 2 - Reclass transfer uses gross credits, not the account balance

**What goes wrong.** The "Old reserve for contract #X due to classification change" entry (`classification_general_to_special` /
`classification_special_to_general`) transfers `$amount16605PC` / `$amount16605PS`: the SUM of every credit ever posted
to the account, with debits (releases, corrections) not subtracted.
When the account was already zeroed it is driven negative and the other account is inflated.

**Real example - client 15, 15 Sep 2026, standard -> monitored.**
Before the transfer: net 5,810,511.11, `16605PC` 0, `16605PS` 581,051.11 (already correct after doc 39264).

Wrong:

| Doc id | Entry | Amount |
|---|---|---|
| 39265 | Dr 73015 / Cr 16605PS | **4,449,399.27** (Bug 1) |
| 39266 | Dr 16605PC / Cr 16605PS | **18,345,857.52** |

Result: `16605PC` -18,345,857.52, `16605PS` +23,376,307.90 (+ later accruals). Diff vs rule: +4,449,399.26, permanent.

Right: nothing after doc 39264. `16605PC` 0, `16605PS` 581,051.11 (= 10% x 5,810,511.11).

**How to fix.** Delete the transfer (lines ~641-671 in `ClientClassificationService.php` and ~893 in `ClientControllerNew.php`) together with
`$amount16605PC` / `$amount16605PS`. `correctClientReserveBalance` already zeroes the old account and sets the new one,
using the real balance (`getClientReserveBalance`). If a transfer is ever kept, it must use the actual balance of the source account, never a sum of credits.

---

## Bug 3 - Moving to a better class posts more expense instead of releasing

**What goes wrong.** Monitored -> standard goes through the same loop and posts `amount = base x 1%` as Dr 73015 / Cr 16605PC. It always
adds reserve; it never releases.

**Real example - client 76, 29 Jun 2026, manual change monitored (10%) -> standard (1%).**
Before: `16605PS` 255,107.51, `16605PC` 0.

Wrong (after the two correct correction rows #18674-18675):

| Doc id | Entry | Amount |
|---|---|---|
| 18676 | Dr 73015 / Cr 16605PC | **38,193.82** |

Result: `16605PC` 63,704.57 instead of 25,510.75.

Right (exactly two entries):

| Entry | Amount |
|---|---|
| Dr 16605PS / Cr 63015 | 255,107.51 |
| Dr 73015 / Cr 16605PC | 25,510.75 |

**How to fix.** Same change as Bug 1 (remove the loop posting). Add a test that an improvement ends with `16605PS` = 0 and `16605PC` = 1% of net.

---

## Bug 4 - Payments do not release reserve (interest and scheduled principal)

**What goes wrong.** The regular payment path (`PaymentControllerNew.php` ~177-250: `interest_amount_payment` and `mother_amount_payment`)
posts no reserve release. Only the full payoff (`PaymentControllerNew.php:509-550`) and the partial-extra path
(`PaymentService.php:1210-1250`) release `principal x %`. Interest paid also lowers net (Cr 16201NI), so reserve must drop by `% x interest`.

**Real example - client 15, 13 Apr 2026, standard (1%), one payment day.**

| Part of the payment | Release that must be posted | What the system posted |
|---|---|---|
| Principal 892.24 | Dr 16605PC / Cr 63015 8.92 | 8.92 (doc 2728) |
| Interest 217,800.00 | Dr 16605PC / Cr 63015 **2,178.00** | **nothing** |
| Principal 15,307.76 | Dr 16605PC / Cr 63015 **153.08** | **nothing** |

Result: reserve 62,165.77 instead of 59,834.69 (+2,331.08). The gap equals the later manual correction (doc 4685, 2,331.08).
Same on client 76, 9 Jun: principal 9,128 and interest 90,746 released nothing (9,987.40 at 10%).

**How to fix.**
- After any payment is posted (regular, full, partial, prepayment application), call one routine that brings the client reserve to the rule target at the payment date
  (`correctClientReserveBalance` with the client's classification at that date). One call per payment, not one per posting row.
- Remove the inline `principal x %` release blocks (`PaymentControllerNew.php:509-550`, `PaymentService.php:1210-1250`) so nothing is released twice.
- The same recalculation must run when a payment is deleted or reversed (`FullPaymentDealReversalService`, `DeleteRegularPaymentDeal`), because net goes back up.
  Today the reversal only adds a warning (`FullPaymentDealReversalService.php:390`).
- Use `provide_special_amount_change` as the document type for a non-standard release (today `PaymentControllerNew.php:517` uses `RESERVE_SPECIAL_AMOUNT`).

---

## Bug 5 - Back-dated payment leaves a half rolled-back reserve

**What goes wrong.** A payment dated before today triggers `RecalculateContractRangeJob` -> `ContractDailyRateService.php:34-46`, which soft-deletes
EFFECTIVE_RATE, INTEREST_RATE, RESERVE_GENERAL, RESERVE_SPECIAL and PENALTY_RATE documents after the date. It does not delete the
reclass document, the RISK modification, the `contract_reserve_histories` row or the `classification_histories` row. The re-accrual then reads the live `client->classification` (line 77).

**Real example - client 131, interest 64,500 dated 22 Sep, entered 23 Sep 12:20 (after the 23 Sep 00:00 classification job).**
The overdue is cleared, so the client must be standard (1%) on 23 Sep.

Wrong:
- Docs 41424-41426 and the 23 Sep accrual were deleted, but doc 41427 (Dr 16605PC / Cr 16605PS 25,684.91) stayed.
- RISK modification = 2 and `contract_reserve_histories` row (9%, 258,903.98) stayed.
- 23 Sep re-accrual: Dr 73015 / Cr 16605PS **270.86** (10% of 2,708.60).

Right:
- No classification-change documents or history rows for 23 Sep (client stays standard, RISK 1).
- 23 Sep re-accrual: Dr 73015 / Cr 16605PC **27.09** (1% of 2,708.60).
- Reserve after the payment: `16605PC` = 1% x net, `16605PS` = 0.

**How to fix.**
- In `ContractDailyRateService.php:34-46` also delete the reclass document types and any classification-change documents dated after `$from`, and the `contract_reserve_histories` / `classification_histories` rows and RISK modifications created for those dates.
- Before re-accruing, recompute the classification as of each date (`maxOverdueDaysForClient` as-of date, or read `classification_histories` by date) instead of the live `client->classification`.
- Finish with the same "bring reserve to target" call as Bug 4.

---

## Bug 6 - Manual standard vs nightly job (decision needed)

Clients 15 and 76 were flipped 7-9 times: a user sets standard by hand, the job sets monitored again the same night
(client 76: manual 29 Jun 15:51, job 22:00). Each flip repeats Bugs 1-3. Ask product: should a manual change be sticky until the overdue
days change, or does the job always win? Implement after Bugs 1-5; not blocking.

## Bug 7 - Nightly reconciliation (after Bugs 1-5)

`CorrectAllClientReservesJob` (reads `classification_histories`) is API-only. Once postings are correct, schedule it (or a read-only report)
nightly to report clients where `|actual - rule| > 0.05 x number of days` and fix any drift. It must use the same classification source as the rest (history by date, not `clients.classification_id`).

## Small cleanups
- `Closure residual reversal` rows (client 76, 31 Aug) change net without a reserve adjustment; covered if Bug 4's recalculation runs there too.
- Confirm percents: DB has monitored 10%, business list says Watch 5%.

---

## Acceptance tests (PHPUnit, real controllers, each asserts the invariant after every step)
Invariant: `PC + PS == round(% x net, 2)` (tolerance 0.05), and `PS == 0` when standard, `PC == 0` when non-standard.

1. Disburse 100,000, standard -> `PC` 1,000. Pay 20,000 principal -> `PC` 800.
2. After accruals, pay 10,000 interest -> `PC` drops by 100.00 (1% x 10,000). Then pay scheduled principal -> drops by 1% of it.
3. Standard -> monitored (client 131 shape): exactly two new reserve entries (release old account, set new account to 10% x net), no transfer row, no "Reserve for contract" row.
4. Monitored -> standard (client 76 shape): `PS` ends 0, `PC` = 1% x net, no extra expense row.
5. Standard -> monitored, then a back-dated payment that clears the overdue: no classification documents or history rows remain for that date, client stays standard, 23 Sep accrual reserve = 1% of effective accrual on `16605PC`.
6. Delete the payment from test 2 -> reserve goes back up by the same amount.
7. Flip standard -> monitored -> standard -> monitored five times: each flip leaves exactly the target, total reserve never exceeds 10% x net.
8. Full payoff -> `PC` = `PS` = 0.

Report back with: before/after output of `GET /accounts/reserve-balance-check` on a restored copy of `lomb`.
