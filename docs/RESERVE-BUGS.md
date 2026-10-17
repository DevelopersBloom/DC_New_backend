# Client reserve bugs (16605PC / 16605PS)

Rule being checked: reserve per client = classification % x (16200NV + 16201NI + 16200) net.
Standard -> 16605PC, non-standard -> 16605PS. Set aside Dr 73015 / Cr 16605P*, release Dr 16605P* / Cr 63015.
DB percents: standard 1, monitored 10, substandard 20, suspicious 50, loss 100.

Method: read-only replay of the `lomb` DB ledger (ends 2026-09-23) for clients 131, 15 and 76.
Today 71 of 91 clients differ from the rule; most are over-reserved.
For client 76 the replay ignored `Reserve correction (` and `Zero 16605P*` rows to show what the automatic logic does alone.

## Summary

| # | Severity | Bug | Evidence |
|---|---|---|---|
| 1 | Money-wrong (largest) | Classification change posts reserve twice, and the second amount is inflated | Client 15 diff 4,449,399.26 = one posting; client 76 reserve 2.86M vs 412k without corrections |
| 2 | Money-wrong | Reclass transfer PC -> PS uses gross historic credits, not the PC balance | Client 15: PC -18,345,857.52, PS +23,381,975.39 |
| 3 | Money-wrong | Move to a better class posts more reserve expense instead of releasing | Client 76, 29 Jun: Dr 73015 / Cr 16605PC 38,193.82 when going 10% -> 1% |
| 4 | Money-wrong | Interest payments release no reserve | Clients 131, 15, 76: 645; ~2,000; ~9,000 per payment |
| 5 | Money-wrong | Back-dated payment rollback leaves reclass entries behind and re-accrues at the wrong percent | Client 131: 888.77 |
| 6 | Process | Manual standard vs nightly job flip-flop re-triggers 1-3 | Clients 15 and 76: 6-10 flips each |
| 7 | Process | Reserve repair is manual only | `CorrectAllClientReservesJob` is API-only |
| 8 | Minor | Closure residual reversals move net without adjusting reserve | Client 76, 31 Aug |
| 9 | Minor | Inconsistent document types on release; DB percents differ from CBA norms | `PaymentControllerNew.php:517`; monitored = 10% vs CBA Watch 5% |

## 1. Classification change posts reserve twice (inflated second amount)

`ClientClassificationService::applyClassificationIfWorse` first calls `correctClientReserveBalance`
([ClientClassificationService.php:125](../app/Services/ClientClassificationService.php)), which posts
the correct target (zero the other account, set this one to % x net).
It then runs a per-contract loop that posts another reserve entry
("Reserve for contract #X due to classification change", amount at line ~189):

    amount = (provided_amount + sum of GROSS prior reserve_special/reserve_general/effective_rate docs)
             x (newPercent - oldPercent) / 100

Problems:
- It duplicates what `correctClientReserveBalance` already did.
- The base counts every earlier reserve and interest-accrual posting, ignoring releases, corrections and deletions.
  It also grows with every flip. Client 76 implied bases: 2.90M, 4.18M, 5.90M, 8.92M against net of about 2.5M-4.1M.
- The same code is duplicated in `ClientControllerNew.php` (~lines 450-600, manual classification change).

Evidence: client 15, 2026-09-15: journal 39264 (correct) is followed by 39265 (+4,449,399.27) and 39266.
After that, diff = 4,449,399.27 permanently. Client 76 without corrections: 2.86M reserve vs 412,348.72 by the rule.

Fix direction: remove the loop's reserve posting and keep only the classification history row, `contract_reserve_histories` row and risk modification.
Let `correctClientReserveBalance` be the only place the reserve is set.

## 2. Reclass transfer uses gross credits

The "Old reserve for contract" transfer (PC -> PS or back) uses
`$amount16605PC` / `$amount16605PS` ([ClientClassificationService.php:162-186, 641-671](../app/Services/ClientClassificationService.php)):
the SUM of credits ever posted to the account, with no debits subtracted.
If the account was already zeroed or released, the transfer drives it negative.

