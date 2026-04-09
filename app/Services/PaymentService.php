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
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\ContractTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    use ContractTrait;
    protected $contractService;
    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
    }

    public function processPayments($contract, $amount, $payer, $cash, $payments, $deal_id, $journal_id = null, bool $forceScheduled = false, $paymentDate = null)
    {
        $payments_sum = 0;
        $interest_amount = 0;
        $principal_amount = 0;
        $initial_amount = $amount;
        $old_provided = $contract->provided_amount;
        $old_left = $contract->left;
        $old_collected = $contract->collected;

        $result_penalty = $this->countPenalty($contract->id);
        $penalty = $result_penalty['penalty_amount'];
        $delay_days = $result_penalty['delay_days'];
        $parent_id = $result_penalty['parent_id'];
        $payed_penalty = 0;
        $date = Carbon::now()->format('Y-m-d');
        if ($penalty > 0) {
            $penaltyResult = $this->processPenalty($contract->id, $amount, $penalty, $payer, $cash, $deal_id, $parent_id);
            $payed_penalty = $penaltyResult['penalty'];
            $amount = $penaltyResult['amount'];
            if ($payed_penalty > 0) {
                $rulePenalty = PostingRule::where('business_event_filter', 'penalty_rate_amount')->first();
                if ($rulePenalty) {
                    $userId = auth()->id();
                    $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
                    $journalDocPenalty = DocumentJournal::create([
                        'date' => $date,
                        'document_number' => $nextDocNum,
                        'document_type' => DocumentJournal::PENALTY_RATE_AMOUNT,
                        'amount_amd' => $payed_penalty,
                        'credit_partner_id' => $contract->client_id,
                        'comment' => 'Daily penalty accrual for contract #' . $contract->id,
                        'debit_account_id' => $rulePenalty->debit_account_id,
                        'credit_account_id' => $rulePenalty->credit_account_id,
                        'user_id' =>$userId,
                        'journalable_type' => DocumentJournal::class,
                        'journalable_id' => $journal_id,
                    ]);

                    Transaction::create([
                        'date' => $date,
                        'document_number' => $nextDocNum,
                        'document_type' => DocumentJournal::PENALTY_RATE_AMOUNT,
                        'debit_account_id' => $rulePenalty->debit_account_id,
                        'credit_account_id' => $rulePenalty->credit_account_id,
                        'credit_partner_id' => $contract->client_id,
                        'amount_amd' => $payed_penalty,
                        'comment' => 'Daily penalty accrual for contract #' . $contract->id,
                        'user_id' => $userId,
                        'is_system' => true,
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id' => $journalDocPenalty->id,
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
                $result = $this->processSinglePayment(
                    $contract,
                    $payment,
                    $amount,
                    $payer,
                    $cash,
                    $deal_id,
                    $forceScheduledForSelected,
                    $paymentDate
                );
                $amount = $result['amount'];
                $interest_amount += $result['interest_amount'];
                $principal_amount += $result['principal_amount'];

            }

            if ($amount > 0) {
                $this->handleRemainingAmount($contract, $amount, $cash, $payments->last()->id, $deal_id);
                //$principal_amount += $amount;
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
                'effective_date' => now()->toDateString(),
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
                'effective_date' => now()->toDateString(),
            ]);
        }

        return [
            'payments_sum' => $payments_sum,
            'interest_amount' => $interest_amount,
            'principal_amount' => $principal_amount,
            'penalty' => $payed_penalty,
            'delay_days' => $delay_days,
            'discount' => 0
        ];
    }
    public function processPenalty($contractId, $amount, $penalty, $payer, $cash, $deal_id = null, $parent_id = null, $isDiscount = false)
    {
        if ($amount < $penalty) {
            $discountAmount = $isDiscount ? $amount : 0;
            $paymentId = $this->createPayment($contractId, $amount, 'penalty', $payer, $cash, [], $deal_id, null, false, $parent_id, $discountAmount);
            //return 0;
            return [
                'penalty' => $amount,
                'amount' => 0,
                'payment_id' => $paymentId
            ];
        } else {
            $discountAmount = $isDiscount ? $penalty : 0;
            $paymentId = $this->createPayment($contractId, $penalty, 'penalty', $payer, $cash, [], $deal_id, null, true, $parent_id, $discountAmount);
            //  return $amount - $penalty;
            return [
                'penalty' => $penalty,
                'amount' => $amount - $penalty,
                'payment_id' => $paymentId
            ];
        }
    }

    private function processSinglePayment($contract, $payment, $amount, $payer, $cash, $deal_id, bool $forceScheduledForSelected = false, $paymentDate = null)
    {
        $remainingAmount = $amount;
        $paidInterest = 0;
        $paidPrincipal = 0;

        $principalPayment = null;
        $interestPayment = null;
        $history = [];
        $earlyHandled = false;

        if ($contract->payment_type == 'amortized') {
            $principalPayment = $payment->principal_payment;
            $interestPayment = $payment->interest_payment;
            $dueSnapshot = (float) $payment->amount;

            // For multi-select full-cover flows, close selected rows first;
            // otherwise allow early split logic for partial early cash.
            $earlySplit = $amount + 10 >=$payment->amount ? $this->tryEarlyAmortizedPaymentSplit($contract, $payment, $remainingAmount, $paymentDate) : null;
            if ($earlySplit !== null) {
                $paidInterest = $earlySplit['paid_interest'];
                $paidPrincipal = $earlySplit['paid_principal'];
                $principalForLine = $earlySplit['principal_for_line'];
                $remainingAmount = $earlySplit['remaining_cash'];

                // Only reduce contract balance by principalForLine (the scheduled
                // principal for this installment). Any extra principal (x - scheduled)
                // sits in remainingCash and flows through handleRemainingAmount →
                // payPartial, which reduces future installments' principal and
                // recalculates interest on the new running balance.
                $contract->left = max(0, $contract->left - $principalForLine);
                $contract->provided_amount = max(0, $contract->provided_amount - $principalForLine);
//                $payment->principal_payment = max(0, (float) $payment->principal_payment - $principalForLine);
//                $payment->interest_payment = max(0, (float) $payment->interest_payment - $paidInterest);
//                $payment->remaining = max(
//                    0,
//                    (float) $contract->provided_amount - max(0, (float) $paidPrincipal - (float) $principalForLine)
//                );

                $cashAppliedToLine = $paidInterest + $principalForLine;
//                if ($amount >= $payment->amount) {
                    $this->completePayment($payment, $payer, $cash, $contract->id, $deal_id, $principalPayment, $interestPayment);
//                } else {
//                    $this->partiallyCompletePayment($payment, $cashAppliedToLine, $deal_id, [], $principalPayment, $interestPayment);
//                }
                $earlyHandled = true;

                // Update paidPrincipal to reflect only what was applied here;
                // the overflow is handled separately by handleRemainingAmount.
                $paidPrincipal = $principalForLine;
            } else {
                $remainingInterestPlan = $payment->interest_payment;

                $paidInterest = min($remainingAmount, $remainingInterestPlan);
                $remainingAmount -= $paidInterest;

                $paidPrincipal = min($remainingAmount, $payment->principal_payment ?? 0);
                $remainingAmount -= $paidPrincipal;

                $contract->left = max(0, $contract->left - $paidPrincipal);
                $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipal - $paidInterest);
                $payment->principal_payment -= $paidPrincipal;
                $payment->interest_payment -= $paidInterest;
                $payment->remaining = max(0, (float) $contract->provided_amount);
            }
        } else {
            $paidInterest = min($remainingAmount, $payment->amount);
            $remainingAmount -= $paidInterest;
            $paidPrincipal = 0;
        }

        if (!$earlyHandled) {
            $totalRequiredForThisLine = $payment->amount;
            if ($amount >= $totalRequiredForThisLine) {
                $this->completePayment($payment, $payer, $cash, $contract->id, $deal_id, $principalPayment, $interestPayment);
            } else {
                $this->partiallyCompletePayment($payment, $amount, $deal_id, [], $principalPayment, $interestPayment);
            }
        }

        // When an amortized installment was handled via early split and paid earlier than
        // its due date, update remaining schedule interest on the reduced principal.
        // Skip this for regular scheduled payments (no early split) — their schedule is
        // already correct and recalculating introduces unnecessary floating-point drift.
        if ($earlyHandled && $contract->payment_type === 'amortized' && (float) $payment->amount <= 0) {
            $due = Carbon::parse($payment->to_date ?? $payment->date)->startOfDay();
            $now = $paymentDate
                ? Carbon::parse($paymentDate, 'Asia/Yerevan')->startOfDay()
                : Carbon::now('Asia/Yerevan')->startOfDay();
            if ($due->gt($now)) {
                $remainingInitialPayments = Payment::where('contract_id', $contract->id)
                    ->where('type', 'regular')
                    ->where('status', 'initial')
                    ->orderBy('date', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();
                if ($remainingInitialPayments->isNotEmpty()) {
                    $this->recalculateAmortizedInterestFromSchedule($contract, $remainingInitialPayments);
                }
            }
        }

//        $contract->collected += $amount;
        $contract->save();

        return [
            'interest_amount'  => $paidInterest,
            'principal_amount' => $paidPrincipal,
            'amount'           => $remainingAmount
        ];
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
        $now = Carbon::now('Asia/Yerevan')->startOfDay();

        if (!$due->isFuture()) {
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
            'remaining_cash' => (float) $remainingCash,
        ];
    }
    private function completePayment($payment, $payer, $cash, $contract_id, $deal_id = null,$principal_payment = null,$interest_payment = null): void
    {
        $oldAmount = $payment['amount'];
        $oldPaid = $payment['paid'];
        $oldDate = $payment['date'];
        $payment->paid += $payment['amount'] + $payment['penalty'];
        //$payment->paid_date = Carbon::now()->format('Y.m.d');
        if ($payment->last_payment == 0) {
            $payment->date = Carbon::now()->format('Y.m.d');
        }
        $payment->penalty = $payment['penalty'];
        $payment->cash = $cash;
        $payment->amount = 0;
        $payment->interest_payment = 0;
        $payment->principal_payment = 0;
        $payment->remaining  = 0;
        $payment->status = $payment->mother - $payment->amount == 0 ? 'completed' : 'initial';

        if ($payer) {
            $payment->another_payer = true;
            $payment->name = $payer['name'];
            $payment->surname = $payer['surname'];
            $payment->phone = $payer['phone'];
        }

        $payment->save();
        $history['payment_changes'][] = [
            'payment_id' => $payment->id,
            'old_amount' => $oldAmount,
            'new_amount' => $payment->amount,
            'old_paid' => $oldPaid,
            'new_paid' => $payment->paid,
            'old_date' => $oldDate,
            'old_mother' => $payment->mother,
            'old_principal' => $principal_payment,
            'old_interest' => $interest_payment,
            'updated_at' => now()->toDateTimeString()
        ];

        DealAction::create([
            'deal_id' => $deal_id,
            'actionable_id' => $payment->id,
            'actionable_type' => Payment::class,
            'amount' => $oldAmount,
            'type' => 'regular',
            'description' => 'Regular payment',
            'date' => Carbon::now()->format('Y-m-d'),
            'history' => $history
        ]);
    }

    private function partiallyCompletePayment($payment, $paid, $deal_id = null, $history = [],$principal_payment = null,$interest_payment = null): void
    {
        $oldPaid = $payment->paid;
        $oldAmount = $payment->amount;
        $oldDate = $payment->date;
        $payment->amount -= $paid;
        $payment->paid += $paid;
        if ($payment->last_payment && $payment->amount == 0) {
            $payment->mother -= $payment->paid;
        }
        $payment->save();
        $history['payment_changes'][] = [
            'payment_id' => $payment->id,
            'old_amount' => $oldAmount,
            'new_amount' => $payment->amount,
            'old_paid' => $oldPaid,
            'new_paid' => $payment->paid,
            'old_date' => $oldDate,
            'old_principal' => $principal_payment,
            'old_interest' => $interest_payment,
            'updated_at' => now()->toDateTimeString()
        ];
        DealAction::create([
            'deal_id' => $deal_id,
            'actionable_id' => $payment->id,
            'actionable_type' => Payment::class,
            'amount' => $paid,
            'type' => 'regular',
            'description' => 'Regular payment',
            'date' => Carbon::now()->format('Y-m-d'),
            'history' => $history
        ]);
    }

    private function handleRemainingAmount($contract, $amount, $cash, $payment_id, $deal_id = null)
    {
        $nextPayment = Payment::where('contract_id', $contract->id)->where('status', 'initial')
            ->where('id', '!=', $payment_id)
            ->orderBy('date', 'asc')->orderBy('id', 'asc')
            ->first();
        $decrease = null;
        $oldAmount = null;
        $oldDate = null;
        $oldPaid = null;
        if ($nextPayment  && $contract->payment_type == 'classic') {
            $decrease = $amount % 1000;
            $amount -= $decrease;
            $oldAmount = $nextPayment->amount;
            $oldDate = $nextPayment->date;
            $oldPaid = $nextPayment->paid;
            $oldInterest = $nextPayment->interest_payment;
            $oldPrincipal = $nextPayment->principal_payment;
        }
        if ($nextPayment && $decrease > 0) {
            $nextPayment->amount -= $decrease;
            $nextPayment->paid += $decrease;
            $nextPayment->save();
            $history['payment_changes'][] = [
                'payment_id' => $nextPayment->id,
                'old_amount' => $oldAmount,
                'new_amount' => $nextPayment->amount,
                'old_paid' => $oldPaid,
                'new_paid' => $nextPayment->paid,
                'old_date' => $oldDate,
                'old_interest' => $oldInterest,
                'old_principal' => $oldPrincipal,
                'updated_at' => now()->toDateTimeString()
            ];
            DealAction::create([
                'deal_id' => $deal_id,
                'actionable_id' => $nextPayment->id,
                'actionable_type' => Payment::class,
                'amount' => $decrease,
                'type' => 'regular',
                'description' => 'Regular payment',
                'date' => Carbon::now()->format('Y-m-d'),
                'history' => $history
            ]);
            //$contract->collected += $decrease;

        }
        if ($amount > 10) {
            $this->payPartial($contract, $amount, false, $cash, $deal_id,null,false,true);
        }
        return $decrease;


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
        $payment->paid = $amount;
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
        $now = Carbon::now();
        $history = ['payment_changes' => []];

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
                        'effective_date' => now()->toDateString(),
                    ]);

                    $contract->left = max(0, $contract->left - $partialAmount);
//                    $contract->collected += $partialAmount;
                    $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
                    $contract->save();
                    $this->contractService->createPayment($contract, $targetDate, null, $remainingMonths);
                }
            } else {
                $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
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
                    'effective_date' => now()->toDateString(),
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
                'effective_date' => now()->toDateString(),
            ]);
            $contract->left = max(0, $contract->left - $partialAmount);
