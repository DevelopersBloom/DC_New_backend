<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\DealAction;
use App\Models\DocumentJournal;
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
//    public function processPayments($contract, $amount, $payer, $cash, $payments, $deal_id)
//    {
//        $payments_sum = 0;
//        $interest_amount = 0;
//        $principal_amount = 0;
//        $effective_amount = 0;
//        $initial_amount = $amount;
//        foreach ($payments as $item) {
//            $payments_sum += $item['amount'] + $item['mother'];
//        }
//        $result = $this->countPenalty($contract->id);
//        $penalty = $result['penalty_amount'];
//        $delay_days = $result['delay_days'];
//        $parent_id = $result['parent_id'];
//        $payed_penalty = 0;
//        $discount = 0;
//        // Process penalty
//        if ($penalty) {
//            $amount = $this->processPenalty($contract->id, $amount, $penalty, $payer, $cash, $deal_id, $parent_id)['amount'];
//            $payed_penalty = $initial_amount - $amount;
//        }
//        // Process payments
//        if ($amount > 0) {
//            foreach ($payments as $payment) {
//                $result = $this->processSinglePayment($contract, $payment, $amount, $payer, $cash, $deal_id);
//                $amount = $result['amount'];
//                $interest_amount += $result['interest_amount'];
//                $principal_amount += $result['principal_amount'];
//                //$effective_amount += $result['effective_amount'];
//            }
//            // Handle any remaining amount
//            if ($amount > 0) {
//                $decrease = $this->handleRemainingAmount($contract, $amount, $cash, $payment->id, $deal_id);
//                $interest_amount += $decrease;
//            }
//
//        }
//        return [
//            'id' => $payment->id ?? null,
//            'payments_sum' => $payments_sum,
//            'interest_amount' => $interest_amount,
//            'principal_amount' => $principal_amount,
//            'delay_days' => $delay_days,
//            'penalty' => $payed_penalty,
//            'discount' => $discount,
//        ];
//    }
    public function processPayments($contract, $amount, $payer, $cash, $payments, $deal_id)
    {
        $payments_sum = 0;
        $interest_amount = 0;
        $principal_amount = 0;
        $initial_amount = $amount;

        $result_penalty = $this->countPenalty($contract->id);
        $penalty = $result_penalty['penalty_amount'];
        $delay_days = $result_penalty['delay_days'];
        $parent_id = $result_penalty['parent_id'];
        $payed_penalty = 0;

        if ($penalty > 0) {
            $penaltyResult = $this->processPenalty($contract->id, $amount, $penalty, $payer, $cash, $deal_id, $parent_id);
            $payed_penalty = $penaltyResult['penalty'];
            $amount = $penaltyResult['amount'];
        }

        if ($amount > 0) {
            foreach ($payments as $payment) {
                $result = $this->processSinglePayment($contract, $payment, $amount, $payer, $cash, $deal_id);
                $amount = $result['amount'];
                $interest_amount += $result['interest_amount'];
                $principal_amount += $result['principal_amount'];

            }

            if ($amount > 0) {
                $this->handleRemainingAmount($contract, $amount, $cash, $payments->last()->id, $deal_id);
                $principal_amount += $amount;
                $amount = 0;
            }

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
    private function processSinglePayment1($contract, $payment, $amount, $payer, $cash, $deal_id)
    {
        $paymentFinal = ($payment['amount'] + $payment['penalty']);
        if ($amount >= $paymentFinal) {
            $this->completePayment($payment, $payer, $cash, $contract->id, $deal_id);
            $contract->collected += $paymentFinal;

            if ($contract->payment_type == 'amortized') {
                $contract->left = max(0, $contract->left - $payment->principal_payment);
                $contract->provided_amount = max(0, $contract->provided_amount - $payment->principal_payment);

            }

            $contract->save();
            return ['interest_amount' => $paymentFinal,
                'amount' => $amount - $paymentFinal];
        } else {
            $this->partiallyCompletePayment($payment, $amount, $deal_id);
            $contract->collected += $amount;


            $contract->save();

            return ['interest_amount' => $amount,
                'amount' => 0];
        }
    }
//    private function processSinglePayment($contract, $payment, $amount, $payer, $cash, $deal_id)
//    {
//        $paymentFinal = ($payment['amount'] + $payment['penalty']);
//
//        if ($amount >= $paymentFinal) {
//            $interest_amount = $payment->amount;
//
//            if ($contract->payment_type == 'amortized') {
//                $contract->left = max(0, $contract->left - $payment->principal_payment);
//                $contract->provided_amount = max(0, $contract->provided_amount - $payment->principal_payment);
//                $paidDeal = DealAction::where('actionable_type', Payment::class)
//                    ->where('actionable_id', $payment->id)
//                    ->orderBy('id', 'desc')
//                    ->first();
//                $paidAmount = data_get($paidDeal, 'history.payment_changes.0.new_paid', 0);
////                $remainingInterest = $payment->interest_payment - $paidAmount - $amount;
//                $remainingInterest = $payment->interest_payment - $paidAmount;
//                $isInterestPaid = $remainingInterest <= 0;
//
//                if ($isInterestPaid) {
//                    $interest_amount = 0;
//                    $principal_amount = min($amount,$payment->principal_payment);
//                } else {
//                    $interest_amount = min($amount, $remainingInterest);
//                    $principal_amount = min($amount-$interest_amount, $payment->principal_payment);
//                }
//            }
//
//            $this->completePayment($payment, $payer, $cash, $contract->id, $deal_id);
//            $contract->collected += $paymentFinal;
//            $contract->save();
//            return ['interest_amount' => $interest_amount,
//                'amount' => $amount - $paymentFinal];
//        } else {
//
//
//            $paidDeal = DealAction::where('actionable_type', Payment::class)
//                ->where('actionable_id', $payment->id)
//                ->orderBy('id', 'desc')
//                ->first();
//            $paidAmount = data_get($paidDeal, 'history.payment_changes.0.new_paid', 0);
//
////            $remainingInterest = $payment->interest_payment - $paidAmount - $amount;
//            $remainingInterest = $payment->interest_payment - $paidAmount;
//            $isInterestPaid = $remainingInterest <= 0;
//            if ($contract->payment_type == 'amortized') {
//                if ($isInterestPaid) {
//                    $interest_amount = 0;
//                } else {
//                    $interest_amount = min($amount, $remainingInterest);
//                }
//            } else {
//                $interest_amount = $amount;
//            }
//            $this->partiallyCompletePayment($payment, $amount, $deal_id);
//            $contract->collected += $amount;
//            $contract->save();
//
//
//            return ['interest_amount' => $interest_amount,
//                'amount' => 0];
//        }
//    }

    private function processSinglePayment($contract, $payment, $amount, $payer, $cash, $deal_id,)
    {
        $penalty = $payment->penalty ?? 0;
        $remainingAmount = $amount;

        $paidPenalty = min($remainingAmount, $penalty);
        $remainingAmount -= $paidPenalty;

        $paidInterest = 0;
        $paidPrincipal = 0;

//        if ($contract->payment_type == 'amortized') {
//            $paidDeal = DealAction::where('actionable_type', Payment::class)
//                ->where('actionable_id', $payment->id)
//                ->orderBy('id', 'desc')
//                ->first();
//            $alreadyPaidTotal = data_get($paidDeal, 'history.payment_changes.0.new_paid', 0);
//
//            $remainingInterestPlan = max(0, ($payment->interest_payment ?? 0) - $alreadyPaidTotal);
//
//            $paidInterest = min($remainingAmount, $remainingInterestPlan);
//            $remainingAmount -= $paidInterest;
//
//            $paidPrincipal = min($remainingAmount, $payment->principal_payment ?? 0);
//            $remainingAmount -= $paidPrincipal;
//
//            $contract->left = max(0, $contract->left - $paidPrincipal);
//            $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipal);
//
//        }
           if ($contract->payment_type == 'amortized') {

                   $remainingInterestPlan = $payment->interest_payment;

                   $paidInterest = min($remainingAmount, $remainingInterestPlan);
                   $remainingAmount -= $paidInterest;

                   $paidPrincipal = min($remainingAmount, $payment->principal_payment ?? 0);
                   $remainingAmount -= $paidPrincipal;

                   $contract->left = max(0, $contract->left - $paidPrincipal);
                   $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipal);
                   $payment->principal_payment -= $paidPrincipal;
                   $payment->interest_payment -= $paidInterest;

            } else {
                $paidInterest = min($remainingAmount, $payment->amount);
                $remainingAmount -= $paidInterest;
                $paidPrincipal = 0;
            }

        $totalRequiredForThisLine = $payment->amount + $penalty;
        if ($amount >= $totalRequiredForThisLine) {
            $this->completePayment($payment, $payer, $cash, $contract->id, $deal_id);
        } else {
            $this->partiallyCompletePayment($payment, $amount, $deal_id);
        }

        $contract->collected += $amount;
        $contract->save();

        return [
            'interest_amount'  => $paidInterest,
            'principal_amount' => $paidPrincipal,
            'penalty'          => $paidPenalty,
            'amount'           => $remainingAmount
        ];
    }
    private function completePayment($payment, $payer, $cash, $contract_id, $deal_id = null): void
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

    private function partiallyCompletePayment($payment, $paid, $deal_id = null, $history = []): void
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
            ->where('id', '!=', $payment_id)->first();
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
        }
        if ($nextPayment && $decrease > 0) {
            $nextPayment->amount -= $decrease;
            $nextPayment->paid += $decrease;
            $nextPayment->save();
            $history['payment_changes'][] = [
                'payment_id' => $nextPayment->id,
                'old_amount' => $oldAmount,
                'new_amount' => $nextPayment->amout,
                'old_paid' => $oldPaid,
                'new_paid' => $nextPayment->paid,
                'old_date' => $oldDate,
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
        if ($amount > 0) {
            $this->payPartial($contract, $amount, false, $cash, $deal_id);
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

//    public function payPartial($contract, $partialAmount, $payer, $cash, $deal_id = null, $date = null)
//    {
//        $now = Carbon::now();
//        $payments = Payment::where('contract_id', $contract->id)->where('type', 'regular')->get();
//        $startedToChange = false;
//        $daysToCalc = 0;
//        $history = [];
//        foreach ($payments as $index => $payment) {
//            $dateToCheck = Carbon::createFromFormat('Y-m-d', $payment->date);
//
//            //$dateToCheck = Carbon::createFromFormat('Y-m-d', $payment->date);
//            if ($dateToCheck->gt($now)) {
//                if ($startedToChange) {
//                    $coeff = ($contract->left - $partialAmount) / $contract->left;
//                    $oldAmount = $payment->amount;
//                    $amount = intval(ceil($payment->amount * $coeff / 10) * 10);
//                    $payment->amount = $amount;
//
//                    $history['payment_changes'][] = [
//                        'payment_id' => $payment->id,
//                        'old_amount' => $oldAmount,
//                        'new_amount' => $amount,
//                        'old_paid' => $payment->paid,
//                        'new_paid' => $payment->paid,
//                        'old_date' => $payment->date,
//                        'updated_at' => now()->toDateTimeString()
//                    ];
//                } else {
//                    $startedToChange = true;
//
//                    if ($index === 0) {
//                        $daysToCalc = $now->diffInDays(Carbon::parse($contract->date));
//                    } else {
//                        $daysToCalc = $now->diffInDays(Carbon::parse($payments[$index - 1]->date));
//                    }
//
//                    $daysLeft = $payment->days - $daysToCalc;
//                    $sum = $payment->amount;
//                    $sum -= $this->calcAmount($contract->left, $daysLeft, $contract->interest_rate);
//                    $sum += $this->calcAmount($contract->left - $partialAmount, $daysLeft, $contract->interest_rate);
//                    $history['payment_changes'][] = [
//                        'payment_id' => $payment->id,
//                        'old_amount' => $payment->amount,
//                        'new_amount' => $sum,
//                        'old_paid' => $payment->paid,
//                        'new_paid' => $payment->paid,
//                        'old_date' => $payment->date,
//                        'updated_at' => now()->toDateTimeString()
//                    ];
//                    $payment->amount = $sum;
//                }
//                $payment->save();
//            }
//
//            if ($payment->last_payment) {
//
//                $history['mother_amount'] = [
//                    'payment_id' => $payment->id,
//                    'old_mother' => $payment->mother,
//                    'new_mother' => $contract->left - $partialAmount,
//                ];
//                $payment->mother = $contract->left - $partialAmount;
//                $payment->save();
//            }
//        }
//        $history['contract_changes'] = [
//            'contract_id' => $contract->id,
//            'old_left' => $contract->left,
//            'new_left' => $contract->left - $partialAmount,
//            'old_collected' => $contract->collected,
//            'new_collected' => $contract->collected + $partialAmount,
//            'old_estimated' => $contract->estimated_amount,
//            'old_provided' => $contract->provided_amount,
//            'new_provided' => max(0, $contract->provided_amount - $partialAmount),
//            'updated_at' => now()->toDateTimeString()
//        ];
//        ContractAmountHistory::create([
//            'contract_id' => $contract->id,
//            'amount' => $partialAmount,
//            'amount_type' => 'provided_amount',
//            'type' => 'out',
//            'date' => now()->toDateTimeString(),
//            'deal_id' => $deal_id,
//            'category_id' => $contract->category_id,
//            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
//        ]);
//
//        // Update contract with partial payment
//        $contract->left = max(0, $contract->left - $partialAmount);
//        $contract->collected += $partialAmount;
//        $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
//        $contract->save();
//        $pawnshop = auth()->user()->pawnshop ?? Pawnshop::where('id', 1)->first();
//        $pawnshop->given -= $partialAmount;
//        $pawnshop->save();
//        // Create the partial payment record
////        if ($isActionable) {
////            return $this->createPayment($contract->id, $partialAmount, 'partial', $payer, $cash,$history, $deal_id);
////        }
//
//
//        $ruleMotherAmount = PostingRule::where('business_event_filter', 'pay_mother_amount')
//            ->first();
//
//        if (!$ruleMotherAmount) {
//            throw new \RuntimeException('Posting rule for pay_mother_amount not found');
//        }
//
//        $debitMother = $ruleMotherAmount->debit_account_id;
//        $creditMother = $ruleMotherAmount->credit_account_id;
//
//        $ruleProvideAmountChange = PostingRule::where('business_event_filter', 'provide_general_amount_change')
//            ->first();
//
//        if (!$ruleProvideAmountChange) {
//            throw new \RuntimeException('Posting rule for provide_general_amount_change not found');
//        }
//
//        $debitAmountChange = $ruleProvideAmountChange->debit_account_id;
//        $creditAmountChange = $ruleProvideAmountChange->credit_account_id;
//
//        $diamondId = Client::where('company_name', 'Diamond Credit')->first()->id ?? 1;
//        $clientId = $contract->client_id;
//
//        $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
//
//        $document_type = DocumentJournal::PAY_MOTHER_AMOUNT;
//        $date = Carbon::now()->format('Y-m-d');
//        $journal = DocumentJournal::where('journalable_type', Contract::class)
//            ->where('journalable_id', $contract->id)
//            ->first();
//        $journalDoc = DocumentJournal::create([
//            'date' => $date,
//            'document_number' => $nextDocNum,
//            'document_type' => $document_type,
//            'amount_amd' => $partialAmount,
//            'partner_id' => $diamondId,
//            'credit_partner_id' => $clientId,
//            'comment' => 'mother_amount_payment',
//            'debit_account_id' => $debitMother,
//            'credit_account_id' => $creditMother,
//            'user_id' => auth()->id(),
//            'journalable_type' => DocumentJournal::class,
//            'journalable_id' => $journal->id,
//        ]);
//        Transaction::create([
//            'date' => $date,
//            'document_number' => $nextDocNum,
//            'document_type' => $document_type,
//
//            'debit_account_id' => $debitMother,
//            'debit_partner_id' => $diamondId,
//            'debit_currency_id' => 1,
//
//            'credit_account_id' => $creditMother,
//            'credit_currency_id' => 1,
//            'credit_partner_id' => $clientId,
//
//            'amount_amd' => $partialAmount,
//
//            'comment' => 'mother_amount_payment',
//            'user_id' => auth()->id(),
//            'is_system' => false,
//
//            'disbursement_date' => $date,
//            'transactionable_type' => DocumentJournal::class,
//            'transactionable_id' => $journalDoc->id,
//        ]);
//
//        $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
//        $document_type_provided = DocumentJournal::PROVIDED_AMOUNT_CHANGE;
//        $reservePercent = $contract->client->classification->reserve_percent ?? 0;
//        $reserveAmount = $partialAmount * $reservePercent / 100;
//
//        $journalDoc = DocumentJournal::create([
//            'date' => $date,
//            'document_number' => $nextDocNum,
//            'document_type' => $document_type_provided,
//            'amount_amd' => $reserveAmount,
//            'partner_id' => $clientId,
//            'credit_partner_id' => $diamondId,
//            'comment' => 'reserve_payment',
//            'debit_account_id' => $debitAmountChange,
//            'credit_account_id' => $creditAmountChange,
//            'user_id' => auth()->id(),
//            'journalable_type' => DocumentJournal::class,
//            'journalable_id' => $journal->id,
//        ]);
//
//        Transaction::create([
//            'date' => $date,
//            'document_number' => $nextDocNum,
//            'document_type' => $document_type_provided,
//
//            'debit_account_id' => $debitAmountChange,
//            'debit_partner_id' => $diamondId,
//            'debit_currency_id' => 1,
//
//            'credit_account_id' => $creditAmountChange,
//            'credit_currency_id' => 1,
//            'credit_partner_id' => $clientId,
//
//            'amount_amd' => $reserveAmount,
//
//            'comment' => 'reserve_amount',
//            'user_id' => auth()->id(),
//            'is_system' => false,
//
//            'disbursement_date' => $date,
//            'transactionable_type' => DocumentJournal::class,
//            'transactionable_id' => $journalDoc->id,
//        ]);
//
//        return $this->createPayment($contract->id, $partialAmount, 'partial', $payer, $cash, $history, $deal_id, $date);
//
//    }
    public function payPartial($contract, $partialAmount, $payer, $cash, $deal_id = null, $date = null,$is_recount = false)
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
                $remainingMonths = Payment::where('contract_id', $contract->id)
                    ->where('type', 'regular')
                    ->where('status', 'initial')
                    ->count();

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
                    ];

                    $contract->left = max(0, $contract->left - $partialAmount);
                    $contract->collected += $partialAmount;
                    $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
                    $contract->save();
                    $this->contractService->createPayment($contract, $targetDate, null, $remainingMonths);
                }
            } else {
                $history['payment_changes'] = $this->processAmortizedPayments($payments, $partialAmount, $now);
                $history['contract_changes'] = [
                    'old_left' => $contract->left,
                    'new_left' => $contract->left - $partialAmount,
                    'old_provided' => $contract->provided_amount,
                    'new_provided' => max(0, $contract->provided_amount - $partialAmount),
                ];

                $contract->left = max(0, $contract->left - $partialAmount);
                $contract->collected += $partialAmount;
                $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
                $contract->save();
            }
        } else {
            $history['payment_changes'] = $this->processClassicPayments($payments, $contract, $partialAmount, $now);
            $history['contract_changes'] = [
                'old_left' => $contract->left,
                'new_left' => $contract->left - $partialAmount,
                'old_provided' => $contract->provided_amount,
                'new_provided' => max(0, $contract->provided_amount - $partialAmount),
            ];

            $contract->left = max(0, $contract->left - $partialAmount);
            $contract->collected += $partialAmount;
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

    protected function processAmortizedPayments($payments, $remainingPartial, $now)
    {
        $changes = [];
        foreach ($payments as $payment) {
            if ($remainingPartial <= 0) break;

            $reduction = min($remainingPartial, $payment->principal_payment);
            if ($reduction <= 0) continue;

            $oldData = [
                'payment_id' => $payment->id,
                'old_amount' => $payment->amount,
                'old_paid' => $payment->paid,
                'old_principal' => $payment->principal_payment,
            ];

            $payment->amount -= $reduction;
            $payment->paid += $reduction;
            $payment->principal_payment -= $reduction;
            if ($payment->amount <= 0) $payment->status = 'completed';

            $payment->save();
            $remainingPartial -= $reduction;

            $changes[] = array_merge($oldData, [
                'new_amount' => $payment->amount,
                'new_paid' => $payment->paid,
                'new_principal' => $payment->principal_payment,
                'reduction' => $reduction,
                'updated_at' => $now->toDateTimeString()
            ]);
        }
        return $changes;
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
        if ($ruleMother) {
            $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
            $journalDoc = DocumentJournal::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => DocumentJournal::PAY_MOTHER_AMOUNT,
                'amount_amd' => $partialAmount,
                'partner_id' => $diamondId,
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
                'debit_partner_id' => $diamondId,
                'credit_account_id' => $ruleMother->credit_account_id,
                'credit_partner_id' => $clientId,
                'amount_amd' => $partialAmount,
                'comment' => 'mother_amount_payment',
                'user_id' => auth()->id(),
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id' => $journalDoc->id,
            ]);
        }

        $ruleReserve = PostingRule::where('business_event_filter', 'provide_general_amount_change')->first();
        if ($ruleReserve) {
            $reservePercent = $contract->client->classification->reserve_percent ?? 0;
            $reserveAmount = $partialAmount * $reservePercent / 100;

            if ($reserveAmount > 0) {
                $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
                $journalDocRes = DocumentJournal::create([
                    'date' => $date,
                    'document_number' => $nextDocNum,
                    'document_type' => DocumentJournal::PROVIDED_AMOUNT_CHANGE,
                    'amount_amd' => $reserveAmount,
                    'partner_id' => $clientId,
                    'credit_partner_id' => $diamondId,
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
                    'document_type' => DocumentJournal::PROVIDED_AMOUNT_CHANGE,
                    'debit_account_id' => $ruleReserve->debit_account_id,
                    'debit_partner_id' => $diamondId,
                    'credit_account_id' => $ruleReserve->credit_account_id,
                    'credit_partner_id' => $clientId,
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
        $result = $this->countPenalty($contract->id);
        $penalty = $result['penalty_amount'];
        $delayDays = $result['delay_days'];
        $interestAmount = $this->calculateCurrentPayment($contract)['current_amount'];
        $lastPayment = Payment::where('contract_id', $contract->id)
            ->where('last_payment', 1)->first();
        $oldMother = $lastPayment->mother;
        $lastPayment->mother = 0;
        $lastPayment->save();
        Payment::where('contract_id', $contract->id)
            ->where('status', 'initial')->delete();


        $history['payment_changes'][] = [
            'payment_id' => $lastPayment->id,
            'old_paid' => $lastPayment->paid,
            'old_date' => $lastPayment->date,
            'old_amount' => $lastPayment->amount,
            'old_mother' => $oldMother
        ];

        $history['contract_changes'] = [
            'contract_id' => $contract->id,
            'old_left' => $contract->left,
            'new_left' => 0,
            'old_collected' => $contract->collected,
            'new_collected' => $contract->collected + $amount,
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
//        $history['payment_changes'] = [
//            'payment_id' => $last_payment->id,
//            'old_mother' => $last_payment->mother,
//            'new_mother' => 0,
//        ];
        // process full payment
        $payment = $this->createPayment($contract->id, $amount, 'full', $payer, $cash, $history, $deal_id);

        $contract->status = 'completed';
        $contract->left = 0;
        $contract->collected += $amount;
        $contract->provided_amount = 0;
        $contract->save();
    }
}