Evidence: client 15, 2026-09-15: transfer 18,345,857.52 from 16605PC when PC was about 0.
Result PC -18,345,857.52, PS +23,381,975.39. The total looks plausible only because the two offset.

Fix direction: transfer the actual `getClientReserveBalance` of the source account, or drop the transfer
(`correctClientReserveBalance` already zeroes one account and sets the other).

## 3. Improvement posts expense instead of release

Moving from monitored (10%) to standard (1%) goes through the same loop with `amount = base x newPercent`
and `amount > 0` always books Dr 73015 / Cr 16605P* (an expense).
Client 76, 2026-06-29, 2026-07-01, 2026-07-22, 2026-08-25: each improvement ADDS 38k-71k of reserve.
The release only happens if a correction run is triggered later.

Fix direction: same as 1. Let the target-balance routine post the difference (release when lower).

## 4. Interest payments and scheduled principal release no reserve

The regular payment path (`PaymentControllerNew.php` ~177-250) posts interest and principal with no reserve release at all.
Only the full payoff (`PaymentControllerNew.php:509-550`) and the partial-extra path (`PaymentService.php:1210-1250`) release `principal x %`.
Interest payments (Dr cash / Cr 16201NI) and the scheduled principal part lower net but nothing is released.
Client 15, 13 Apr: principal 15,307.76 released nothing (153.08 missing) next to interest 217,800 (2,178.00 missing).
Per payment: client 131: 644.99 (22 Sep); client 15: 1,843-2,178 (13 Apr, 11 May, 11 Aug, 12 Sep);
client 76: 9,075 (9 Jun), 8,084 (11 Jul).
Mirror image: the daily accrual job reserves % of the EFFECTIVE accrual, so the rule is respected on the way up but not on the way down.

Fix direction: on interest and penalty payment, release `% x amount` (or recompute the client target after every payment).

## 5. Back-dated payment rollback

Back-dated payment -> `RecalculateContractRangeJob` -> `ContractDailyRateService.php:34-46` soft-deletes
EFFECTIVE_RATE, INTEREST_RATE, RESERVE_GENERAL, RESERVE_SPECIAL and PENALTY_RATE documents after the date.
It does NOT touch:
- the reclass document ("Պահուստների միջև վերադասակարգում");
- the RISK modification;
- the `contract_reserve_histories` and `classification_histories` rows.

The re-accrual then reads the live `client->classification` (`ContractDailyRateService.php:77`),
which may no longer match the rolled-back state.
Evidence: client 131, 2026-09-23: three reserve rows deleted, reclass 25,684.91 kept, re-accrual at 10%
(270.86 instead of 27.09).

Fix direction: include the reclass document type in the rollback, and revert the classification state or recompute from history by date.

## 6. Flip-flop between manual standard and nightly job

On the same overdue state, a user sets the client to standard by hand and the 22:00 / 00:00 job sets it back to monitored.
Client 15: 7 flips (Jun-Sep). Client 76: 9 flips. Each flip re-runs bugs 1-3.
Fix direction: a manual override should be sticky, or the job should respect the override until overdue days change.

## 7. Repair is manual

`CorrectAllClientReservesJob` and `/clients/correct-reserve(s)` are the only things that fully reconcile.
They are API-only (not in `Kernel.php`), so the ledger shows hundreds of "Reserve correction" rows.
Client 15 had zero repair after 15 Sep.
Fix direction: after fixing 1-4, schedule a nightly reconciliation that posts any difference to the rule target.

## 8. Closure residual reversals

Client 76, 2026-08-31: "Closure residual reversal" rows on 16200NV / 16201NI / 16200 change net with no reserve adjustment.

## 9. Minor

- Release document type is inconsistent: standard uses `PROVIDED_AMOUNT_CHANGE`, non-standard uses `RESERVE_SPECIAL_AMOUNT` for a release (`PaymentControllerNew.php:517`).
- DB percents (monitored 10%, substandard 20%, suspicious 50%, loss 100%) differ from the CBA norms the business quoted (Watch 5%).
  Confirm which is intended.
- `CorrectAllClientReservesJob` reads `classification_histories`, other paths read `clients.classification_id`.
  They disagree after bug 5.
