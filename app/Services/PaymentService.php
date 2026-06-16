<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\Modification;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\PostingRule;
use App\Models\Prepayment;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\ContractTrait;
use App\Traits\CorrectReserveTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    use ContractTrait;
    use CorrectReserveTrait;
    protected $contractService;
    protected PrepaymentService $prepaymentService;
    public function __construct(ContractService $contractService, PrepaymentService $prepaymentService)
    {
        $this->contractService   = $contractService;
        $this->prepaymentService = $prepaymentService;
    }

    public function processPayments($contract, $amount, $payer, $cash, $payments, $deal_id, $journal_id = null, bool $forceScheduled = false, $interestAmount = 0, $ispPaymentSelected = false, $date = null, $paymentMechanism = null)
    {
        $payments_sum = 0;
        $interest_amount = 0;
        $principal_amount = 0;
        $prepayment_principal = 0;
        $initial_amount = $amount;

        $contract->historyContext = [
            'deal_id'     => $deal_id,
            'date'        => $date ?? now()->toDateString(),
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1,
        ];
        $old_provided = $contract->provided_amount;
        $old_left = $contract->left;
        $old_collected = $contract->collected;
        $result_penalty = $this->countPenalty($contract->id,$date);
        $penalty = $result_penalty['penalty_amount'];
        $delay_days = $result_penalty['delay_days'];
        $parent_id = $result_penalty['parent_id'];
        $payed_penalty = 0;
        if ($penalty > 0) {
            $penaltyResult = $this->processPenalty($contract->id, $amount, $penalty, $payer, $cash, $deal_id, $parent_id,$date);
            $payed_penalty = $penaltyResult['penalty'];
            $amount = $penaltyResult['amount'];
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
                    $userId = auth()->id();
                    $nextDocNum = Transaction::getNextDocumentNumber();
                    $journalDocPenalty = DocumentJournal::create([
                        'date' => $date,
                        'document_number' => $nextDocNum,
                        'document_type' => DocumentJournal::PAY_PENALTY_AMOUNT,
                        'amount_amd' => $payed_penalty,
                        'debit_partner_id' => $rulePenalty->resolveDebitPartnerId($contract) ?? null,
                        'credit_partner_id' => $rulePenalty->resolveCreditPartnerId($contract) ?? $contract->client_id,
                        'comment' => 'Daily penalty accrual for contract #' . $contract->id,
                        'debit_account_id' => $rulePenalty->debit_account_id,
                        'credit_account_id' => $rulePenalty->credit_account_id,
                        'user_id' =>$userId,
                        'journalable_type' => DocumentJournal::class,
                        'journalable_id' => $journal_id,
                        'contract_id' => $contract->id,
                    ]);

                    Transaction::create([
                        'date' => $date,
                        'document_number' => $nextDocNum,
                        'document_type' => DocumentJournal::PAY_PENALTY_AMOUNT,
                        'debit_account_id' => $rulePenalty->debit_account_id,
                        'credit_account_id' => $rulePenalty->credit_account_id,
                        'debit_partner_id' => $rulePenalty->resolveDebitPartnerId($contract) ?? null,
                        'credit_partner_id' => $rulePenalty->resolveCreditPartnerId($contract) ?? $contract->client_id,
                        'amount_amd' => $payed_penalty,
                        'comment' => 'Daily penalty accrual for contract #' . $contract->id,
                        'user_id' => $userId,
                        'is_system' => true,
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id' => $journalDocPenalty->id,
                        'contract_id' => $contract->id,
                    ]);
                }
            }
        }
        if ($amount > 0) {
            $selectedTotalDue = $payments->sum(function ($p) {
                return (float) ($p->amount ?? 0) + (float) ($p->penalty ?? 0);
            });
            // If the entered amount can fully cover all selected rows, or the caller explicitly
            // requests scheduled (e.g. makePayment with explicit IDs), skip early split.
            $forceScheduledForSelected = $forceScheduled || ($amount >= $selectedTotalDue);
            foreach ($payments as $payment) {
                $payment = $this->normalizePaymentDates($payment, $contract);
                if ($payment->from_date >= $date && !$ispPaymentSelected) continue;
                if ($amount > 0) {
                    $result = $this->processSinglePayment(
                        $contract,
                        $payment,
                        $amount,
                        $payer,
                        $cash,
                        $deal_id,
                        $forceScheduledForSelected,
                        $interestAmount,
                        $date,
                        $paymentMechanism
                    );
                    $amount = $result['amount'];
                    $interestAmount = $result['remaining_interest'];
                    $interest_amount += $result['interest_amount'];
                    $principal_amount += $result['principal_amount'];
                    $prepayment_principal += $result['prepayment_principal'] ?? 0;
                }
            }
            if ($amount > 0) {
                $this->handleRemainingAmount($contract, $amount, $cash, $payments->last()->id, $deal_id, $date);

                $amount = 0;
            }

        }
        $contract->collected += $interest_amount;
        $contract->save();
        if ($principal_amount > 0 && $contract->payment_type == 'amortized') {
            $history = [];
            $history['contract_changes'] = [
                'old_left' => $old_left,
                'new_left' => $contract->left - $principal_amount,
                'old_provided' => $old_provided,
                'new_provided' => max(0, $contract->provided_amount - $principal_amount),
                'old_collected' => $old_collected,
                'contract_id' => $contract->id,
            ];
            DealAction::create([
                'deal_id' => $deal_id,
                'actionable_id' => $contract->id,
                'actionable_type' => Contract::class,
                'amount' => $principal_amount,
                'type' => 'partial',
                'description' => 'Partial payment contract changes',
                'date' => $date,
                'history' => $history
            ]);
            Modification::create([
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'modification_type' => 'Modificator',
                'field_code' => 'PrincipalAmount',
                'element_code' => 'Amount',
                'old_value' => $old_provided !== null ? (string)$old_provided : null,
                'new_value' => (string)max(0, $contract->provided_amount - $principal_amount),
                'effective_date' => $date ?? now()->toDateString(),
            ]);

        }
        if ($interest_amount > 0) {
            Modification::create([
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'modification_type' => 'Modificator',
                'field_code' => 'PercentsPaid',
                'element_code' => 'Amount',
                'old_value' => $old_collected !== null ? (string)$old_collected : null,
                'new_value' => (string)max(0, $old_collected + $interest_amount),
                'effective_date' => $date ?? now()->toDateString(),
            ]);
        }

        return [
            'payments_sum'        => $payments_sum,
            'interest_amount'     => $interest_amount,
            'principal_amount'    => $principal_amount,
            'prepayment_principal'=> $prepayment_principal,
            'penalty'             => $payed_penalty,
            'delay_days'          => $delay_days,
            'discount'            => 0
        ];
    }
    public function processPenalty($contractId, $amount, $penalty, $payer, $cash, $deal_id = null, $parent_id = null, $isDiscount = false,$date = null)
    {
        $balance = (float) (\App\Models\Contract::find($contractId)->provided_amount ?? 0);

        if ($amount < $penalty) {
            $discountAmount = $isDiscount ? $amount : 0;
            $paymentId = $this->createPayment($contractId, $amount, 'penalty', $payer, $cash, [], $deal_id, $date, false, $parent_id, $discountAmount);
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
            return [
                'penalty' => $amount,
                'amount' => 0,
                'payment_id' => $paymentId
            ];
        } else {
            $discountAmount = $isDiscount ? $penalty : 0;
            $paymentId = $this->createPayment($contractId, $penalty, 'penalty', $payer, $cash, [], $deal_id, $date, true, $parent_id, $discountAmount,$date);
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
            return [
                'penalty' => $penalty,
                'amount' => $amount - $penalty,
                'payment_id' => $paymentId
            ];
        }
    }

    private function processSinglePayment(
        $contract, $payment, $amount, $payer, $cash, $deal_id,
        bool $forceScheduledForSelected = false, $interestAmount = 0, $date = null, $paymentMechanism = null
    ): array {
        $balanceBefore = (float) $contract->provided_amount;

        if ($contract->payment_type === 'amortized' && $paymentMechanism === 'prepayment') {
            $result = $this->handlePrepayment($contract, $payment, $payer, $cash, $deal_id, $amount, $interestAmount, $date, $balanceBefore);
        } elseif ($contract->payment_type === 'amortized') {
            $result = $this->handleAmortizedPayment($contract, $payment, $payer, $cash, $deal_id, $amount, $interestAmount, $date, $balanceBefore);
        } else {
            $result = $this->handleClassicPayment($contract, $payment, $payer, $cash, $deal_id, $amount, $interestAmount, $date, $balanceBefore);
        }

        $contract->save();
        $payment->save();

        return $result;
    }

    private function handlePrepayment(
        $contract, $payment, $payer, $cash, $deal_id,
        float $amount, float $interestAmount, ?string $date, float $balanceBefore
    ): array {
        $remainingAmount         = $amount;
        $remainingInterestAmount = $interestAmount;
        // Pay scheduled interest first
        $alreadyPaidInterest   = (float) $payment->entries()->sum('interest_amount');
        $remainingInterestPlan = max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
        $paidInterest          = min($remainingInterestPlan, $remainingAmount);
        $remainingInterestAmount -= $paidInterest;
        $remainingAmount         -= $paidInterest;

        // Pay scheduled principal
        $alreadyPaidPrincipal = (float) $payment->entries()->sum('principal_amount');
        $remainingPrincipal   = max(0, (float) ($payment->principal_payment ?? 0) - $alreadyPaidPrincipal);
        $paidPrincipal        = min($remainingAmount, $remainingPrincipal);
        $remainingAmount     -= $paidPrincipal;
        // Reduce loan balance immediately (prepayment commits the principal reduction)
        if ($paidPrincipal > 0) {
            $contract->left            = max(0, $contract->left - $paidPrincipal);
            $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipal);
        }

        $totalPaid   = $paidInterest + $paidPrincipal;
        $alreadyPaid = (float) $payment->entries()->sum('amount');
        $balanceAfter = (float) $contract->provided_amount;

        if ($alreadyPaid + $totalPaid >= (float) $payment->amount - 0.01) {
            $this->completePayment($payment, $payer, $cash, $contract->id, $deal_id,
                $paidPrincipal, $paidInterest, $date, $balanceBefore, $balanceAfter, $totalPaid);
        } else {
            $this->partiallyCompletePayment($payment, $totalPaid, $deal_id, [],
                $paidPrincipal, $paidInterest, $date, $balanceBefore, $balanceAfter);
        }

        // Before due date → register as prepayment (cash goes to liability 39920, not loan account)
        $due = Carbon::parse($payment->to_date ?? $payment->date)->startOfDay();
        $now = $date ? Carbon::parse($date, 'Asia/Yerevan')->startOfDay() : Carbon::now('Asia/Yerevan')->startOfDay();

        $prepaymentPrincipal = 0;
        if ($due->gt($now) && $paidPrincipal > 0) {
            $this->prepaymentService->createSingle($contract->id, $payment->id, $deal_id, $paidPrincipal, $payment->to_date);
            $prepaymentPrincipal = $paidPrincipal;
            $paidPrincipal       = 0;
        }

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => $paidPrincipal,
            'prepayment_principal' => $prepaymentPrincipal,
            'amount'               => $remainingAmount,
            'remaining_interest'   => $remainingInterestAmount,
        ];
    }

    private function handleAmortizedPayment(
        $contract, $payment, $payer, $cash, $deal_id,
        float $amount, float $interestAmount, ?string $date, float $balanceBefore
    ): array {
        $remainingAmount         = $amount;
        $remainingInterestAmount = $interestAmount;

        $earlySplit = $amount + 10 >= $payment->amount
            ? $this->tryEarlyAmortizedPaymentSplit($contract, $payment, $remainingAmount, $date)
            : null;

        if ($earlySplit !== null) {
            return $this->applyEarlySplit($contract, $payment, $payer, $cash, $deal_id, $earlySplit, $date, $balanceBefore);
        }

        // Scheduled path: interest first, then principal if past due
        $alreadyPaidInterest   = (float) $payment->entries()->sum('interest_amount');
        $remainingInterestPlan = max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
        $paidInterest          = 0;
        if ($remainingInterestAmount > 0 && $remainingInterestPlan > 0) {
            $paidInterest            = min($remainingInterestAmount, $remainingInterestPlan, $amount);
            $remainingInterestAmount -= $paidInterest;
            $remainingAmount         -= $paidInterest;
        }

        $paidPrincipal = 0;
        if ($payment->to_date <= ($date ?? now()->format('Y-m-d'))) {
            $alreadyPaidPrincipal = (float) $payment->entries()->sum('principal_amount');
            $remainingPrincipal   = max(0, (float) $payment->principal_payment - $alreadyPaidPrincipal);
            $paidPrincipal        = min($remainingAmount, $remainingPrincipal);
            $remainingAmount     -= $paidPrincipal;

            $contract->left            = max(0, $contract->left - $paidPrincipal);
            $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipal);
        }

        $this->recordEntry($contract, $payment, $payer, $cash, $deal_id, $paidInterest, $paidPrincipal, $amount, $date, $balanceBefore);

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => $paidPrincipal,
            'prepayment_principal' => 0,
            'amount'               => $remainingAmount,
            'remaining_interest'   => $remainingInterestAmount,
        ];
    }

    private function applyEarlySplit(
        $contract, $payment, $payer, $cash, $deal_id,
        array $split, ?string $date, float $balanceBefore
    ): array {
        $paidInterest     = $split['paid_interest'];
        $principalForLine = $split['principal_for_line'];
        $remainingCash    = $split['remaining_cash'];

        $contract->left            = max(0, $contract->left - $principalForLine);
        $contract->provided_amount = max(0, $contract->provided_amount - $principalForLine);
        $payment->remaining        = max(0, (float) ($payment->remaining - $remainingCash));

        $this->completePayment(
            $payment, $payer, $cash, $contract->id, $deal_id,
            $principalForLine, $paidInterest,
            $date, $balanceBefore, (float) $contract->provided_amount,
            $paidInterest + $principalForLine
        );

        // Recalculate future interest on the reduced principal
        if ((float) $payment->amount <= 0) {
            $due = Carbon::parse($payment->to_date ?? $payment->date)->startOfDay();
            $now = $date
                ? Carbon::parse($date, 'Asia/Yerevan')->startOfDay()
                : Carbon::now('Asia/Yerevan')->startOfDay();

            if ($due->gt($now)) {
                $remaining = Payment::where('contract_id', $contract->id)
                    ->where('type', 'regular')->where('status', 'initial')
                    ->orderBy('date')->orderBy('id')
                    ->get();

                if ($remaining->isNotEmpty()) {
                    $this->recalculateAmortizedInterestFromSchedule($contract, $remaining, $now);
                }
            }
        }

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => $principalForLine,
            'prepayment_principal' => 0,
            'amount'               => $remainingCash,
            'remaining_interest'   => 0,
        ];
    }

    private function handleClassicPayment(
        $contract, $payment, $payer, $cash, $deal_id,
        float $amount, float $interestAmount, ?string $date, float $balanceBefore
    ): array {
        $alreadyPaid     = (float) $payment->entries()->sum('amount');
        $remainingDue    = max(0, (float) $payment->amount - $alreadyPaid);
        $paidInterest    = min($amount, $remainingDue);
        $remainingAmount = $amount - $paidInterest;

        $this->recordEntry($contract, $payment, $payer, $cash, $deal_id, $paidInterest, 0, $amount, $date, $balanceBefore);

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => 0,
            'prepayment_principal' => 0,
            'amount'               => $remainingAmount,
            'remaining_interest'   => $interestAmount,
        ];
    }

    private function recordEntry(
        $contract, $payment, $payer, $cash, $deal_id,
        float $paidInterest, float $paidPrincipal, float $originalAmount, ?string $date, float $balanceBefore
    ): void {
        $alreadyPaid      = (float) $payment->entries()->sum('amount');
        $totalRemaining   = max(0, (float) $payment->amount - $alreadyPaid);
        $balanceAfter     = (float) $contract->provided_amount;

        if ($originalAmount >= $totalRemaining) {
            // Fill any rounding gap so entry totals match the due amount exactly
            if ($paidPrincipal == 0 && $totalRemaining > $paidInterest) {
                $gap           = $totalRemaining - $paidInterest;
                $paidPrincipal = $gap;
                $contract->left            = max(0, $contract->left - $gap);
                $contract->provided_amount = max(0, $contract->provided_amount - $gap);
                $balanceAfter              = (float) $contract->provided_amount;
            }
            $this->completePayment($payment, $payer, $cash, $contract->id, $deal_id,
                $paidPrincipal, $paidInterest,
                $date, $balanceBefore, $balanceAfter, $totalRemaining);
        } else {
            $this->partiallyCompletePayment($payment, $paidInterest + $paidPrincipal, $deal_id, [],
                $paidPrincipal, $paidInterest,
                $date, $balanceBefore, $balanceAfter);
        }
    }

    /**
     * Early payment (before installment due date): split cash using
     * cash = past_interest + calcAmount(P - x, future_days) + x (same basis as ContractTrait::calcAmount).
     * Returns null when due date is today or past, or split does not apply.
     */
    private function tryEarlyAmortizedPaymentSplit(Contract $contract, Payment $payment, float $cashAfterPenalty, $paymentDate = null): ?array
    {
        if ($payment->type !== 'regular' || $payment->status !== 'initial') {
            return null;
        }

        $due = Carbon::parse($payment->to_date ?? $payment->date)->startOfDay();
        $now = $paymentDate
            ? Carbon::parse($paymentDate)->setTimezone('Asia/Yerevan')->startOfDay()
            : Carbon::now('Asia/Yerevan')->startOfDay();

        if (!$due->gt($now)) {
            return null;
        }

        $from = Carbon::parse($payment->from_date)->startOfDay();
        $elapsedDays = max(1, $from->diffInDays($now));
        $futureDays = $now->diffInDays($due);
        if ($futureDays < 1) {
            return null;
        }
        $P = (float) $contract->provided_amount;
        $rate = (float) $contract->interest_rate;

        if ($P <= 0 || $cashAfterPenalty <= 0) {
            return null;
        }
        $pastInterest = $P* $elapsedDays * $rate / 100;
        $kFuture = $futureDays * ($rate / 100);
        $denom = 1 - $kFuture;
        if (abs($denom) < 1e-9) {
            return null;
        }
        $x = ($cashAfterPenalty - $pastInterest - $P * $kFuture) / $denom;

        if ($x < 0) {
            return null;
        }
        $x = min($x, $P, $cashAfterPenalty);

        // Keep precision here; ContractTrait::calcAmount returns int and truncates decimals.
        $futureInterest = max(0, (max(0, $P - $x) * (int) $futureDays * ($rate / 100)));
        $paidInterest = $pastInterest + $futureInterest;
        $paidPrincipal = $x;

//        if ($paidInterest + $paidPrincipal > $cashAfterPenalty + 1.0) {
//            return null;
//        }

        $principalForLine = min($paidPrincipal, (float) ($payment->principal_payment ?? 0));
        $remainingCash = $cashAfterPenalty - $paidInterest - $principalForLine;
        if ($remainingCash < 0) {
            $remainingCash = 0;
        }

        return [
            'paid_interest' => (float) $paidInterest,
            'paid_principal' => (float) $paidPrincipal,
            'principal_for_line' => (float) $principalForLine,
            'initial_principal' => (float) $payment->principal_payment,
            'remaining_cash' => (float) $remainingCash,
        ];
    }
    private function completePayment(
        $payment, $payer, $cash, $contract_id,
        $deal_id = null, $principal_payment = null, $interest_payment = null,
        $date = null, float $balanceBefore = 0, float $balanceAfter = 0,
        float $amountPaid = 0
    ): void {
        // actual amount paid = remaining after previous partial payments
        $oldAmount = $amountPaid > 0 ? $amountPaid : (float) $payment['amount'];
        $oldDate   = $payment['date'];

        $payment->status = 'completed';
        $payment->save();

        $meta = $payer ? [
            'another_payer' => true,
            'name'          => $payer['name'] ?? null,
            'surname'       => $payer['surname'] ?? null,
            'phone'         => $payer['phone'] ?? null,
        ] : null;

        PaymentEntry::create([
            'payment_id'       => $payment->id,
            'contract_id'      => $contract_id,
            'deal_id'          => $deal_id,
            'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
            'user_id'          => auth()->id() ?? 1,
            'reference'        => Str::uuid(),
            'amount'           => $oldAmount,
            'principal_amount' => $principal_payment ?? 0,
            'interest_amount'  => $interest_payment  ?? 0,
            'penalty_amount'   => 0,
            'balance_before'   => $balanceBefore,
            'balance_after'    => $balanceAfter,
            'document_type'    => 'regular_payment',
            'date'             => $date ?? Carbon::now()->format('Y-m-d'),
            'cash'             => $cash ?? false,
            'meta_data'        => $meta,
        ]);

        $history['payment_changes'][] = [
            'payment_id'    => $payment->id,
            'old_amount'    => $oldAmount,
            'old_date'      => $oldDate,
            'old_mother'    => $payment->mother,
            'old_principal' => $principal_payment,
            'old_interest'  => $interest_payment,
            'updated_at'    => now()->toDateTimeString(),
        ];

        DealAction::create([
            'deal_id'         => $deal_id,
            'actionable_id'   => $payment->id,
            'actionable_type' => Payment::class,
            'amount'          => $oldAmount,
            'type'            => 'regular',
            'description'     => 'Regular payment',
            'date'            => $date ?? Carbon::now()->format('Y-m-d'),
            'history'         => $history,
        ]);
    }

    private function partiallyCompletePayment(
        $payment, $paid, $deal_id = null, $history = [],
        float $principalPaid = 0, float $interestPaid = 0,
        $date = null, float $balanceBefore = 0, float $balanceAfter = 0
    ): void {
        $oldAmount = $payment->amount;
        $oldDate   = $payment->date;

        PaymentEntry::create([
            'payment_id'       => $payment->id,
            'contract_id'      => $payment->contract_id,
            'deal_id'          => $deal_id,
            'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
            'user_id'          => auth()->id() ?? 1,
            'reference'        => Str::uuid(),
            'amount'           => $paid,
            'principal_amount' => $principalPaid,
            'interest_amount'  => $interestPaid,
            'penalty_amount'   => 0,
            'balance_before'   => $balanceBefore,
            'balance_after'    => $balanceAfter,
            'document_type'    => 'regular_payment',
            'date'             => $date ?? Carbon::now()->format('Y-m-d'),
            'cash'             => false,
        ]);

        $history['payment_changes'][] = [
            'payment_id'    => $payment->id,
            'old_amount'    => $oldAmount,
            'old_date'      => $oldDate,
            'old_principal' => $principalPaid,
            'old_interest'  => $interestPaid,
            'updated_at'    => now()->toDateTimeString(),
        ];

        DealAction::create([
            'deal_id'         => $deal_id,
            'actionable_id'   => $payment->id,
            'actionable_type' => Payment::class,
            'amount'          => $paid,
            'type'            => 'regular',
            'description'     => 'Regular payment',
            'date'            => $date ?? Carbon::now()->format('Y-m-d'),
            'history'         => $history,
        ]);
    }

    private function handleRemainingAmount($contract, $amount, $cash, $payment_id, $deal_id = null,$date = null)
    {
        $nextPayment = Payment::where('contract_id', $contract->id)->where('status', 'initial')
            ->where('id', '!=', $payment_id)
            ->orderBy('date', 'asc')->orderBy('id', 'asc')
            ->first();
        $decrease = null;
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
                'payment_id'    => $nextPayment->id,
                'contract_id'   => $contract->id,
                'deal_id'       => $deal_id,
                'pawnshop_id'   => auth()->user()->pawnshop_id ?? 1,
                'user_id'       => auth()->id() ?? 1,
                'reference'     => Str::uuid(),
                'amount'        => $decrease,
                'balance_before'=> $balanceBefore,
                'balance_after' => $balanceBefore,
                'document_type' => 'regular_payment',
                'date'          => $date ?? Carbon::now()->format('Y-m-d'),
                'cash'          => false,
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
            //$contract->collected += $decrease;

        }
        if ($amount >= 1) {
            $this->payPartial($contract, $amount, false, $cash, $deal_id,$date,false,true);
        }
        return $decrease;


    }

    private function applyExtraToFutureInterest(Contract $contract, float $extra, ?int $excludePaymentId = null): void
    {
        $futurePayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->when($excludePaymentId, fn($q) => $q->where('id', '!=', $excludePaymentId))
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($futurePayments as $payment) {
            if ($extra <= 0) break;

            $deduct = min($extra, (float) $payment->interest_payment);
            if ($deduct <= 0) continue;

            $payment->interest_payment -= $deduct;
            $payment->amount = max(0, $payment->amount - $deduct);
            $extra -= $deduct;

            if ((float) $payment->amount <= 0) {
                $payment->status = 'completed';
            }

            $payment->save();
        }
    }

    public function createPayment($contract_id, $amount, $type, $payer, $cash, $history = [], $deal_id = null, $date = null, $is_completed = false, $parent_id = null, $discountAmount = 0)
    {
        // $status = ($type === 'penalty' ||  $type === 'full') ? 'completed' : 'initial';
        if ($type === 'penalty' || $type === 'full' || $type === 'partial') {
            $status = 'completed';
        } else {
            $status = 'initial';
        }
        $payment = new Payment();
        $payment->contract_id = $contract_id;
        $payment->amount = $amount;
        $payment->type = $type;
        $payment->cash = $cash ?? true;
        $payment->is_completed = $is_completed;
        $payment->parent_id = $parent_id;
        $user = auth()->user() ?? User::where('id', 1)->first();
        $payment->pawnshop_id = $user->pawnshop_id;
        $lastPGI = Payment::where('contract_id', $contract_id)->max('PGI_ID');
        $payment->PGI_ID = $lastPGI ? $lastPGI + 1 : 1;
        // $payment->paid_date = Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d');
        $payment->date = $date ?? Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d');
        $payment->status = $status;
        $payment->discount_amount = ($payment->discount_amount ?? 0) + $discountAmount;

        if ($payer) {
            $payment->another_payer = true;
            $payment->name = $payer['name'] ?? null;
            $payment->surname = $payer['surname'] ?? null;
            $payment->phone = $payer['phone'] ?? null;
        }
        $payment->save();
        if ($deal_id) {
            DealAction::create([
                'deal_id' => $deal_id,
                'actionable_id' => $payment->id,
                'actionable_type' => Payment::class,
                'amount' => $amount,
                'type' => $type,
                'description' => $type . 'payment',
                'date' => $date ?? Carbon::now()->format('Y-m-d'),
                'history' => $history,
            ]);
        }
        return $payment->id;
    }

    public function payPartial($contract, $partialAmount, $payer, $cash, $deal_id = null, $date = null,$is_recount = false,$is_remaining_payment = false)
    {
        $now = $date ?? Carbon::now();
        $balanceBefore = (float) $contract->provided_amount;
        $history = ['payment_changes' => []];

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
                $history['payment_changes'] = $remainingPayments->map(fn($p) => [
                    'payment_id' => $p->id,
                    'old_amount' => $p->amount,
                    'old_paid' => $p->paid,
                    'old_date' => $p->date,
                    'old_principal' => $p->principal_payment,
                    'old_interest' => $p->interest_payment,
                ])->toArray();

                $remainingMonths = $remainingPayments->count();
                if ($remainingMonths > 0) {
                    Payment::where('contract_id', $contract->id)
                        ->where('type', 'regular')
                        ->where('status', 'initial')
                        ->delete();

                    $targetDate = $date ?? Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d');
                    $history['contract_changes'] = [
                        'old_left' => $contract->left,
                        'new_left' => $contract->left - $partialAmount,
                        'old_provided' => $contract->provided_amount,
                        'new_provided' => max(0, $contract->provided_amount - $partialAmount),
                        'old_collected' => $contract->collected,
                        'contract_id' => $contract->id,
                    ];
                    Modification::create([
                        'subject_type' => Contract::class,
                        'subject_id' => $contract->id,
                        'modification_type' => 'Modificator',
                        'field_code' => 'PrincipalAmount',
                        'element_code' => 'Amount',
                        'old_value' => $contract->provided_amount !== null ? (string)$contract->provided_amount : null,
                        'new_value' => (string)max(0, $contract->provided_amount - $partialAmount),
                        'effective_date' => $date ?? now()->toDateString(),
                    ]);

                    $contract->left = max(0, $contract->left - $partialAmount);
//                    $contract->collected += $partialAmount;
                    $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
                    $contract->save();
                    $this->contractService->createPayment($contract, $targetDate, null, $remainingMonths);
                }
            } else {
                $providedAmount = $contract->provided_amount - $partialAmount;
                $contract->provided_amount = max(0, $providedAmount);
                $contract->left = max(0, $contract->left - $partialAmount);
//                $contract->collected += $partialAmount;
                $contract->save();
                $history['payment_changes'] = $this->processAmortizedPayments($contract, $payments, $partialAmount, $now);
                $history['contract_changes'] = [
                    'old_left' => $contract->left,
                    'new_left' => $contract->left - $partialAmount,
                    'old_provided' => $contract->provided_amount,
                    'new_provided' => max(0, $contract->provided_amount - $partialAmount),
                    'old_collected' => $contract->collected,
                    'contract_id' => $contract->id,
                ];
                Modification::create([
                    'subject_type' => Contract::class,
                    'subject_id' => $contract->id,
                    'modification_type' => 'Modificator',
                    'field_code' => 'PrincipalAmount',
                    'element_code' => 'Amount',
                    'old_value' => $contract->provided_amount !== null ? (string)$contract->provided_amount : null,
                    'new_value' => (string)max(0, $contract->provided_amount - $partialAmount),
                    'effective_date' => $date ?? now()->toDateString(),
                ]);

            }
            DealAction::create([
                'deal_id' => $deal_id,
                'actionable_id' => $contract->id,
                'actionable_type' => Contract::class,
                'amount' => $partialAmount,
                'type' => 'partial',
                'description' => $is_recount ? 'Partial payment with schedule recount' : 'Partial payment with amount reduction',
                'date' => $date ?? Carbon::now()->format('Y-m-d'),
                'history' => $history
            ]);
        } else {
            $paymentResult = $this->processClassicPayments($payments, $contract, $partialAmount, $now);
            $history['payment_changes'] = $paymentResult['payments'];
            $history['mother_amount']   = $paymentResult['mother_amount'];
            $history['contract_changes'] = [
                'old_left' => $contract->left,
                'new_left' => $contract->left - $partialAmount,
                'old_provided' => $contract->provided_amount,
                'new_provided' => max(0, $contract->provided_amount - $partialAmount),
                'old_collected' => $contract->collected,
                'contract_id' => $contract->id,
            ];
            Modification::create([
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'modification_type' => 'Modificator',
                'field_code' => 'PrincipalAmount',
                'element_code' => 'Amount',
                'old_value' => $contract->provided_amount !== null ? (string)$contract->provided_amount : null,
                'new_value' => (string)max(0, $contract->provided_amount - $partialAmount),
                'effective_date' => $date ?? now()->toDateString(),
            ]);
            $contract->left = max(0, $contract->left - $partialAmount);
//            $contract->collected += $partialAmount;
            $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
            $contract->save();
        }
        $this->handleAccountingForPartial($contract, $partialAmount, $date,$deal_id,$cash);

        $balanceAfter = (float) $contract->provided_amount;
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
                $runningBalance = $entryBalanceAfter;
            }
        } else {
            // is_recount or classic: single entry linked to next scheduled payment
            $nextPayment = Payment::where('contract_id', $contract->id)
                ->where('type', 'regular')
                ->where('status', 'initial')
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            $firstPaymentId = $nextPayment?->id;
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

        return $firstPaymentId;
    }

    /**
     * Applies prepayment to future installments: reduces principal_payment in order,
     * then recalculates interest on the outstanding balance for each period (same basis as schedule).
     */
    protected function processAmortizedPayments(Contract $contract, $payments, $remainingPartial, $now)
    {
        $payments = $payments->where('status','initial')->sortBy(fn ($p) => [$p->date, $p->id ?? 0])->values();
        $changes = [];
        foreach ($payments as $payment) {
            if ($remainingPartial <= 0) {
                break;
            }

            // Subtract what was already paid toward this payment's principal via entries
            $alreadyPaidPrincipal = (float) PaymentEntry::where('payment_id', $payment->id)->sum('principal_amount');
            $effectiveRemaining   = max(0, (float) $payment->principal_payment - $alreadyPaidPrincipal);
            $reduction = min($remainingPartial, $effectiveRemaining);
            if ($reduction <= 0) {
                continue;
            }

            $oldData = [
                'payment_id' => $payment->id,
                'old_amount' => $payment->amount,
                'old_paid' => $payment->paid,
                'old_principal' => $payment->principal_payment,
                'old_date' => $payment->date,
                'old_interest' => $payment->interest_payment,
                'reduction' => $reduction,
            ];

            // Mutate in-memory only — do NOT save yet.
            // amount is intentionally left unchanged here; recalculateAmortizedInterestFromSchedule
            // will set the correct value (new_principal + new_interest) and persist everything
            // in a single pass, preventing a window where amount = old_interest + new_principal.
//            $payment->principal_payment -= $reduction;
            $remainingPartial -= $reduction;
            $payment->save();
            $changes[] = $oldData;
        }
        if (!empty($changes)) {
            $remainingInitialPayments = Payment::where('contract_id', $contract->id)
                ->where('type', 'regular')
                ->where('status', 'initial')
                ->orderBy('to_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();
            // Single save point: recalculates interest on the updated principals and persists
            // all modified fields (paid, principal_payment, interest_payment, amount) atomically.
            $this->recalculateAmortizedInterestFromSchedule($contract, $remainingInitialPayments,$now);
        }

        foreach ($changes as &$change) {
            $p = Payment::find($change['payment_id']);
            if ($p) {
                $change['new_principal'] = $p->principal_payment;
                $change['new_interest'] = $p->interest_payment;
                $change['new_amount'] = $p->amount;
            }
        }
        unset($change);

        return $changes;
    }

    /**
     * Running balance: interest for each period = calcAmount(balance, days, rate) per schedule,
     * then balance -= principal_payment for that line.
     */
    /**
     * Recompute interest and line amounts on all open regular installments (e.g. after deal edit).
     */
    public function recalculateAmortizedSchedule(Contract $contract, ?string $date = null): void
    {
        $payments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->orderBy('to_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($payments->isEmpty()) {
            return;
        }

        $this->recalculateAmortizedInterestFromSchedule($contract, $payments, $date);
    }

    protected function recalculateAmortizedInterestFromSchedule(Contract $contract, $payments,$date = null): void
    {
        $payments = $payments->where('status','initial')->sortBy(fn ($p) => [$p->date, $p->id ?? 0])->values();
        $balance = (float) $contract->provided_amount;
        $rate = (float) $contract->interest_rate;
        $prevDate = Carbon::parse($contract->date);
        foreach ($payments as $payment) {
            $payment = $this->normalizePaymentDates($payment, $contract);
            $paymentDate = Carbon::parse($payment->to_date)->startOfDay();
            $fromDate = Carbon::parse($payment->from_date)->startOfDay();
            $now = $date ?? now()->startOfDay();

            $selectedDate = $fromDate->lt($now) ? $now : $fromDate;

            $days = max(1, $paymentDate->diffInDays($selectedDate));

            $prevDate = $paymentDate;
            $interest = $balance * $days * ($rate / 100);
            $diff = $payment->interest_payment - $interest;
            $payment->interest_payment = $interest;
            $payment->original_interest_payment -= $diff;
            $principal = (float) $payment->principal_payment;
            $fee = (float) ($payment->service_fee_payment ?? 0);
            $paid = (float) ($payment->paid ?? 0);
            $payment->amount = $payment->interest_payment + $principal;
            if ((float) $payment->amount <= 0) {
                $payment->status = 'completed';
            }

            $balance -= $principal;
            if ($balance < 0) {
                $balance = 0;
            }

            $payment->remaining = round($balance, 10);
            $payment->save();
        }
    }

    protected function processClassicPayments($payments, $contract, $partialAmount, $now)
    {
        $history = [
            'payments' => [],
            'mother_amount' => null
        ];
        $startedToChange = false;

        foreach ($payments as $index => $payment) {
            if ($payment->amount <= 0) continue;

            $payment = $this->normalizePaymentDates($payment, $contract);
            $dateToCheck = Carbon::parse($payment->date);
            if ($dateToCheck->gt($now)) {
                $oldPaid = $payment->paid;
                $oldAmount = $payment->amount;
                $oldDate = $payment->date;
                $oldPrincipal = $payment->principal_payment;
                $oldInterest = $payment->interest_payment;
                if ($startedToChange) {
                    $coeff = ($contract->left - $partialAmount) / $contract->left;
                    $newAmount = intval(ceil($oldAmount * $coeff / 10) * 10);
                } else {
                    $startedToChange = true;
                    $prevDate = $index === 0 ? $contract->date : $payments[$index - 1]->date;
                    $daysLeft = $payment->days - $now->diffInDays(Carbon::parse($prevDate));

                    $newAmount = $oldAmount
                        - $this->calcAmount($contract->left, $daysLeft, $contract->interest_rate)
                        + $this->calcAmount($contract->left - $partialAmount, $daysLeft, $contract->interest_rate);
                }

                $payment->amount = max(0, $newAmount);
                $payment->save();

                $history['payments'][] = [
                    'payment_id' => $payment->id,
                    'old_amount' => $oldAmount,
                    'new_amount' => $payment->amount,
                    'old_paid'   => $oldPaid,
                    'old_date'   => $oldDate,
                    'old_principal' => $oldPrincipal,
                    'old_interest' => $oldInterest,
                    'updated_at' => $now->toDateTimeString()
                ];
            }

            if ($payment->last_payment) {
                $this->updateLastPayment($payment, $contract->left - $partialAmount, $history);
            }
        }

        return $history;
    }


    private function updateLastPayment($payment, $newMother, &$history) {
        $history['mother_amount'] = [
            'payment_id' => $payment->id,
            'old_mother' => $payment->mother,
            'new_mother' => $newMother,
        ];

        $payment->mother = $newMother;
        if ($payment->mother <= 0) $payment->status = 'completed';
        $payment->save();
    }

    private function handleAccountingForPartial($contract, $partialAmount, $date,$deal_id=null,$cash = null)
    {
        $clientId = $contract->client_id;
        $date = $date ?? Carbon::now()->format('Y-m-d');
        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();
        $filter = $cash ? 'pay_mother_amount_cash' : 'pay_mother_amount';
        $ruleMother = PostingRule::where('business_event_filter', $filter)->first();
        if ($ruleMother && $partialAmount > 0) {
            $nextDocNum = Transaction::getNextDocumentNumber();
            $journalDoc = DocumentJournal::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => DocumentJournal::PAY_MOTHER_AMOUNT,
                'amount_amd' => $partialAmount,
                'debit_partner_id' => $ruleMother->resolveDebitPartnerId($contract) ?? null,
                'credit_partner_id' => $ruleMother->resolveCreditPartnerId($contract) ?? $clientId,
                'comment' => 'mother_amount_payment',
                'debit_account_id' => $ruleMother->debit_account_id,
                'credit_account_id' => $ruleMother->credit_account_id,
                'user_id' => auth()->id(),
                'journalable_type' => DocumentJournal::class,
                'journalable_id' => $journal->id,
                'deal_id' => $deal_id,
                'contract_id' => $contract->id,
            ]);

            Transaction::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => DocumentJournal::PAY_MOTHER_AMOUNT,
                'debit_account_id' => $ruleMother->debit_account_id,
                'credit_account_id' => $ruleMother->credit_account_id,
                'debit_partner_id' => $ruleMother->resolveDebitPartnerId($contract) ?? null,
                'credit_partner_id' => $ruleMother->resolveCreditPartnerId($contract) ?? $clientId,
                'amount_amd' => $partialAmount,
                'comment' => 'mother_amount_payment',
                'user_id' => auth()->id(),
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id' => $journalDoc->id,
                'contract_id' => $contract->id,
            ]);
        }

        $classificationName = $contract->client->classification->name ?? 'standard';

        $eventFilter = ($classificationName === 'standard')
            ? 'provide_general_amount_change'
            : 'provide_special_amount_change';

        $ruleReserve = PostingRule::where('business_event_filter', $eventFilter)->first();
        if ($ruleReserve) {
            $reservePercent = $contract->client->classification->reserve_percent ?? 0;
            $reserveAmount = $partialAmount * $reservePercent / 100;

            if ($reserveAmount > 0) {
                $nextDocNum = Transaction::getNextDocumentNumber();
                $documentType = ($classificationName === 'standard')
                    ? DocumentJournal::PROVIDED_AMOUNT_CHANGE
                    : DocumentJournal::RESERVE_SPECIAL_AMOUNT;
                $journalDocRes = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentType,
                    'amount_amd' => $reserveAmount,
                    'debit_partner_id' => $ruleReserve->resolveDebitPartnerId($contract) ?? $clientId,
                    'credit_partner_id' => $ruleReserve->resolveCreditPartnerId($contract) ?? null,
                    'comment' => 'reserve_payment',
                    'debit_account_id' => $ruleReserve->debit_account_id,
                    'credit_account_id' => $ruleReserve->credit_account_id,
                    'user_id' => auth()->id(),
                    'journalable_type' => DocumentJournal::class,
                    'journalable_id' => $journal->id,
                    'contract_id' => $contract->id,
                ]);

                Transaction::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentType,
                    'debit_account_id' => $ruleReserve->debit_account_id,
                    'debit_partner_id' => $ruleReserve->resolveDebitPartnerId($contract) ?? $clientId,
                    'credit_account_id' => $ruleReserve->credit_account_id,
                    'credit_partner_id' => $ruleReserve->resolveCreditPartnerId($contract) ?? null,
                    'amount_amd' => $reserveAmount,
                    'comment' => 'reserve_amount',
                    'user_id' => auth()->id(),
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id' => $journalDocRes->id,
                    'contract_id' => $contract->id,
                ]);
            }
        }
    }
    public function processFullPayment($contract, $amount, $payer, $cash, $deal_id = null,$date = null)
    {
        Payment::where('contract_id', $contract->id)
            ->where('status', 'initial')->delete();

        $providedAmount = $contract->provided_amount;
        $interestAmount = $amount - $providedAmount;

        $history['contract_changes'] = [
            'contract_id' => $contract->id,
            'old_left' => $contract->left,
            'new_left' => 0,
            'old_collected' => $contract->collected,
            'new_collected' => $contract->collected + $interestAmount,
            'old_provided' => $contract->provided_amount,
//            'old_estimated' => $contract->estimated_amount,
            'old_status' => 'initial',
            'new_status' => 'completed',
            'updated_at' => now()->toDateTimeString()
        ];

        $payment = $this->createPayment($contract->id, $amount, 'full', $payer, $cash, $history, $deal_id,$date);

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

        $contract->status = 'completed';
        $contract->left = 0;
        $contract->collected += $interestAmount;
        $contract->provided_amount = 0;
        $contract->historyContext = [
            'deal_id'     => $deal_id,
            'date'        => $date ?? now()->toDateString(),
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1,
        ];
        $contract->save();

        $nowDate = $date ?? now()->toDateString();

        $modifications = [
            [
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'modification_type' => 'Modificator',
                'field_code' => 'PrincipalAmount',
                'element_code' => 'Amount',
                'old_value' => $contract->provided_amount !== null ? (string)$contract->provided_amount : null,
                'new_value' => '0',
                'effective_date' => $nowDate,
            ],
            [
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'modification_type' => 'Modificator',
                'field_code' => 'PercentsPaid',
                'element_code' => 'Amount',
                'old_value' => $contract->collected !== null ? (string)$contract->collected : null,
                'new_value' => (string)($contract->collected + $interestAmount),
                'effective_date' => $nowDate,
            ],
            [
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'modification_type' => 'Modificator',
                'field_code' => 'LoanStatus',
                'element_code' => 'YN',
                'old_value' => 'Y',
                'new_value' => 'N',
                'effective_date' => $nowDate,
            ],
        ];

        Modification::insert($modifications);

        try {
            $this->releaseReserveBalancesIfClientFullyClosed(
                clientId:   $contract->client_id,
                contractId: $contract->id,
                date:       $nowDate,
            );
        } catch (\Throwable $e) {
            Log::error("Reserve release after full payment failed for contract #{$contract->id}: " . $e->getMessage());
        }

        return $payment;
    }
}
