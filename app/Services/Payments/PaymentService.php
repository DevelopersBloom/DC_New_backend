<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\Modification;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\Prepayment;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ContractService;
use App\Traits\ContractTrait;
use App\Traits\CorrectReserveTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the full payment flow:
 *   processPayments  — main entry point (penalty → scheduled rows → leftovers)
 *   processPenalty   — late-fee payment
 *   payPartial       — principal-only partial payment
 *   processFullPayment — close a contract in one shot
 *
 * Per-row interest/principal logic is delegated to PrepaymentHandler or
 * ScheduledPaymentHandler; entry recording is handled by PaymentEntryRecorder.
 */
class PaymentService
{
    use ContractTrait;
    use CorrectReserveTrait;

    public function __construct(
        protected ContractService                  $contractService,
        protected PrepaymentHandler                $prepaymentHandler,
        protected ScheduledPaymentHandler          $scheduledHandler,
        protected LatePaymentInterestRecalculator  $lateInterestRecalculator,
        protected PaymentDateClassifier            $dateClassifier,
    ) {}

    // ── Main payment loop ────────────────────────────────────────────────────

    public function processPayments(
        $contract, $amount, $payer, $cash, $payments, $deal_id,
        $journal_id = null, bool $forceScheduled = false, $interestAmount = 0,
        $ispPaymentSelected = false, $date = null, $paymentMechanism = null
    ): array {
        $payments_sum         = 0;
        $interest_amount      = 0;
        $principal_amount     = 0;
        $prepayment_principal = 0;
        $initial_amount       = $amount;

        $timing = $this->dateClassifier->classify($contract, $date);

        $contract->historyContext = [
            'deal_id'     => $deal_id,
            'date'        => $date ?? now()->toDateString(),
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1,
        ];
        $old_provided  = $contract->provided_amount;
        $old_left      = $contract->left;
        $old_collected = $contract->collected;

        // ── Late-payment interest recalculation ──────────────────────────────
        // Adjust interest on installments whose period was crossed while the
        // client had not yet paid.  Runs before penalty so penalty sees the
        // correct (updated) amounts; no-ops when no installments are affected.
        if ($date !== null && $contract->payment_type === 'amortized') {
            $this->lateInterestRecalculator->recalculate($contract, $date, $payments);
        }

        // ── Penalty first ────────────────────────────────────────────────────
        $result_penalty = $this->countPenalty($contract->id, $date);
        $penalty        = $result_penalty['penalty_amount'];
        $delay_days     = $result_penalty['delay_days'];
        $parent_id      = $result_penalty['parent_id'];
        $payed_penalty  = 0;
        $partial_amount = 0;
        if ($penalty > 0) {
            $penaltyResult = $this->processPenalty(
                $contract->id, $amount, $penalty, $payer, $cash, $deal_id, $parent_id, $date
            );
            $payed_penalty = $penaltyResult['penalty'];
            $amount        = $penaltyResult['amount'];

            if ($payed_penalty > 0) {
                $classification = $contract->client->classification->name ?? '';
                if ($classification === 'loss') {
                    $rulePenalty = PostingRule::where('business_event_filter', 'pay_penalty_amount_loss')->first();
                } elseif ($cash) {
                    $rulePenalty = PostingRule::where('business_event_filter', 'pay_penalty_amount_cash')->first();
                } else {
                    $rulePenalty = PostingRule::where('business_event_filter', 'pay_penalty_amount')->first();
                }
                if ($rulePenalty) {
                    $userId       = auth()->id();
                    $nextDocNum   = Transaction::getNextDocumentNumber();
                    $journalDocPenalty = DocumentJournal::create([
                        'date'              => $date,
                        'document_number'   => $nextDocNum,
                        'document_type'     => DocumentJournal::PAY_PENALTY_AMOUNT,
                        'amount_amd'        => $payed_penalty,
                        'debit_partner_id'  => $rulePenalty->resolveDebitPartnerId($contract) ?? null,
                        'credit_partner_id' => $rulePenalty->resolveCreditPartnerId($contract) ?? $contract->client_id,
                        'comment'           => 'Daily penalty accrual for contract #' . $contract->id,
                        'debit_account_id'  => $rulePenalty->debit_account_id,
                        'credit_account_id' => $rulePenalty->credit_account_id,
                        'user_id'           => $userId,
                        'journalable_type'  => DocumentJournal::class,
                        'journalable_id'    => $journal_id,
                        'contract_id'       => $contract->id,
                    ]);
                    Transaction::create([
                        'date'                 => $date,
                        'document_number'      => $nextDocNum,
                        'document_type'        => DocumentJournal::PAY_PENALTY_AMOUNT,
                        'debit_account_id'     => $rulePenalty->debit_account_id,
                        'credit_account_id'    => $rulePenalty->credit_account_id,
                        'debit_partner_id'     => $rulePenalty->resolveDebitPartnerId($contract) ?? null,
                        'credit_partner_id'    => $rulePenalty->resolveCreditPartnerId($contract) ?? $contract->client_id,
                        'amount_amd'           => $payed_penalty,
                        'comment'              => 'Daily penalty accrual for contract #' . $contract->id,
                        'user_id'              => $userId,
                        'is_system'            => true,
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id'   => $journalDocPenalty->id,
                        'contract_id'          => $contract->id,
                    ]);
                }
            }
        }

        // ── Process each payment row ─────────────────────────────────────────
        if ($amount > 0) {
            $selectedTotalDue = $payments->sum(function ($p) {
                return (float) ($p->amount ?? 0) + (float) ($p->penalty ?? 0);
            });
            // If the entered amount can fully cover all selected rows, or the caller explicitly
            // requests scheduled (e.g. makePayment with explicit IDs), skip early split.
            $forceScheduledForSelected = $forceScheduled || ($amount >= $selectedTotalDue);
            $processedPaymentIds       = [];

            foreach ($payments as $payment) {
                $payment = $this->normalizePaymentDates($payment, $contract);
                if ($payment->from_date >= $date && !$ispPaymentSelected) continue;
                if ($amount > 0) {
                    $result = $this->processSinglePayment(
                        $contract, $payment, $amount, $payer, $cash, $deal_id,
                        $forceScheduledForSelected, $interestAmount, $date, $paymentMechanism, $timing
                    );
                    $processedPaymentIds[] = $payment->id;
                    $amount               = $result['amount'];
                    $interest_amount      += $result['interest_amount'];
                    $principal_amount     += $result['principal_amount'];
                    $prepayment_principal += $result['prepayment_principal'] ?? 0;
                }
            }
            // ── Handle leftover cash ─────────────────────────────────────────
            // Prepayment/future-interest mechanisms only make sense when this
            // payment is actually SOONER (R6) — a due-date/late leftover always
            // follows R9/R11 (reduce upcoming principal + recalc) regardless of
            // which mechanism the request asked for.
            if ($amount > 0) {
                if ($timing === PaymentDateClassifier::SOONER && $paymentMechanism === 'prepayment') {
                    $extra = $this->prepaymentHandler->applyRemaining(
                        $contract, $amount, $payer, $cash, $deal_id, $date, $processedPaymentIds
                    );
                    $prepayment_principal += $extra;
                }
//                elseif ($timing === PaymentDateClassifier::SOONER && $paymentMechanism === 'interest') {
//                    $applied = $this->applyExtraToFutureInterest(
//                        $contract, $amount, $payments->last()->id ?? null,
//                        $deal_id, $cash, $date
//                    );
//                    $interest_amount += $applied;
//                }
                 else {
                    $this->handleRemainingAmount(
                        $contract, $amount, $cash, $payments->last()->id, $deal_id, $date
                    );
                    $partial_amount += $amount;
                }
                $amount = 0;
            }
        }

        // ── Persist contract totals and audit trail ──────────────────────────
        $contract->collected += $interest_amount;
        $contract->save();

        if ($principal_amount > 0 && $contract->payment_type == 'amortized') {
            $history['contract_changes'] = [
                'old_left'      => $old_left,
                'new_left'      => $contract->left - $principal_amount,
                'old_provided'  => $old_provided,
                'new_provided'  => max(0, $contract->provided_amount - $principal_amount),
                'old_collected' => $old_collected,
                'contract_id'   => $contract->id,
            ];
            DealAction::create([
                'deal_id'         => $deal_id,
                'actionable_id'   => $contract->id,
                'actionable_type' => Contract::class,
                'amount'          => $principal_amount,
                'type'            => 'partial',
                'description'     => 'Partial payment contract changes',
                'date'            => $date,
                'history'         => $history,
            ]);
            Modification::create([
                'subject_type'      => Contract::class,
                'subject_id'        => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'        => 'PrincipalAmount',
                'element_code'      => 'Amount',
                'old_value'         => $old_provided !== null ? (string) $old_provided : null,
                'new_value'         => (string) max(0, $contract->provided_amount - $principal_amount),
                'effective_date'    => $date ?? now()->toDateString(),
            ]);
        }

        if ($interest_amount > 0) {
            Modification::create([
                'subject_type'      => Contract::class,
                'subject_id'        => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'        => 'PercentsPaid',
                'element_code'      => 'Amount',
                'old_value'         => $old_collected !== null ? (string) $old_collected : null,
                'new_value'         => (string) max(0, $old_collected + $interest_amount),
                'effective_date'    => $date ?? now()->toDateString(),
            ]);
        }

        return [
            'payments_sum'         => $payments_sum,
            'interest_amount'      => $interest_amount,
            'principal_amount'     => $principal_amount,
            'prepayment_principal' => $prepayment_principal,
            'penalty'              => $payed_penalty,
            'delay_days'           => $delay_days,
            'discount'             => 0,
            'partial_amount'       => $partial_amount,
        ];
    }