//            $contract->collected += $partialAmount;
            $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
            $contract->save();
        }

        $this->recordContractHistory($contract, $partialAmount, $deal_id);
        $this->handleAccountingForPartial($contract, $partialAmount, $date,$deal_id);

        if ($contract->payment_type == 'classic') {
            return $this->createPayment($contract->id, $partialAmount, 'partial', $payer, $cash, $history, $deal_id, $date);
        }

        return null;
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

            $reduction = min($remainingPartial, (float) $payment->principal_payment);
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
            $payment->paid += $reduction;
            $payment->principal_payment -= $reduction;
            $remainingPartial -= $reduction;
            $payment->save();
            $changes[] = $oldData;
        }

        if (!empty($changes)) {
            $remainingInitialPayments = Payment::where('contract_id', $contract->id)
                ->where('type', 'regular')
                ->where('status', 'initial')
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->get();
            // Single save point: recalculates interest on the updated principals and persists
            // all modified fields (paid, principal_payment, interest_payment, amount) atomically.
            $this->recalculateAmortizedInterestFromSchedule($contract, $remainingInitialPayments);
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
    protected function recalculateAmortizedInterestFromSchedule(Contract $contract, $payments): void
    {
        $payments = $payments->where('status','initial')->sortBy(fn ($p) => [$p->date, $p->id ?? 0])->values();
        $balance = (float) $contract->provided_amount;
        $rate = (float) $contract->interest_rate;
        $prevDate = Carbon::parse($contract->date);
        foreach ($payments as $payment) {
            $paymentDate = Carbon::parse($payment->to_date);
            $fromDate = Carbon::parse($payment->from_date);
            $days = max(1, $paymentDate->diffInDays($fromDate));

            $prevDate = $paymentDate;

            $interest = $balance * $days * $rate / 100;

            $payment->interest_payment = $interest;

            $principal = (float) $payment->principal_payment;
            $fee = (float) ($payment->service_fee_payment ?? 0);
            $paid = (float) ($payment->paid ?? 0);
            $payment->amount = max(0, $principal + $interest);

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

//    protected function processClassicPayments($payments, $contract, $partialAmount, $now)
//    {
//        $changes = [];
//        $startedToChange = false;
//
//        foreach ($payments as $index => $payment) {
//            if ($payment->amount <= 0) continue;
//
//            $dateToCheck = Carbon::parse($payment->date);
//            if ($dateToCheck->gt($now)) {
//                $oldPaid = $payment->paid;
//                $oldAmount = $payment->amount;
//                $oldDate = $payment->date;
//                if ($startedToChange) {
//                    $coeff = ($contract->left - $partialAmount) / $contract->left;
//                    $newAmount = intval(ceil($oldAmount * $coeff / 10) * 10);
//                } else {
//                    $startedToChange = true;
//                    $prevDate = $index === 0 ? $contract->date : $payments[$index - 1]->date;
//                    $daysLeft = $payment->days - $now->diffInDays(Carbon::parse($prevDate));
//
//                    $newAmount = $oldAmount
//                        - $this->calcAmount($contract->left, $daysLeft, $contract->interest_rate)
//                        + $this->calcAmount($contract->left - $partialAmount, $daysLeft, $contract->interest_rate);
//                }
//
//                $payment->amount = max(0, $newAmount);
//                $payment->save();
//
//                $changes[] = [
//                    'payment_id' => $payment->id,
//                    'old_amount' => $oldAmount,
//                    'new_amount' => $payment->amount,
//                    'old_paid' =>  $oldPaid,
//                    'old_date' => $oldDate,
//                    'updated_at' => $now->toDateTimeString()
//                ];
//            }
//
//            if ($payment->last_payment) {
//                $this->updateLastPayment($payment, $contract->left - $partialAmount, $changes);
//            }
//        }
//        return $changes;
//    }
    protected function processClassicPayments($payments, $contract, $partialAmount, $now)
    {
        $history = [
            'payments' => [],
            'mother_amount' => null
        ];
        $startedToChange = false;

        foreach ($payments as $index => $payment) {
            if ($payment->amount <= 0) continue;

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
    protected function recordContractHistory($contract, $amount, $deal_id)
    {
        ContractAmountHistory::create([
            'contract_id' => $contract->id,
            'amount' => $amount,
            'amount_type' => 'provided_amount',
            'type' => 'out',
            'date' => now()->toDateTimeString(),
            'deal_id' => $deal_id,
            'category_id' => $contract->category_id,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
        ]);
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

    private function handleAccountingForPartial($contract, $partialAmount, $date,$deal_id=null)
    {
        $diamondId = Client::where('company_name', 'Diamond Credit')->first()->id ?? 1;
        $clientId = $contract->client_id;
        $date = $date ?? Carbon::now()->format('Y-m-d');
        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();

        $ruleMother = PostingRule::where('business_event_filter', 'pay_mother_amount')->first();
        if ($ruleMother && $partialAmount > 0) {
            $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
            $journalDoc = DocumentJournal::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => DocumentJournal::PAY_MOTHER_AMOUNT,
                'amount_amd' => $partialAmount,
                'credit_partner_id' => $clientId,
                'comment' => 'mother_amount_payment',
                'debit_account_id' => $ruleMother->debit_account_id,
                'credit_account_id' => $ruleMother->credit_account_id,
                'user_id' => auth()->id(),
                'journalable_type' => DocumentJournal::class,
                'journalable_id' => $journal->id,
                'deal_id' => $deal_id,
            ]);

            Transaction::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => DocumentJournal::PAY_MOTHER_AMOUNT,
                'debit_account_id' => $ruleMother->debit_account_id,
                'credit_account_id' => $ruleMother->credit_account_id,
                'credit_partner_id' => $clientId,
                'amount_amd' => $partialAmount,
                'comment' => 'mother_amount_payment',
                'user_id' => auth()->id(),
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id' => $journalDoc->id,
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
                $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
                $documentType = ($classificationName === 'standard')
                    ? DocumentJournal::PROVIDED_AMOUNT_CHANGE
                    : DocumentJournal::RESERVE_SPECIAL_AMOUNT;
                $journalDocRes = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentType,
                    'amount_amd' => $reserveAmount,
                    'debit_partner_id' => $clientId,
                    'comment' => 'reserve_payment',
                    'debit_account_id' => $ruleReserve->debit_account_id,
                    'credit_account_id' => $ruleReserve->credit_account_id,
                    'user_id' => auth()->id(),
                    'journalable_type' => DocumentJournal::class,
                    'journalable_id' => $journal->id,
                ]);

                Transaction::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => $documentType,
                    'debit_account_id' => $ruleReserve->debit_account_id,
                    'debit_partner_id' => $clientId,
                    'credit_account_id' => $ruleReserve->credit_account_id,
                    'amount_amd' => $reserveAmount,
                    'comment' => 'reserve_amount',
                    'user_id' => auth()->id(),
                    'transactionable_type' => DocumentJournal::class,
                    'transactionable_id' => $journalDocRes->id,
                ]);
            }
        }
    }
    public function processFullPayment($contract, $amount, $payer, $cash, $deal_id = null)
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

        ContractAmountHistory::create([
            'contract_id' => $contract->id,
            'amount' => $contract->provided_amount,
            'amount_type' => 'provided_amount',
            'type' => 'out',
            'date' => now()->toDateTimeString(),
            'deal_id' => $deal_id,
            'category_id' => $contract->category_id,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
        ]);
        ContractAmountHistory::create([
            'contract_id' => $contract->id,
            'amount' => $contract->estimated_amount,
            'amount_type' => 'estimated_amount',
            'type' => 'out',
            'date' => now()->toDateTimeString(),
            'deal_id' => $deal_id,
            'category_id' => $contract->category_id,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
        ]);
        $payment = $this->createPayment($contract->id, $amount, 'full', $payer, $cash, $history, $deal_id);

        $contract->status = 'completed';
        $contract->left = 0;
        $contract->collected += $interestAmount;
        $contract->provided_amount = 0;
        $contract->save();

        $nowDate = now()->toDateString();

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
        return $payment;
    }
}
