# Regenerate schedule after a principal payment — final plan (awaiting approval)

Scope: backend (Laravel, `DC_New_backend`) and frontend (React/Redux, `DC_New_frontend`). Paths are relative to `DC_New_backend/` unless prefixed `FE:` (= `DC_New_frontend/src/`).
Status: PLAN ONLY. No application code was changed.
Evidence: every code claim was read in the repository. Every data claim about A-26-00115 (contract id 122) was read from `lomb-65.sql` (dump taken 2026-10-30 23:44) with `grep`/`awk` only; nothing was run against a live database. Items still needing a decision are in section 11.

---

## 1. Summary

**Goal.** After a client pays down principal, rebuild the *future* schedule on the reduced balance so the instalments shrink, **keep the deadline and the due-day cadence, keep all history**. It is the mirror of reprovide (reprovide raises the balance and restarts the term; this lowers it and keeps the end date).

**What the dump shows for A-26-00115** (section 2.1): the 540,000 payment is already recorded correctly (interest 52,800 to 10-30, principal 487,273), the balance is 1,512,727, but the schedule was not re-amortized: the next 28 rows are "interest only" and the original 77,702 instalments return from 2029. The new schedule (section 7) is **58 equal-ish rows of ≈ 59,423** ending **2031-09-09**.

**Why the existing button cannot do it, and why the existing rebuilds are not reusable as they are** (section 2.2): it deletes everything and refuses on any paid row; the rebuild helpers ignore the remaining term; the open rows carry principal-prepayment entries that any rebuild has to preserve; hard deletes cascade into prepayments.

**Design in one paragraph.** One service, `ScheduleRegenerator` (preview → apply → revert). The *current row* (the open row whose period contains the effective date) and everything before it are kept. Later open rows are replaced by a new annuity on the balance left after the current row, with due dates on the contract's `payment_day`, ending at the business day of `contracts.deadline`. Principal-prepayment entries sitting on the replaced rows are re-pointed to the current row (amounts, dates, deals untouched) and the current row's gross principal is raised by the same amount, so every existing "entries vs row" calculation keeps working. Guards refuse the unusual cases; everything is audited and reversible.

---

## 2. Findings