    // ── Penalty payment ──────────────────────────────────────────────────────

    public function processPenalty(
        $contractId, $amount, $penalty, $payer, $cash,
        $deal_id = null, $parent_id = null, $isDiscount = false, $date = null
    ): array {
        $balance = (float) (Contract::find($contractId)->provided_amount ?? 0);

        if ($amount < $penalty) {
            $discountAmount = $isDiscount ? $amount : 0;
            $paymentId      = $this->createPayment(
                $contractId, $amount, 'penalty', $payer, $cash, [], $deal_id, $date, false, $parent_id, $discountAmount
            );
            PaymentEntry::create([
                'payment_id'       => $paymentId,
                'contract_id'      => $contractId,
                'deal_id'          => $deal_id,
                'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
                'user_id'          => auth()->id() ?? 1,
                'reference'        => Str::uuid(),
                'amount'           => $amount,
                'principal_amount' => 0,
                'interest_amount'  => 0,
                'penalty_amount'   => $amount,
                'balance_before'   => $balance,
                'balance_after'    => $balance,
                'document_type'    => 'penalty_payment',
                'date'             => $date ?? Carbon::now()->format('Y-m-d'),
                'cash'             => $cash ?? false,
            ]);
            return ['penalty' => $amount, 'amount' => 0, 'payment_id' => $paymentId];
        }

        $discountAmount = $isDiscount ? $penalty : 0;
        $paymentId      = $this->createPayment(
            $contractId, $penalty, 'penalty', $payer, $cash, [], $deal_id, $date, true, $parent_id, $discountAmount, $date
        );
        PaymentEntry::create([
            'payment_id'       => $paymentId,
            'contract_id'      => $contractId,
            'deal_id'          => $deal_id,
            'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
            'user_id'          => auth()->id() ?? 1,
            'reference'        => Str::uuid(),
            'amount'           => $penalty,
            'principal_amount' => 0,
            'interest_amount'  => 0,
            'penalty_amount'   => $penalty,
            'balance_before'   => $balance,
            'balance_after'    => $balance,
            'document_type'    => 'penalty_payment',
            'date'             => $date ?? Carbon::now()->format('Y-m-d'),
            'cash'             => $cash ?? false,
        ]);
        return ['penalty' => $penalty, 'amount' => $amount - $penalty, 'payment_id' => $paymentId];
    }

    // ── Single-row dispatcher ────────────────────────────────────────────────

    private function processSinglePayment(
        $contract, $payment, $amount, $payer, $cash, $deal_id,
        bool $forceScheduledForSelected = false, $interestAmount = 0,
        $date = null, $paymentMechanism = null, $timing = null
    ): array {
        $balanceBefore = (float) $contract->provided_amount;

        // The prepayment bucket only exists for genuinely early payments (R5/R6) —
        // gated by the overall transaction timing, not just the requested mechanism,
        // so a due-date/late payment never creates a Prepayment record even if a
        // future row happens to be explicitly selected alongside it.
        if ($contract->payment_type === 'amortized' && $paymentMechanism === 'prepayment' && $timing === PaymentDateClassifier::SOONER) {
            $result = $this->prepaymentHandler->handle(
                $contract, $payment, $payer, $cash, $deal_id,
                $amount, $interestAmount, $date, $balanceBefore
            );
        } elseif ($contract->payment_type === 'amortized') {
            $result = $this->scheduledHandler->handleAmortized(
                $contract, $payment, $payer, $cash, $deal_id,
                $amount, $interestAmount, $date, $balanceBefore
            );
        } else {
            $result = $this->scheduledHandler->handleClassic(
                $contract, $payment, $payer, $cash, $deal_id,
                $amount, $interestAmount, $date, $balanceBefore
            );
        }

        $contract->save();
        $payment->save();

        return $result;
    }

    // ── Remaining-cash handler (non-prepayment) ──────────────────────────────

    private function handleRemainingAmount(
        $contract, $amount, $cash, $payment_id, $deal_id = null, $date = null
    ): mixed {
        $nextPayment = Payment::where('contract_id', $contract->id)
            ->where('status', 'initial')
            ->where('id', '!=', $payment_id)
            ->orderBy('date', 'asc')->orderBy('id', 'asc')
            ->first();

        $decrease  = null;
        $oldAmount = null;
        $oldDate   = null;

        if ($nextPayment && $contract->payment_type == 'classic') {
            $decrease     = $amount % 1000;
            $amount      -= $decrease;
            $oldAmount    = $nextPayment->amount;
            $oldDate      = $nextPayment->date;
            $oldInterest  = $nextPayment->interest_payment;
            $oldPrincipal = $nextPayment->principal_payment;
        }

        if ($nextPayment && $decrease > 0) {
            $balanceBefore = (float) $contract->provided_amount;
            PaymentEntry::create([
                'payment_id'     => $nextPayment->id,
                'contract_id'    => $contract->id,
                'deal_id'        => $deal_id,
                'pawnshop_id'    => auth()->user()->pawnshop_id ?? 1,
                'user_id'        => auth()->id() ?? 1,
                'reference'      => Str::uuid(),
                'amount'         => $decrease,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceBefore,
                'document_type'  => 'regular_payment',
                'date'           => $date ?? Carbon::now()->format('Y-m-d'),
                'cash'           => false,
            ]);
            $history['payment_changes'][] = [
                'payment_id'    => $nextPayment->id,
                'old_amount'    => $oldAmount,
                'new_amount'    => $nextPayment->amount,
                'old_date'      => $oldDate,
                'old_interest'  => $oldInterest,
                'old_principal' => $oldPrincipal,
                'updated_at'    => now()->toDateTimeString(),
            ];
            DealAction::create([
                'deal_id'         => $deal_id,
                'actionable_id'   => $nextPayment->id,
                'actionable_type' => Payment::class,
                'amount'          => $decrease,
                'type'            => 'regular',
                'description'     => 'Regular payment',
                'date'            => $date ?? Carbon::now()->format('Y-m-d'),
                'history'         => $history,
            ]);
        }

        if ($amount >= 1) {
            dd($amount);
            $this->payPartial($contract, $amount, false, $cash, $deal_id, $date, false, true);
        }

        return $decrease;
    }

    // ── Partial payment (principal reduction) ────────────────────────────────