### 2.1 What the dump shows (A-26-00115, contract 122)
- Header: 2,000,000 disbursed 2026-09-09, `deadline` 2031-09-09, `deadline_days` 60, `interest_rate` 0.11 (% per day), `payment_type` amortized, **`payment_day` 6**, `provided_amount` now **1,512,727**, status initial, `mother` 2,000,000.
- Schedule rows (`payments`, regular): id 9919 = stub 2026-09-09 → 10-06 (27 days, interest 59,400, **completed**); ids **9920-9979 = 60 open rows**, anchor day 6, **last row 2031-10-06** (not the deadline). Penalty row 10799 (77, 10-07).
- Payments accepted:
  - Deal 773, 2026-10-07: 59,550 = 59,400 interest + 73 on row 9920 + 77 penalty.
  - **Deal 866, 2026-10-30, "Հերթական վճարում" (type payment, `is_recount = 0`): 540,000 = 52,727 interest + 487,273 principal.** Entries: one `regular_payment` entry of 52,727 interest on row 9920 (with the earlier 73 = **52,800 = 24 days × 2,200**, i.e. interest from 10-06 to 10-30 fully charged) and **29 principal-only `partial_payment` entries on rows 9920-9948** (each = that row's principal, the last one 7,024.69 of 30,894.24), `contract_amount_histories` out 487,273.
  - No `prepayments` rows for contract 122.
- Row state now: row 9920 (due 11-06) interest 64,448.00 (period split at 10-30 correctly), entries 52,800 interest + 9,502.59 principal, **11,648.00 still due**. Rows 9921-9947: principal fully covered by entries, **only interest due (≈ 46-55 k)**. Row 9948 partly covered. Rows 9949-9979: untouched 77,702.59 instalments, last 94,144.56.
- Gross principal of open rows = 2,000,000.00; principal entries on them = 487,273.00; **net = 1,512,727.00 = `provided_amount`**.
- `contracts.payment_schedule` JSON still holds the **original** 61-row schedule (not synced after the payments).
- Remaining scheduled interest on rows 9921-9979 = **2,352,428.55** (plus 11,648 on row 9920).

### 2.2 The "Regenerate" button today
Flow: `FE: components/PaymentsList/PaymentsList.jsx` (button `:1463`, modal `:2149`, handlers `:1187-1230`) → `FE: store/slices/contracts/slice.js:128` → `POST /contracts/regenerate-schedule` (`routes/api.php:323`) → `ContractControllerNew::regenerateSchedule` (`:1552-1595`) → `ContractService::regenerateSchedule` (`:1030-1068`).
- Throws "Cannot regenerate schedule when completed payments exist." on the first completed regular row (`:1041`). Contract 122 has one (9919), so the button cannot work for it.
- `Payment::where(contract_id)->forceDelete()` removes every payment row of the contract incl. penalty rows (`:1050`); rewrites `contracts.date`, `provided_at`, `deadline_days` (`:1052-1056`).
- Controller validates only `contract_id`, `start_date`; `payment_day` from the modal is ignored. No `can:` middleware (compare `routes/api.php:316-318`), no `PostingDatePolicy`, no audit; only `executed` is refused.
- `payment_entries.payment_id` is `restrict` (delete fails once any entry exists); `prepayments.payment_id` is `cascadeOnDelete` (`2026_06_16_000001_create_prepayments_table.php:14`) so a hard delete silently drops deferred 39920 buckets.
- `tests/Unit/ContractServiceRegenerateScheduleTest.php` tests a copy of the month formula, not the service.

### 2.3 The existing rebuild helpers are not reusable as they are
- `buildAnnuitySchedule` ignores its `$months` argument and start date; it uses `deadline_days` (`ContractService.php:824-825`) and anchors rows to the start date's day. `payPartial(is_recount)` (`PaymentService.php:446-480`) and `rebuildScheduleFromDate` (`ContractService.php:1072`, reprovide) go through it. Reprovide only works because the user retypes `months` and `deadline` is moved (`ContractControllerNew.php:1043-1050`, `FE: PaymentsList.jsx:1136`).
- `rebuildScheduleFromDate` soft-deletes **all** open rows (it would drop rows with entries), and overwrites `payment_schedule` with only the new rows (`:1180-1183`).
- Contracts with `payment_day` (122) use the broken-period builder (`createAnnuityPayment :910-969`, `computeBrokenPeriodAnnuitySchedule :722-808`), which honours the term but **always ends one month after the deadline** (contract 122: 2031-10-06 vs 2031-09-09). This is existing behaviour for every `payment_day` contract.
- `PaymentControllerNew::payPartial` passes a null date to the service (`:719`): a recount on a back-dated posting date rebuilds from today.

### 2.4 How an excess payment is stored (the case in the dump)
`PaymentService::payPartial(..., is_remaining_payment = true)` non-recount branch reduces `provided_amount` and spreads the reduction over the open rows (`ScheduledPaymentHandler::processAmortized`), writing **one principal-only `partial_payment` entry per affected row** (`PaymentService.php:566-609`). Rows keep their gross `principal_payment`; "paid" is expressed only by entries. Several calculations net all entries of a row (`ScheduledPaymentHandler.php:50,62,91`, `PrepaymentHandler.php:44-62`, `PaymentService.php:975-1070`, `Contract.php:323`, `ContractTrait.php:557,914`). Therefore:
- The invariant is **Σ(principal_payment − principal entries) over open rows = `provided_amount`**, not Σ principal_payment.
- Any regeneration that removes a row with such entries must carry them to a surviving row (otherwise they disappear from `PaymentEntry::payment()`, which is a plain `belongsTo` - `app/Models/PaymentEntry.php:55` - and from `scheduleState`, which feeds `periodInterest` for the current row, `ScheduledPaymentHandler.php:260-300`).
- For the recount branch (`is_recount`) the whole amount goes on the first new row (`PaymentService.php:614-650`), which inflates that row; not the case in this dump but the guard/relocation must cover it.

### 2.5 Other findings that affect the plan
- **Reversal hazard.** `AdminControllerNew::handlePartialPaymentDeal` (`:962-969`) force-deletes open rows by `created_at` and restores `deleted_payment_ids`; `RegularPaymentDealReversalService` restores rows from `DealAction` history by row id. After a regeneration, reversing a deal that touched replaced rows would corrupt the schedule.
- **Separate bug seen in the data (outside this feature).** `modifications` id 1779 for deal 866 records `PrincipalAmount` old **1,512,727** → new **1,025,454**; it should be 2,000,000 → 1,512,727. `payPartial` reduces `provided_amount` first and then logs `old_value = provided_amount` (already reduced) and `new = provided - partial` again (`PaymentService.php:507-518`, also `:529`). The registry modification history is wrong for every non-recount excess payment. Recommend a separate ticket.
- `contracts.payment_schedule` JSON is written only by `ContractService` and read by nothing else (`grep payment_schedule`); it is a stale snapshot, so it is synced for consistency but is not a source of truth.
- Daily jobs `ProcessContractDailyRate`, `ProcessDailyNdmInterest` do not read payments/schedule. `MarkDuePrepaymentsPaid` works from `prepayments.due_date`.
- Backend tests use the configured MySQL with `DatabaseTransactions` (`phpunit.xml` sqlite lines commented out). The frontend has no test runner, toast library or i18n layer.

---

## 3. Design

### 3.1 Semantics
Mode `remaining_balance` only (the old "full reset" is dropped; the old route is kept as a safe wrapper). From the **current row** onward:
- `effective_date` (default today; validated by `PostingDatePolicy`).
- **Current row** = first open regular row with `to_date >= effective_date` and `from_date < effective_date` (for 122: row 9920, 10-06 → 11-06). If the first open row is already past due (`to_date < effective_date`) → `OVERDUE_ROWS`.
- Rows before and including the current row keep dates, interest and status. **The payment in progress needs no stub**: the current row's interest is already split at the payment date by `periodInterest`.
- Opening balance of the new rows `B0 = provided_amount − unpaid principal of the current row` (for 122: 1,512,727 − 0).
- New rows: due dates on the anchor day (`payment_day`, else the day of the first scheduled due date), business-day shifted with `getNextWorkingDay`, from the month after the current row up to the deadline month; **the last row is due on the business day of `contracts.deadline`** (decision D-END, section 11).
- Annuity sized as in `computeBrokenPeriodAnnuitySchedule`: PMT on nominal monthly rate `rate×365/12`, interest per row = balance × actual days × `rate/100`, last row takes the remaining principal; service fee likewise if `fee_annual_rate > 0`. The calculation is extracted into a pure class `AnnuityScheduleCalculator` that accepts opening balance, first period start, anchor day, deadline.
- `contracts.date`, `provided_at`, `provided_amount`, `interest_rate`, `deadline`, `deadline_days` are never modified.

### 3.2 Entries on replaced rows
Inside the apply transaction, for each replaced open row:
1. `partial_payment` entries (principal-only, any deal): `UPDATE payment_entries SET payment_id = <current row id>` (amount, principal, date, deal, balances untouched); the current row's `principal_payment`/`original_principal_payment` and `amount` are raised by the relocated principal so that `amount − Σ entries.amount` and the principal netting stay correct (row 9920: principal 9,502.59 → 487,273.00; amount 73,950.59 → 551,720.99; entries 540,073.00; **still due 11,648.00**).
2. Any other entry type (`regular_payment`, `penalty_payment`, interest collections, prepayment-related) on a row that would be replaced → refuse with `ROW_HAS_COLLECTIONS` (v1).
3. All moves are written to the audit row so revert can restore `payment_id`, principal and amount exactly.
Why relocate instead of leaving the entries on soft-deleted rows: they would vanish from the current row's interest split and from reports that join `payment`.

### 3.3 Invariants (asserted inside the transaction; failure = rollback)
1. Σ(principal_payment − principal entries) over open regular rows = `provided_amount` (±0.01).
2. Last open row `date` = business day of `contracts.deadline`; no open row after it; last `remaining = 0`, `last_payment = true`.
3. Row interest = `round(opening_balance × days × rate/100, 2)` (fee analogously).
4. Everything not in the replaced set is byte-identical: completed rows, the current row's interest/dates, all entries' amounts/dates/deals, journals, transactions, deals, deal_actions, penalty/full/partial rows (checksum of ids + amounts before/after).
5. Entry totals per deal unchanged (e.g. deal 866 still sums to 540,000 principal+interest).
6. `payment_schedule` JSON rebuilt from the regular rows.
7. `countPenalty` unchanged; no unpaid prepayments.

### 3.4 Guards (v1; each returns `error_code` + Armenian `message`)
| code | condition |
|---|---|
| `NOT_ALLOWED` | missing permission |
| `CONTRACT_NOT_ACTIVE` | status executed / cancelled / taken, or not disbursed |
| `NO_BALANCE` | `provided_amount <= 0` |
| `FUTURE_DATE` `BACKDATE_NOT_ALLOWED` `PERIOD_CLOSED` | `PostingDatePolicy::validate` |
| `DATE_BEFORE_LAST_PAYMENT` | effective date earlier than the latest entry date |
| `OVERDUE_ROWS` | an open row with `to_date < effective_date`, or penalty > 0 (same rule as reprovide) |
| `ROW_HAS_COLLECTIONS` | replaced row holds non-principal entries |
| `UNPAID_PREPAYMENTS` | unpaid 39920 prepayments exist |
| `NO_FUTURE_ROWS` | nothing to replace after the current row |
| `STATE_CHANGED` (409) | `state_hash` differs from the preview |
| `ALREADY_RUNNING` (409) | lock held |

### 3.5 Concurrency, idempotency, audit, revert
- `DB::transaction`: `lockForUpdate` on the contract, then its open rows; recompute `state_hash = sha1(provided_amount, ids+updated_at of open rows, entry count+sum, unpaid prepayment count)` and compare.
- If the generated rows equal the existing ones → `changed:false`, nothing written.
- New table `schedule_regenerations`: `id, contract_id, user_id, effective_date, current_payment_id, anchor_day, reason, balance, old/new monthly, old/new total_interest, old/new last_date, removed_payment_ids, created_payment_ids, moved_entries (json: entry_id, old_payment_id), current_row_before (json: principal, original_principal, amount), old_payment_schedule (json), created_at, reverted_at, reverted_by`; plus `ActivityService->log('regenerate_schedule', …)`.
- Revert (latest only; no entry on created rows; no later regeneration): soft-delete created rows, restore removed rows, move entries back, restore the current row, restore the JSON.
- Reversal guard in `AdminControllerNew` and `RegularPaymentDealReversalService`: refuse reversing a deal dated on or before the latest non-reverted regeneration of its contract ("Revert the schedule regeneration first").
- New rows get `PGI_ID = max(PGI_ID incl. trashed) + 1…`.

### 3.6 Permission
`regenerate_contract_schedule` (admin by default) as middleware on all routes + the `PostingDatePolicy` back-date rule.

---

## 4. API contract
```
POST /contracts/regenerate-schedule/preview       can:regenerate_contract_schedule
POST /contracts/regenerate-schedule               can:regenerate_contract_schedule
POST /contracts/regenerate-schedule/{id}/revert   can:regenerate_contract_schedule
GET  /contracts/{contractId}/schedule-regenerations   can:view_contracts
```
Request (preview/apply): `{ contract_id, effective_date, payment_day?, reason, state_hash (apply only) }`.
Preview 200 (values for 122): 
```json
{ "effective_date":"2026-10-30", "current_row":{"id":9920,"due":"2026-11-06","due_left":11648.00},
  "anchor_day":6, "balance":1512727.00,
  "kept_rows":2, "removed_rows":59, "created_rows":58, "moved_entries":28,
  "old":{"monthly_min":51583.99,"monthly_max":94144.56,"total_interest":2352428.55,"last_date":"2031-10-06","rows":[]},
  "new":{"monthly":59423.05,"total_interest":1939417.42,"last_date":"2031-09-09","rows":[]},
  "warnings":[{"code":"LAST_ROW_DIFFERS_FROM_OLD","message":"…"}],
  "state_hash":"…" }
```
Apply 200: summary + `{ regeneration_id, changed }`. Errors 4xx `{ error_code, message }`.

---

## 5. Backend work
| # | Item | Files |
|---|---|---|
| B1 | Migration + model `schedule_regenerations` | `database/migrations`, `app/Models/ScheduleRegeneration.php` |
| B2 | Permission seeded, admin role | `RolePermissionService`, seeder |
| B3 | `AnnuityScheduleCalculator` extracted from `computeBrokenPeriodAnnuitySchedule` (+ deadline handling), single JSON builder, `PGI_ID` incl. trashed | `app/Services/ContractService.php`, new class |
| B4 | `ScheduleRegenerator` (preview/apply/revert, guards, entry relocation, invariants, hash, audit) | `app/Services/Schedule/ScheduleRegenerator.php` |
| B5 | Form requests, controller, 4 routes | `routes/api.php` |
| B6 | Old route made safe (soft delete, guards); reversal guards | `ContractService::regenerateSchedule`, `AdminControllerNew.php:962`, `RegularPaymentDealReversalService` |
| B7 | Phase 3: `payPartial` recount uses the calculator, honours the posting date, attaches its entry per 3.2 | `PaymentService.php:446-480,614-650`, `PaymentControllerNew.php:719` |
| B8 | Tests (section 8) | `tests/Unit`, `tests/Feature` |

## 6. Frontend work
Existing: state `PaymentsList.jsx:90-93`, `canRegenerateSchedule :254` (role check), handlers `:1187-1230`, button `:1463`, modal `:2149`, reprovide date helpers `:197-215`, `utils/permissions.js::hasPermission`, `UI/ComplexModal`.
| # | Item |
|---|---|
| F-1 | New `components/PaymentsList/RegenerateScheduleModal.jsx` (file is already 2,421 lines); remove the old modal/handlers/dead `payment_day` code |
| F-2 | `canRegenerateSchedule` = `hasPermission(user,"regenerate_contract_schedule")` and not executed/cancelled, `provided_amount > 0`, not in edit mode |
| F-3 | Thunks `previewRegenerateSchedule`, `applyRegenerateSchedule`, `revertRegenerateSchedule`, `fetchScheduleRegenerations`; rejections keep `{message,error_code}`; apply refetches `getContractById` |
| F-4 | Step 1: effective date (min = latest payment date, max = today), payment day (prefilled), reason (required); «Նախադիտել» |
| F-5 | Step 2 preview: cards (balance, current-row amount still due, monthly old range → new, total interest old → new, last date old → new vs deadline), old/new rows table with changed cells highlighted, warnings, «Հաստատել»/«Հետ»; confirm disabled while loading |
| F-6 | Inline error text from `error_code`; `STATE_CHANGED` refreshes the preview; `changed:false` → «Փոփոխություն չկա»; no `alert`/`confirm` |
| F-7 | History «Գրաֆիկի փոփոխություններ» with «Հետադարձել» on the latest |
| F-8 | Armenian strings in one constants object (English only if requested) |
| F-9 | Verification: browser run on a staging copy of 122, each error code, narrow width, `npm run build`; optional `vitest` for pure helpers |

Armenian errors: `CONTRACT_NOT_ACTIVE` «Պայմանագիրը փակ է կամ կատարված, գրաֆիկը չի կարելի փոխել»; `NO_BALANCE` «Վարկի մնացորդը 0 է»; `OVERDUE_ROWS` «Կան ժամկետանց վճարումներ ({amount}֏), նախ մարեք դրանք»; `ROW_HAS_COLLECTIONS` «{n}-րդ վճարումը մասամբ վճարված է. ընտրեք նրա վճարման օրվանից հետո ամսաթիվ»; `UNPAID_PREPAYMENTS` «Կան չկիրառված կանխավճարներ (39920), նախ կիրառեք դրանք»; `DATE_BEFORE_LAST_PAYMENT` «Ամսաթիվը վերջին վճարումից շուտ է»; `NO_FUTURE_ROWS` «Փոխարինելու ապագա վճարումներ չկան»; `STATE_CHANGED` «Պայմանագիրը փոխվել է, նորից դիտեք նախադիտումը».

---

## 7. Golden numbers (A-26-00115)
Computed with the existing function's convention (PMT on `0.0011×365/12`, interest = balance × actual days × 0.0011, business-day shift with the holiday list of `getNextWorkingDay`: 01-01, 01-02, 01-06, 01-27, 01-28, 03-08, 04-24, 05-01, 05-09, 05-28, 07-05, 09-21, 12-31).
- Kept: row 9919 (completed); row 9920 (due 2026-11-06) with relocated principal: principal 487,273.00, interest 64,448.00, amount 551,720.99, entries 540,073.00, **due 11,648.00**.
- Opening balance for new rows B0 = **1,512,727.00**; 58 new rows (anchor 6th, Dec-2026 … Sep-2031).
- PMT **59,423.05**.
- Row 1: 2026-12-07 (6th is Sunday), from 11-06, 31 days, interest **51,583.99**, principal **7,839.06**, balance 1,504,887.94.
- Row 2: 2027-01-07 (01-06 holiday), 31 days, interest 51,316.68, principal 8,106.37, balance 1,496,781.57.
- Row 3: 2027-02-08, 32 days, interest 52,686.71, principal 6,736.34, balance 1,490,045.23.
- Row 57: 2031-08-06, from 07-07, 30 days, interest 3,900.87, principal 55,522.18, balance 62,686.11.
- **Row 58 (last): 2031-09-09 (deadline), from 08-06, 34 days, interest 2,344.46, principal 62,686.11, total 65,030.57, balance 0.**
- Σ principal 1,512,727.00; **total new interest 1,939,417.42** vs old remaining 2,352,428.55 (+11,648 on row 9920). Alternative D-END = last anchor 2031-09-08: last row 64,961.62, total interest 1,939,348.47.
- Old shape for comparison: rows 9921-9947 due ≈ 46-55 k (interest only), 9949-9978 = 77,702.59, last 94,144.56 on 2031-10-06.
These numbers are to be frozen in the unit test after being re-computed by the PHP implementation; any ±0.01 difference must be explained, not adjusted.

---

## 8. Test plan
### Unit (`tests/Unit/AnnuityScheduleCalculatorTest.php`; replaces the tautological existing test)
- Section 7 numbers row by row; Σ principal; last row = deadline business day; D-END variants.
- 6th-of-month holiday (01-06), weekend shifts, anchor 29-31 rejected, fee_annual_rate > 0, one-row term, rate 0, idempotency.
- Current-row maths: B0 with a partially prepaid current row; `periodInterest` split unchanged.
### Feature (`tests/Feature/RegenerateScheduleTest.php`, MySQL + `DatabaseTransactions` like `RecalculateScheduleTest`)
- Fixture built like the dump (stub completed, 60 open rows, 29 principal entries, interest entries on the current row): preview equals section 7; apply: rows 9921-9979 soft-deleted, 58 created, 28 entries moved, row 9920 consistent (due 11,648), deal 866 totals unchanged, JSON rebuilt, invariants hold.
- Pay 11,648 on the current row afterwards → completes; pay the first new row → entry attaches, interest/principal split correct.
- Each refusal in 3.4; apply twice → `changed:false`, one audit row; `state_hash` mismatch and payment between preview and apply → 409.
- Permission 403; non-admin back date 403; closed period 422.
- Revert restores rows, entries (`payment_id`), current row amounts, JSON; blocked after a payment on created rows.
- Reversing a deal dated before the regeneration is blocked.
- Recount fixture (whole amount on the first row) handled by 3.2.
- Reprovide regression: same fixture before/after B3 gives identical rows.
- Old route: refuses with entries, no `forceDelete` left (grep test).
### Manual QA (staging copy of the dump)
Preview vs section 7; apply; schedule tab refreshes; pay 11,648 then the next instalment; Form 6 / Form 9, ACRA and L002 generate; accounting turnover and deal 866 amounts unchanged; revert and re-apply; each refusal; non-admin; back date as admin/cashier; modal at narrow width with 60 rows; `npm run build`.

---

## 9. Harm register
| # | Harm | Mitigation |
|---|---|---|
| H1 | Hard delete cascades into prepayments / FK errors | Soft delete only; refuse with unpaid prepayments |
| H2 | Entries orphaned or invisible on deleted rows (28 on 122) | Relocation to the current row + row amounts adjusted + audit (3.2) |
| H3 | Current row mis-netted after relocation | Principal/amount raised by exactly the moved principal; invariant 1; test on 122 fixture |
| H4 | Wrong end date / extra month | Calculator with deadline; invariant 2 |
| H5 | Due days drift | Anchor day from `payment_day` |
| H6 | Reversal of earlier deals corrupts the schedule | Reversal guards (3.5) |
| H7 | Reprovide altered by shared-code changes | Regression test; reprovide path unchanged until phase 3 |
| H8 | Reports read the schedule (ACRA, L002, V06, V09) | Same row type/status/ordering; QA run; **no registry notification is sent (your decision)** |
| H9 | Concurrent payment / double click | Locks, `state_hash`, `changed:false`, disabled button |
| H10 | Back date / closed period / missing permission | `PostingDatePolicy`, `can:` |
| H11 | Duplicate `PGI_ID` | max incl. trashed |
| H12 | Last row differs from the old schedule's end (2031-10-06 → 2031-09-09) | Preview shows old vs new last date and a warning; D-END decision |
| H13 | Wrong `Modification` history (separate bug, 2.5) | Not changed here; ticket |
| H14 | Client expectation: instalments fall from 51-77 k to 59.4 k | Preview shows old range vs new |

---

## 10. Rollout and backfill
- **Phase 1 (read-only):** migration, permission, calculator + unit tests, preview endpoint.
- **Phase 2:** apply/revert, entry relocation, audit, feature tests, new modal; old route made safe. Button visible only with the permission.
- **Phase 3:** `payPartial` recount uses the calculator and the posting date; reversal guards shipped; separate ticket for the Modification bug.
- **Phase 4:** run detection (below), regenerate affected contracts one by one (122 first) via preview → review → apply; remove dead code.
- No automatic backfill. Rollback = revert endpoint per contract; the audit table is additive.

Detection queries (read-only):
```sql
-- contracts whose open schedule runs past the deadline (payment_day contracts will all show ≤ ~1 month; look for > 31 days)
SELECT c.id,c.num,c.payment_day,c.deadline,MAX(p.date) last_open,COUNT(*) open_rows
FROM contracts c JOIN payments p ON p.contract_id=c.id AND p.type='regular' AND p.status='initial' AND p.deleted_at IS NULL
WHERE c.payment_type='amortized' AND c.status NOT IN ('executed','cancelled')
GROUP BY c.id HAVING MAX(p.date) > DATE_ADD(c.deadline, INTERVAL 31 DAY);
-- contracts with principal entries spread over several open rows (candidates for regenerate)
SELECT p.contract_id, COUNT(DISTINCT p.id) rows_with_partial, SUM(e.principal_amount) prepaid
FROM payments p JOIN payment_entries e ON e.payment_id=p.id AND e.document_type='partial_payment'
WHERE p.status='initial' AND p.deleted_at IS NULL GROUP BY p.contract_id HAVING rows_with_partial>1;
```

---

## 11. Decisions I need from you
1. **D-END (end date).** The old schedule ends 2031-10-06 although the contract deadline is 2031-09-09 (all `payment_day` contracts end up to a month late). Recommended: last instalment on the deadline's business day (2031-09-09, 34-day last period). Alternative: last anchor date on/before the deadline (2031-09-08). Which?
2. **Entry relocation (3.2).** Move the 28 principal-only entries to the current row 9920 and raise that row's principal/amount by the same amount - OK? (Alternative "leave on deleted rows" breaks the interest split of the current row and joins in reports; I do not recommend it.)
3. v1 refusals in 3.4 (overdue rows, non-principal entries on replaced rows, unpaid prepayments): approved?
4. Permission `regenerate_contract_schedule`, admin only: OK?
5. Armenian only in the UI, or English too? `vitest` for frontend helpers, yes/no?
6. Ticket for the wrong `PrincipalAmount` modification (1,512,727 → 1,025,454): do you want it fixed before or after this feature, and does the registry already consume it?

---

## 12. Implementation status (2026-10-31)

Decisions applied as recommended: D-END = deadline business day, entries relocated to the current row, v1 refusals as in 3.4, admin-only `regenerate_contract_schedule`, Armenian-only UI, no vitest, Modification bug left as a separate ticket.

**Done (phases 1-2)**
- Backend: `Services/Schedule/{WorkingDays, AnnuityScheduleCalculator, ScheduleRegenerator, ScheduleRegenerationException}`, `Models/ScheduleRegeneration`, migrations `2026_10_31_000001` (table) and `2026_10_31_000002` (permission), `ScheduleRegenerationController`, 4 routes with `can:` middleware, reversal/edit guards in `AdminControllerNew` (`deleteDeal`, `previewDeleteDeal`, `updateDeals`). Old route, controller method and `ContractService::regenerateSchedule` (force delete) removed; `getNextWorkingDay` now delegates to `WorkingDays`.
- Frontend: thunks in `store/slices/contracts/slice.js`, `RegenerateScheduleModal.jsx` (two steps, Armenian texts for every error code), button gated by `hasPermission`, history list with revert in `PaymentsList.jsx`.
- Tests: `tests/Unit/AnnuityScheduleCalculatorTest.php` (A-26-00115 golden numbers), `tests/Feature/RegenerateScheduleTest.php` (10 tests) - both pass. A simulation of contract 122's post-payment state (rows and entries from `lomb-65.sql`, inside a rolled-back transaction) gave: 58 new rows, PMT 59,423.05, total new interest 1,939,417.42, last row 2031-09-09, 28 entries moved, row 9920 principal 487,273.00 / due left 11,648.00, second apply `changed:false`, revert restores the original rows.

**Not done**
- Phase 3 (`payPartial` recount using the calculator, posting date, entry placement) - changes live payment code, needs its own review.
- Phase 4 (running it on production data, detection queries).
- Migration `2026_10_31_000002` (permission) has not been run anywhere; on the local dev DB only the table migration was run (to execute the tests).
- Browser check of the modal was not possible (no running backend with auth); frontend verified with `npm run build` only.
- Pre-existing failures in `tests/Unit` (`AmortizedRunningBalanceTest` x2: `ScheduledPaymentHandler::openingBalances()` missing; `DealsTableOnlyUpdateServiceTest` x1) are unrelated to this work.