    public function payPartial(
        $contract, $partialAmount, $payer, $cash,
        $deal_id = null, $date = null, $is_recount = false, $is_remaining_payment = false
    ): mixed {
        $now           = $date ?? Carbon::now();
        $balanceBefore = (float) $contract->provided_amount;
        $history       = ['payment_changes' => []];

        $contract->historyContext = [
            'deal_id'     => $deal_id,
            'date'        => $date ?? now()->toDateString(),
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1,
        ];
        $payments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->orderBy('date', 'asc')
            ->get();

        if ($contract->payment_type == 'amortized') {
            if ($is_recount) {
                $remainingPayments = Payment::where('contract_id', $contract->id)
                    ->where('type', 'regular')
                    ->where('status', 'initial')
                    ->get();

                $history['deleted_payment_ids'] = $remainingPayments->pluck('id')->toArray();
                $history['payment_changes']     = $remainingPayments->map(fn ($p) => [
                    'payment_id'    => $p->id,
                    'old_amount'    => $p->amount,
                    'old_paid'      => $p->paid,
                    'old_date'      => $p->date,
                    'old_principal' => $p->principal_payment,
                    'old_interest'  => $p->interest_payment,
                ])->toArray();

                $remainingMonths = $remainingPayments->count();
                if ($remainingMonths > 0) {
                    Payment::where('contract_id', $contract->id)
                        ->where('type', 'regular')
                        ->where('status', 'initial')
                        ->delete();

                    $targetDate              = $date ?? Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d');
                    $history['contract_changes'] = [
                        'old_left'      => $contract->left,
                        'new_left'      => $contract->left - $partialAmount,
                        'old_provided'  => $contract->provided_amount,
                        'new_provided'  => max(0, $contract->provided_amount - $partialAmount),
                        'old_collected' => $contract->collected,
                        'contract_id'   => $contract->id,
                    ];
                    Modification::create([
                        'subject_type'      => Contract::class,
                        'subject_id'        => $contract->id,
                        'modification_type' => 'Modificator',
                        'field_code'        => 'PrincipalAmount',
                        'element_code'      => 'Amount',
                        'old_value'         => $contract->provided_amount !== null ? (string) $contract->provided_amount : null,
                        'new_value'         => (string) max(0, $contract->provided_amount - $partialAmount),
                        'effective_date'    => $date ?? now()->toDateString(),
                    ]);

                    $contract->left            = max(0, $contract->left - $partialAmount);
                    $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
                    $contract->save();
                    $this->contractService->createPayment($contract, $targetDate, null, $remainingMonths);
                }
            } else {
                $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
                $contract->left            = max(0, $contract->left - $partialAmount);
                $contract->save();

                $history['payment_changes']  = $this->scheduledHandler->processAmortized(
                    $contract, $payments, $partialAmount, $now
                );
                $history['contract_changes'] = [
                    'old_left'      => $contract->left,
                    'new_left'      => $contract->left - $partialAmount,
                    'old_provided'  => $contract->provided_amount,
                    'new_provided'  => max(0, $contract->provided_amount - $partialAmount),
                    'old_collected' => $contract->collected,
                    'contract_id'   => $contract->id,
                ];
                Modification::create([
                    'subject_type'      => Contract::class,
                    'subject_id'        => $contract->id,
                    'modification_type' => 'Modificator',
                    'field_code'        => 'PrincipalAmount',
                    'element_code'      => 'Amount',
                    'old_value'         => $contract->provided_amount !== null ? (string) $contract->provided_amount : null,
                    'new_value'         => (string) max(0, $contract->provided_amount - $partialAmount),
                    'effective_date'    => $date ?? now()->toDateString(),
                ]);
            }

            DealAction::create([
                'deal_id'         => $deal_id,
                'actionable_id'   => $contract->id,
                'actionable_type' => Contract::class,
                'amount'          => $partialAmount,
                'type'            => 'partial',
                'description'     => $is_recount
                    ? 'Partial payment with schedule recount'
                    : 'Partial payment with amount reduction',
                'date'            => $date ?? Carbon::now()->format('Y-m-d'),
                'history'         => $history,
            ]);
        } else {
            $paymentResult               = $this->scheduledHandler->processClassic(
                $payments, $contract, $partialAmount, $now
            );
            $history['payment_changes']  = $paymentResult['payments'];
            $history['mother_amount']    = $paymentResult['mother_amount'];
            $history['contract_changes'] = [
                'old_left'      => $contract->left,
                'new_left'      => $contract->left - $partialAmount,
                'old_provided'  => $contract->provided_amount,
                'new_provided'  => max(0, $contract->provided_amount - $partialAmount),
                'old_collected' => $contract->collected,
                'contract_id'   => $contract->id,
            ];
            Modification::create([
                'subject_type'      => Contract::class,
                'subject_id'        => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'        => 'PrincipalAmount',
                'element_code'      => 'Amount',
                'old_value'         => $contract->provided_amount !== null ? (string) $contract->provided_amount : null,
                'new_value'         => (string) max(0, $contract->provided_amount - $partialAmount),
                'effective_date'    => $date ?? now()->toDateString(),
            ]);
            $contract->left            = max(0, $contract->left - $partialAmount);
            $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
            $contract->save();
        }

        $this->handleAccountingForPartial($contract, $partialAmount, $date, $deal_id, $cash);

        $balanceAfter   = (float) $contract->provided_amount;
        $firstPaymentId = null;

        if ($contract->payment_type === 'amortized' && !$is_recount && !empty($history['payment_changes'])) {
            // One entry per affected payment row, balance cascades down
            $runningBalance = $balanceBefore;
            foreach ($history['payment_changes'] as $change) {
                $reduction = (float) ($change['reduction'] ?? 0);
                if ($reduction <= 0) continue;
                $entryBalanceAfter = max(0, $runningBalance - $reduction);
                if ($firstPaymentId === null) {
                    $firstPaymentId = $change['payment_id'];
                }
                $existingEntry = PaymentEntry::where('payment_id', $change['payment_id'])
                    ->where('document_type', 'partial_payment')
                    ->when($deal_id, fn ($q) => $q->where('deal_id', $deal_id))
                    ->latest('id')
                    ->first();
                if ($existingEntry) {
                    $existingEntry->amount           += $reduction;
                    $existingEntry->principal_amount += $reduction;
                    $existingEntry->balance_after     = $entryBalanceAfter;
                    $existingEntry->save();
                } else {
                    PaymentEntry::create([
                        'payment_id'       => $change['payment_id'],
                        'contract_id'      => $contract->id,
                        'deal_id'          => $deal_id,
                        'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
                        'user_id'          => auth()->id() ?? 1,
                        'reference'        => Str::uuid(),
                        'amount'           => $reduction,
                        'principal_amount' => $reduction,
                        'interest_amount'  => 0,
                        'penalty_amount'   => 0,
                        'balance_before'   => $runningBalance,
                        'balance_after'    => $entryBalanceAfter,
                        'document_type'    => 'partial_payment',
                        'date'             => $date ?? Carbon::now()->format('Y-m-d'),
                        'cash'             => $cash ?? false,
                    ]);
                }
                $runningBalance = $entryBalanceAfter;
            }
        } else {
            // is_recount or classic: single entry linked to next scheduled payment
            $nextPayment = Payment::where('contract_id', $contract->id)
                ->where('type', 'regular')
                ->where('status', 'initial')
                ->orderBy('date', 'asc')->orderBy('id', 'asc')
                ->first();
            $firstPaymentId = $nextPayment?->id;
            $existingEntry  = PaymentEntry::where('payment_id', $firstPaymentId)
                ->where('document_type', 'partial_payment')
                ->when($deal_id, fn ($q) => $q->where('deal_id', $deal_id))
                ->latest('id')
                ->first();
            if ($existingEntry) {
                $existingEntry->amount           += $partialAmount;
                $existingEntry->principal_amount += $partialAmount;
                $existingEntry->balance_after     = $balanceAfter;
                $existingEntry->save();
            } else {
                PaymentEntry::create([
                    'payment_id'       => $firstPaymentId,
                    'contract_id'      => $contract->id,
                    'deal_id'          => $deal_id,
                    'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
                    'user_id'          => auth()->id() ?? 1,
                    'reference'        => Str::uuid(),
                    'amount'           => $partialAmount,
                    'principal_amount' => $partialAmount,
                    'interest_amount'  => 0,
                    'penalty_amount'   => 0,
                    'balance_before'   => $balanceBefore,
                    'balance_after'    => $balanceAfter,
                    'document_type'    => 'partial_payment',
                    'date'             => $date ?? Carbon::now()->format('Y-m-d'),
                    'cash'             => $cash ?? false,
                ]);
            }
        }

        return $firstPaymentId;
    }

    // ── Full payment ─────────────────────────────────────────────────────────

    public function processFullPayment(
        $contract, $amount, $payer, $cash, $deal_id = null, $date = null
    ): array {
        Payment::where('contract_id', $contract->id)->where('status', 'initial')->delete();

        $providedAmount = $contract->provided_amount;

        $duePrepayments = Prepayment::where('contract_id', $contract->id)
            ->where('status', 'unpaid')
            ->get();

        $bucketPrincipalLike = (float) $duePrepayments->sum(
            fn ($p) => (float) $p->principal_amount + (float) $p->partial_amount
        );
        $bucketInterest = (float) $duePrepayments->sum('interest_amount');

        $motherAmountToPay    = $this->calculateMotherAmountToPay($contract);
        $principalFromBucket  = $motherAmountToPay['principal_from_bucket'];
        $refundAmount         = max(0, $bucketPrincipalLike - $providedAmount);

        // $bucketInterest was already recognized into contract->collected when it was
        // deposited (PrepaymentHandler::handle() adds it via processPayments()) — only
        // its GL reclassification (liability → income, below) is still pending, so it
        // must not be added to $interestAmount/collected again here.
        $interestAmount = ($amount + $principalFromBucket) - $providedAmount;

        $history['contract_changes'] = [
            'contract_id'   => $contract->id,
            'old_left'      => $contract->left,
            'new_left'      => 0,
            'old_collected' => $contract->collected,
            'new_collected' => $contract->collected + $interestAmount,
            'old_provided'  => $contract->provided_amount,
            'old_status'    => 'initial',
            'new_status'    => 'completed',
            'updated_at'    => now()->toDateTimeString(),
        ];

        $payment = $this->createPayment(
            $contract->id, $amount, 'full', $payer, $cash, $history, $deal_id, $date
        );

        PaymentEntry::create([
            'payment_id'       => $payment,
            'contract_id'      => $contract->id,
            'deal_id'          => $deal_id,
            'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
            'user_id'          => auth()->id() ?? 1,
            'reference'        => Str::uuid(),
            'amount'           => $amount,
            'principal_amount' => $providedAmount,
            'interest_amount'  => $interestAmount,
            'penalty_amount'   => 0,
            'balance_before'   => $providedAmount,
            'balance_after'    => 0,
            'document_type'    => 'full_payment',
            'date'             => $date ?? Carbon::now()->format('Y-m-d'),
            'cash'             => $cash ?? false,
        ]);

        // estimated_amount does not become 0 on full payment, so record its 'out' event manually.
        ContractAmountHistory::create([
            'contract_id' => $contract->id,
            'amount'      => $contract->estimated_amount,
            'amount_type' => 'estimated_amount',
            'type'        => 'out',
            'date'        => $date ?? now()->toDateString(),
            'deal_id'     => $deal_id,
            'category_id' => $contract->category_id,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1,
        ]);

        $contract->status          = 'completed';
        $contract->left            = 0;
        $contract->collected      += $interestAmount;
        $contract->provided_amount = 0;
        $contract->historyContext  = [
            'deal_id'     => $deal_id,
            'date'        => $date ?? now()->toDateString(),
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1,
        ];
        $contract->save();

        // ── B4: reclassify the bucket out of the liability account, refund the rest ──
        if ($duePrepayments->isNotEmpty()) {
            $journal = DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $contract->id)
                ->first();
            $docNum = Transaction::getNextDocumentNumber();

            if ($principalFromBucket > 0) {
                $rule = $this->getPostingRule('prepayment_apply_principal');
                $this->postEntry(
                    $date ?? Carbon::now()->format('Y-m-d'), $docNum, DocumentJournal::PREPAYMENT_APPLY_PRINCIPAL,
                    $principalFromBucket, 'prepayment_apply_principal_on_closure',
                    $rule->debit_account_id, $rule->credit_account_id,
                    $deal_id, $journal?->id, $contract->client_id, $contract->id, $rule, $contract
                );
            }

            if ($bucketInterest > 0) {
                $rule = $this->getPostingRule('prepayment_apply_interest');
                $this->postEntry(
                    $date ?? Carbon::now()->format('Y-m-d'), $docNum, DocumentJournal::PREPAYMENT_APPLY_INTEREST,
                    $bucketInterest, 'prepayment_apply_interest_on_closure',
                    $rule->debit_account_id, $rule->credit_account_id,
                    $deal_id, $journal?->id, $contract->client_id, $contract->id, $rule, $contract
                );
            }

            if ($refundAmount > 0) {
                $refundEvent = $cash ? 'prepayment_refund_cash' : 'prepayment_refund';
                $rule        = $this->getPostingRule($refundEvent);
                $this->postEntry(
                    $date ?? Carbon::now()->format('Y-m-d'), $docNum, DocumentJournal::PREPAYMENT_REFUND,
                    $refundAmount, 'prepayment_refund_on_closure',
                    $rule->debit_account_id, $rule->credit_account_id,
                    $deal_id, $journal?->id, $contract->client_id, $contract->id, $rule, $contract
                );
            }

            foreach ($duePrepayments as $duePrepayment) {
                $duePrepayment->status  = 'paid';
                $duePrepayment->paid_at = $date ? Carbon::parse($date)->startOfDay() : Carbon::now();
                $duePrepayment->save();
            }
        }

        $nowDate = $date ?? now()->toDateString();
        Modification::insert([
            [
                'subject_type'      => Contract::class,
                'subject_id'        => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'        => 'PrincipalAmount',
                'element_code'      => 'Amount',
                'old_value'         => $contract->provided_amount !== null ? (string) $contract->provided_amount : null,
                'new_value'         => '0',
                'effective_date'    => $nowDate,
            ],
            [
                'subject_type'      => Contract::class,
                'subject_id'        => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'        => 'PercentsPaid',
                'element_code'      => 'Amount',
                'old_value'         => $contract->collected !== null ? (string) $contract->collected : null,
                'new_value'         => (string) ($contract->collected + $interestAmount),
                'effective_date'    => $nowDate,
            ],
            [
                'subject_type'      => Contract::class,
                'subject_id'        => $contract->id,
                'modification_type' => 'Modificator',
                'field_code'        => 'LoanStatus',
                'element_code'      => 'YN',
                'old_value'         => 'Y',
                'new_value'         => 'N',
                'effective_date'    => $nowDate,
            ],
        ]);

        try {
            $this->releaseReserveBalancesIfClientFullyClosed(
                clientId:   $contract->client_id,
                contractId: $contract->id,
                date:       $nowDate,
            );
        } catch (\Throwable $e) {
            Log::error(
                "Reserve release after full payment failed for contract #{$contract->id}: " . $e->getMessage()
            );
        }

        return [
            'payment_id'           => $payment,
            'refund_amount'        => $refundAmount,
            'principal_from_bucket' => $principalFromBucket,
        ];
    }

    // ── Payment preview (read-only breakdown) ───────────────────────────────

    /**
     * Simulate the payment allocation and return the interest / principal split
     * without writing anything to the database.
     *
     * @return array{penalty_amount:float, interest_amount:float, principal_amount:float, prepayment_principal:float}
     */
    public function previewPaymentBreakdown(
        Contract $contract,
        float $amount,
        ?string $date = null,
        ?string $paymentMechanism = null,
        array $paymentIds = []
    ): array {
        $contractClone   = clone $contract;
        $remaining       = $amount;
        $interestAmount  = 0.0;
        $principalAmount = 0.0;
        $prepayPrincipal = 0.0;
        $penaltyPaid     = 0.0;

        // ── Step 1: Penalty deduction ────────────────────────────────────────
        $penaltyResult = $this->countPenalty($contract->id, $date);
        $penalty = (float) ($penaltyResult['penalty_amount'] ?? 0);

        if ($penalty > 0) {
            $penaltyPaid = min($remaining, $penalty);
            $remaining  -= $penaltyPaid;
        }

        if ($remaining <= 0) {
            return [
                'penalty_amount'       => round($penaltyPaid, 2),
                'interest_amount'      => 0.0,
                'principal_amount'     => 0.0,
                'prepayment_principal' => 0.0,
            ];
        }

        // ── Step 2: Load relevant payment rows ───────────────────────────────
        $paymentIdsCollection = collect($paymentIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $payments = Payment::query()
            ->where('contract_id', $contract->id)
            ->where('status', 'initial')
            ->when(
                $paymentIdsCollection->isNotEmpty(),
                fn ($q) => $q->whereIn('id', $paymentIdsCollection),
                fn ($q) => $q->where('type', 'regular')
            )
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        // ── Step 3: Current interest to cover ───────────────────────────────
        $current          = $this->calculateCurrentPayment($contract, $date);
        $remainingInterest = max(0.0, (float) ($current['interest_amount'] ?? 0));

        $ispPaymentSelected    = $paymentIdsCollection->isNotEmpty();
        $selectedTotalDue      = $payments->sum(fn ($p) => (float) ($p->amount ?? 0) + (float) ($p->penalty ?? 0));
        $forceScheduled        = $ispPaymentSelected || ($remaining >= $selectedTotalDue);
        $today                 = $date ?? now()->format('Y-m-d');

        // ── Step 4: Simulate per-row allocation (no saves) ──────────────────
        foreach ($payments as $payment) {
            $payment = $this->normalizePaymentDates($payment, $contractClone);

            if ($payment->from_date >= $today && !$ispPaymentSelected) {
                continue;
            }

            if ($remaining <= 0) {
                break;
            }

            if ($contractClone->payment_type === 'amortized' && $paymentMechanism === 'prepayment') {
                // Pay scheduled interest first, then scheduled principal
                $alreadyPaidInterest   = (float) $payment->entries()->sum('interest_amount');
                $remainingInterestPlan = max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
                $paidInterest          = min($remainingInterestPlan, $remaining);
                $remaining            -= $paidInterest;
                $remainingInterest    -= $paidInterest;
                $interestAmount       += $paidInterest;

                $alreadyPaidPrincipal = (float) $payment->entries()->sum('principal_amount');
                $remainingPrincipal   = max(0, (float) ($payment->principal_payment ?? 0) - $alreadyPaidPrincipal);
                $paidPrincipal        = min($remaining, $remainingPrincipal);
                $remaining           -= $paidPrincipal;

                $contractClone->provided_amount = max(0, $contractClone->provided_amount - $paidPrincipal);
                $contractClone->left            = max(0, $contractClone->left - $paidPrincipal);

                $timing = $this->dateClassifier->classifyAgainstDueDate($payment->to_date ?? $payment->date, $today);

                if ($timing === PaymentDateClassifier::SOONER && $paidPrincipal > 0) {
                    $prepayPrincipal += $paidPrincipal;
                } else {
                    $principalAmount += $paidPrincipal;
                }

            } elseif ($contractClone->payment_type === 'amortized') {
                // Try early split first
                $timing = $this->dateClassifier->classifyAgainstDueDate($payment->to_date ?? $payment->date, $date);
                $earlySplit = $timing === PaymentDateClassifier::SOONER
                    ? $this->scheduledHandler->calculateEarlySplitPreview($contractClone, $payment, $remaining, $date)
                    : null;

                if ($earlySplit !== null) {
                    // R10 (C2): interest_due already collected by the split; the rest
                    // (principal_part) reduces this row's own principal first — any
                    // excess spills to the next row (R9), via the same loop.
                    $paidInterest  = $earlySplit['paid_interest'];
                    $principalPart = $earlySplit['principal_part'];

                    $alreadyPaidPrincipalForRow = (float) $payment->entries()->sum('principal_amount');
                    $remainingPrincipalForRow   = max(0, (float) ($payment->principal_payment ?? 0) - $alreadyPaidPrincipalForRow);
                    $paidPrincipal              = min($principalPart, $remainingPrincipalForRow);

                    $contractClone->provided_amount = max(0, $contractClone->provided_amount - $paidPrincipal);
                    $contractClone->left            = max(0, $contractClone->left - $paidPrincipal);

                    $interestAmount   += $paidInterest;
                    $principalAmount  += $paidPrincipal;
                    $remaining         = $principalPart - $paidPrincipal;
                    $remainingInterest = 0;
                } else {
                    // Scheduled path: interest first, then principal (past due only)
                    $alreadyPaidInterest   = (float) $payment->entries()->sum('interest_amount');
                    $remainingInterestPlan = max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
                    $paidInterest          = 0;

                    if ($remainingInterest > 0 && $remainingInterestPlan > 0) {
                        $paidInterest       = min($remainingInterest, $remainingInterestPlan, $remaining);
                        $remainingInterest -= $paidInterest;
                        $remaining        -= $paidInterest;
                    }

                    $paidPrincipal = 0;
                    if (($payment->to_date ?? $payment->date) <= $today) {
                        $alreadyPaidPrincipal = (float) $payment->entries()->sum('principal_amount');
                        $remainingPrincipal   = max(0, (float) $payment->principal_payment - $alreadyPaidPrincipal);
                        $paidPrincipal        = min($remaining, $remainingPrincipal);
                        $remaining           -= $paidPrincipal;

                        $contractClone->provided_amount = max(0, $contractClone->provided_amount - $paidPrincipal);
                        $contractClone->left            = max(0, $contractClone->left - $paidPrincipal);
                    }

                    $interestAmount  += $paidInterest;
                    $principalAmount += $paidPrincipal;
                }
            } else {
                // Classic: scheduled payment = interest only
                $alreadyPaid  = (float) $payment->entries()->sum('amount');
                $remainingDue = max(0, (float) $payment->amount - $alreadyPaid);
                $paidInterest = min($remaining, $remainingDue);
                $remaining   -= $paidInterest;
                $interestAmount += $paidInterest;
            }
        }

        // ── Step 5: Leftover cash allocation ────────────────────────────────
        if ($remaining > 0) {
            if ($paymentMechanism === 'prepayment') {
                $prepayPrincipal += $remaining;
            } elseif ($paymentMechanism === 'interest') {
                $interestAmount += $remaining;
            } else {
                $principalAmount += $remaining;
            }
        }

        return [
            'penalty_amount'       => round($penaltyPaid, 2),
            'interest_amount'      => round($interestAmount, 2),
            'principal_amount'     => round($principalAmount, 2),
            'prepayment_principal' => round($prepayPrincipal, 2),
        ];
    }

    // ── Schedule recalculation proxy ─────────────────────────────────────────

    public function recalculateAmortizedSchedule(Contract $contract, ?string $date = null): void
    {
        $this->scheduledHandler->recalculateSchedule($contract, $date);
    }

    // ── Payment creation helper ──────────────────────────────────────────────

    public function createPayment(
        $contract_id, $amount, $type, $payer, $cash,
        $history = [], $deal_id = null, $date = null,
        $is_completed = false, $parent_id = null, $discountAmount = 0
    ): int {
        $status = ($type === 'penalty' || $type === 'full' || $type === 'partial')
            ? 'completed'
            : 'initial';

        $payment               = new Payment();
        $payment->contract_id  = $contract_id;
        $payment->amount       = $amount;
        $payment->type         = $type;
        $payment->cash         = $cash ?? true;
        $payment->is_completed = $is_completed;
        $payment->parent_id    = $parent_id;

        $user             = auth()->user() ?? User::where('id', 1)->first();
        $payment->pawnshop_id = $user->pawnshop_id;

        $lastPGI         = Payment::where('contract_id', $contract_id)->max('PGI_ID');
        $payment->PGI_ID = $lastPGI ? $lastPGI + 1 : 1;
        $payment->date   = $date ?? Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d');
        $payment->status = $status;
        $payment->discount_amount = ($payment->discount_amount ?? 0) + $discountAmount;

        if ($payer) {
            $payment->another_payer = true;
            $payment->name          = $payer['name'] ?? null;
            $payment->surname       = $payer['surname'] ?? null;
            $payment->phone         = $payer['phone'] ?? null;
        }
        $payment->save();

        if ($deal_id) {
            DealAction::create([
                'deal_id'         => $deal_id,
                'actionable_id'   => $payment->id,
                'actionable_type' => Payment::class,
                'amount'          => $amount,
                'type'            => $type,
                'description'     => $type . 'payment',
                'date'            => $date ?? Carbon::now()->format('Y-m-d'),
                'history'         => $history,
            ]);
        }

        return $payment->id;
    }

    // ── Private: accounting entries for principal reduction ──────────────────

    private function handleAccountingForPartial(
        $contract, $partialAmount, $date, $deal_id = null, $cash = null
    ): void {
        $clientId = $contract->client_id;
        $date     = $date ?? Carbon::now()->format('Y-m-d');
        $journal  = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();

        $filter     = $cash ? 'pay_mother_amount_cash' : 'pay_mother_amount';
        $ruleMother = PostingRule::where('business_event_filter', $filter)->first();

//        if ($ruleMother && $partialAmount > 0) {
//            $nextDocNum = Transaction::getNextDocumentNumber();
//            $journalDoc = DocumentJournal::create([
//                'date'              => $date,
//                'document_number'   => $nextDocNum,
//                'document_type'     => DocumentJournal::PAY_MOTHER_AMOUNT,
//                'amount_amd'        => $partialAmount,
//                'debit_partner_id'  => $ruleMother->resolveDebitPartnerId($contract) ?? null,
//                'credit_partner_id' => $ruleMother->resolveCreditPartnerId($contract) ?? $clientId,
//                'comment'           => 'mother_amount_payment',
//                'debit_account_id'  => $ruleMother->debit_account_id,
//                'credit_account_id' => $ruleMother->credit_account_id,
//                'user_id'           => auth()->id(),
//                'journalable_type'  => DocumentJournal::class,
//                'journalable_id'    => $journal->id,
//                'deal_id'           => $deal_id,
//                'contract_id'       => $contract->id,
//            ]);
//            Transaction::create([
//                'date'                 => $date,
//                'document_number'      => $nextDocNum,
//                'document_type'        => DocumentJournal::PAY_MOTHER_AMOUNT,
//                'debit_account_id'     => $ruleMother->debit_account_id,
//                'credit_account_id'    => $ruleMother->credit_account_id,
//                'debit_partner_id'     => $ruleMother->resolveDebitPartnerId($contract) ?? null,
//                'credit_partner_id'    => $ruleMother->resolveCreditPartnerId($contract) ?? $clientId,
//                'amount_amd'           => $partialAmount,
//                'comment'              => 'mother_amount_payment',
//                'user_id'              => auth()->id(),
//                'transactionable_type' => DocumentJournal::class,
//                'transactionable_id'   => $journalDoc->id,
//                'contract_id'          => $contract->id,
//            ]);
//        }

        $classificationName = $contract->client->classification->name ?? 'standard';
        $eventFilter        = ($classificationName === 'standard')
            ? 'provide_general_amount_change'
            : 'provide_special_amount_change';
        $ruleReserve = PostingRule::where('business_event_filter', $eventFilter)->first();

        if ($ruleReserve) {
            $reservePercent = $contract->client->classification->reserve_percent ?? 0;
            $reserveAmount  = $partialAmount * $reservePercent / 100;

            if ($reserveAmount > 0) {
                $nextDocNum   = Transaction::getNextDocumentNumber();
                $documentType = ($classificationName === 'standard')
                    ? DocumentJournal::PROVIDED_AMOUNT_CHANGE
                    : DocumentJournal::RESERVE_SPECIAL_AMOUNT;
                $journalDocRes = DocumentJournal::create([
                    'date'              => $date,
                    'document_number'   => $nextDocNum,
                    'document_type'     => $documentType,
                    'amount_amd'        => $reserveAmount,
                    'debit_partner_id'  => $ruleReserve->resolveDebitPartnerId($contract) ?? $clientId,
                    'credit_partner_id' => $ruleReserve->resolveCreditPartnerId($contract) ?? null,
                    'comment'           => 'reserve_payment',
                    'debit_account_id'  => $ruleReserve->debit_account_id,
                    'credit_account_id' => $ruleReserve->credit_account_id,
                    'user_id'           => auth()->id(),
                    'journalable_type'  => DocumentJournal::class,
                    'journalable_id'    => $journal->id,
                    'contract_id'       => $contract->id,
                ]);
                Transaction::create([
                    'date'                 => $date,
                    'document_number'      => $nextDocNum,
                    'document_type'        => $documentType,
                    'debit_account_id'     => $ruleReserve->debit_account_id,
                    'debit_partner_id'     => $ruleReserve->resolveDebitPartnerId($contract) ?? $clientId,
                    'credit_account_id'    => $ruleReserve->credit_account_id,
                    'credit_partner_id'    => $ruleReserve->resolveCreditPartnerId($contract) ?? null,
                    'amount_amd'           => $reserveAmount,
                    'comment'              => 'reserve_amount',
                    'user_id'              => auth()->id(),
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id'   => $journalDocRes->id,
                    'contract_id'          => $contract->id,
                ]);
            }
        }
    }

    // ── Future-interest coverage (mechanism: 'interest') ────────────────────

    private function applyExtraToFutureInterest(
        Contract $contract, float $extra, ?int $excludePaymentId = null,
        $deal_id = null, $cash = null, $date = null
    ): float {
        $applied       = 0.0;
        $balanceBefore = (float) $contract->provided_amount;

        $futurePayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->when($excludePaymentId, fn($q) => $q->where('id', '!=', $excludePaymentId))
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($futurePayments as $payment) {
            if ($extra <= 0) break;

            $alreadyPaidInterest = (float) PaymentEntry::where('payment_id', $payment->id)->sum('interest_amount');
            $interestDue         = max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
//          $interestDue         = max(0, (float) $payment->original_interest_payment - $alreadyPaidInterest);
            if ($interestDue <= 0) continue;

            $deduct   = min($extra, $interestDue);
            $extra   -= $deduct;
            $applied += $deduct;

            $alreadyPaidTotal = (float) PaymentEntry::where('payment_id', $payment->id)->sum('amount');
            if ($alreadyPaidTotal + $deduct >= (float) $payment->amount) {
                $payment->status = 'completed';
            }

            $payment->save();

            $existing = PaymentEntry::where('payment_id', $payment->id)
                ->when($deal_id, fn ($q) => $q->where('deal_id', $deal_id))
                ->latest('id')
                ->first();
            if ($existing) {
                $existing->amount          += $deduct;
                $existing->interest_amount += $deduct;
                $existing->balance_after    = $balanceBefore;
                $existing->date             = $date ?? $existing->date;
                $existing->save();
            } else {
                PaymentEntry::create([
                    'payment_id'       => $payment->id,
                    'contract_id'      => $contract->id,
                    'deal_id'          => $deal_id,
                    'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
                    'user_id'          => auth()->id() ?? 1,
                    'reference'        => Str::uuid(),
                    'amount'           => $deduct,
                    'principal_amount' => 0,
                    'interest_amount'  => $deduct,
                    'penalty_amount'   => 0,
                    'balance_before'   => $balanceBefore,
                    'balance_after'    => $balanceBefore,
                    'document_type'    => 'interest_payment',
                    'date'             => $date ?? Carbon::now()->format('Y-m-d'),
                    'cash'             => $cash ?? false,
                ]);
            }
        }

        return $applied;
    }
}
