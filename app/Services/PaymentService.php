<?php

namespace App\Services;

use App\Models\ContractAmountHistory;
use App\Models\DealAction;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\User;
use App\Traits\ContractTrait;
use Illuminate\Support\Carbon;

class PaymentService {
    use ContractTrait;
    public function processPayments($contract, $amount, $payer, $cash, $payments,$deal_id) {
        $payments_sum = 0;
        $interest_amount = 0;
        $initial_amount = $amount;
        foreach ($payments as $item) {
            $payments_sum += $item['amount'] + $item['mother'];
        }
        $result = $this->countPenalty($contract->id);
        $penalty = $result['penalty_amount'];
        $delay_days = $result['delay_days'];
        $payed_penalty = 0;
        $discount = 0;
        // Process penalty
        if ($penalty) {
            $amount = $this->processPenalty($contract->id, $amount, $penalty, $payer, $cash,$deal_id)['amount'];
            $payed_penalty = $initial_amount - $amount;
        }
        // Process payments
        if ($amount > 0) {
            foreach ($payments as $payment) {
                $result = $this->processSinglePayment($contract, $payment, $amount, $payer, $cash,$deal_id);
                $amount = $result['amount'];
                $interest_amount += $result['interest_amount'];

            }
            // Handle any remaining amount
            if ($amount> 0 ) {
                $decrease = $this->handleRemainingAmount($contract, $amount, $cash,$payment->id,$deal_id);
                $interest_amount+=$decrease;
            }

        }
        return [
            'id'              => $payment->id ?? null,
            'payments_sum'    => $payments_sum,
            'interest_amount' => $interest_amount,
            'delay_days'      => $delay_days,
            'penalty'         => $payed_penalty,
            'discount'        => $discount,
        ];
    }

    public function processPenalty($contractId, $amount, $penalty, $payer, $cash,$deal_id=null) {
        if ($amount <= $penalty) {
            $paymentId = $this->createPayment($contractId, $amount, 'penalty', $payer, $cash,[],$deal_id);
            //return 0;
            return [
                'penalty' => $amount,
                'amount'  => 0,
                'payment_id' => $paymentId
            ];
        } else {
            $paymentId = $this->createPayment($contractId, $penalty, 'penalty', $payer, $cash,[],$deal_id);
          //  return $amount - $penalty;
            return [
                'penalty' => $penalty,
                'amount'  => $amount - $penalty,
                'payment_id' => $paymentId
            ];
        }
    }

    private function processSinglePayment($contract, $payment, $amount, $payer, $cash,$deal_id) {
        $paymentFinal = ($payment['amount'] + $payment['penalty']);
        if ($amount >= $paymentFinal) {
            $this->completePayment($payment,$payer, $cash,$contract->id,$deal_id);
            $contract->collected += $paymentFinal;
            return ['interest_amount' => $paymentFinal,
                    'amount' => $amount - $paymentFinal];
        } else {
            $this->partiallyCompletePayment($payment, $amount,$deal_id);
            $contract->collected += $amount;
            return ['interest_amount' => $amount,
                    'amount' => 0];
        }
    }

    private function completePayment($payment, $payer, $cash,$contract_id,$deal_id=null): void
    {
        $oldAmount = $payment['amount'];
        $oldPaid = $payment['paid'];
        $oldDate = $payment['date'];
        $payment->paid += $payment['amount'] + $payment['penalty'];
        //$payment->paid_date = Carbon::now()->format('Y.m.d');
        $payment->date = Carbon::now()->format('Y.m.d');
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
            'old_paid'       => $oldPaid,
            'new_paid'   => $payment->paid,
            'old_date'   => $oldDate,
            'updated_at' => now()->toDateTimeString()
        ];
        DealAction::create([
            'deal_id'        => $deal_id,
            'actionable_id'  => $payment->id,
            'actionable_type'=> Payment::class,
            'amount'         => $oldAmount,
            'type'           => 'regular',
            'description'    => 'Regular payment',
            'date'           => Carbon::now()->format('Y-m-d'),
            'history'        => $history
        ]);
    }

    private function partiallyCompletePayment($payment, $paid,$deal_id=null,$history=[]): void
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
            'old_paid'   => $oldPaid,
            'new_paid'   => $payment->paid,
            'old_date'   => $oldDate,
            'updated_at' => now()->toDateTimeString()
        ];
        DealAction::create([
            'deal_id'        => $deal_id,
            'actionable_id'  => $payment->id,
            'actionable_type'=> Payment::class,
            'amount'         => $paid,
            'type'           => 'regular',
            'description'    => 'Regular payment',
            'date'           => Carbon::now()->format('Y-m-d'),
            'history'        => $history
        ]);
    }

    private function handleRemainingAmount($contract, $amount, $cash,$payment_id,$deal_id=null)
    {
        $decrease = $amount % 1000;
        $amount -= $decrease;
        $nextPayment = Payment::where('contract_id', $contract->id)->where('status', 'initial')
                                ->where('id','!=',$payment_id)->first();
        $oldAmount = $nextPayment->amount;
        $oldDate = $nextPayment->date;
        $oldPaid = $nextPayment->paid;

        if ($nextPayment && $decrease > 0) {
            $nextPayment->amount -= $decrease;
            $nextPayment->paid += $decrease;
            $nextPayment->save();
            $history['payment_changes'][] = [
                'payment_id' => $nextPayment->id,
                'old_amount' => $oldAmount,
                'new_amount' => $nextPayment->amout,
                'old_paid'   => $oldPaid,
                'new_paid'   => $nextPayment->paid,
                'old_date'   => $oldDate,
                'updated_at' => now()->toDateTimeString()
            ];
            DealAction::create([
                'deal_id'        => $deal_id,
                'actionable_id'  => $nextPayment->id,
                'actionable_type'=> Payment::class,
                'amount'         => $decrease,
                'type'           => 'regular',
                'description'    => 'Regular payment',
                'date'           => Carbon::now()->format('Y-m-d'),
                'history'        => $history
            ]);
            //$contract->collected += $decrease;

        }
        if ($amount > 0) {
            $this->payPartial($contract, $amount, false, $cash,$deal_id);
        }
        return $decrease;


    }
    public function createPayment($contract_id, $amount, $type, $payer, $cash,$history = [],$deal_id=null,$date=null)
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

        $user = auth()->user() ?? User::where('id',1)->first();
        $payment->pawnshop_id = $user->pawnshop_id;
       // $payment->paid_date = Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d');
        $payment->date = $date ?? Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d');
        $payment->status = $status;

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

    public function payPartial($contract, $partialAmount, $payer, $cash,$deal_id=null,$date=null)
    {
        $now = Carbon::now();
        $payments = Payment::where('contract_id', $contract->id)->where('type', 'regular')->get();
        $startedToChange = false;
        $daysToCalc = 0;
        $history = [];
        foreach ($payments as $index => $payment) {
            $dateToCheck = Carbon::createFromFormat('Y-m-d', $payment->date);

            //$dateToCheck = Carbon::createFromFormat('Y-m-d', $payment->date);
            if ($dateToCheck->gt($now)) {
                if ($startedToChange) {
                    $coeff = ($contract->left - $partialAmount) / $contract->left;
                    $oldAmount = $payment->amount;
                    $amount = intval(ceil($payment->amount * $coeff / 10) * 10);
                    $payment->amount = $amount;

                    $history['payment_changes'][] = [
                        'payment_id' => $payment->id,
                        'old_amount' => $oldAmount,
                        'new_amount' => $amount,
                        'old_paid'   => $payment->paid,
                        'new_paid'   => $payment->paid,
                        'old_date'   => $payment->date,
                        'updated_at' => now()->toDateTimeString()
                    ];
                } else {
                    $startedToChange = true;

                    if ($index === 0) {
                        $daysToCalc = $now->diffInDays(Carbon::parse($contract->date));
                    } else {
                        $daysToCalc = $now->diffInDays(Carbon::parse($payments[$index - 1]->date));
                    }

                    $daysLeft = $payment->days - $daysToCalc;
                    $sum = $payment->amount;
                    $sum -= $this->calcAmount($contract->left, $daysLeft, $contract->interest_rate);
                    $sum += $this->calcAmount($contract->left - $partialAmount, $daysLeft, $contract->interest_rate);
                    $history['payment_changes'][] = [
                        'payment_id' => $payment->id,
                        'old_amount' => $payment->amount,
                        'new_amount' => $sum,
                        'old_paid'   => $payment->paid,
                        'new_paid'   => $payment->paid,
                        'old_date'   => $payment->date,
                        'updated_at' => now()->toDateTimeString()
                    ];
                    $payment->amount = $sum;
                }
                $payment->save();
            }

            if ($payment->last_payment) {

                $history['mother_amount'] = [
                    'payment_id' => $payment->id,
                    'old_mother' => $payment->mother,
                    'new_mother' => $contract->left - $partialAmount,
                ];
                $payment->mother = $contract->left - $partialAmount;
                $payment->save();
            }
        }
        $history['contract_changes'] = [
            'contract_id' => $contract->id,
            'old_left' => $contract->left,
            'new_left' => $contract->left - $partialAmount,
            'old_collected' => $contract->collected,
            'new_collected' => $contract->collected + $partialAmount,
            'old_estimated' => $contract->estimated_amount,
            'old_provided' => $contract->provided_amount,
            'new_provided' =>  max(0, $contract->provided_amount - $partialAmount),
            'updated_at' => now()->toDateTimeString()
        ];
        ContractAmountHistory::create([
            'contract_id' => $contract->id,
            'amount' => $partialAmount,
            'amount_type' => 'provided_amount',
            'type' => 'out',
            'date' => now()->toDateTimeString(),
            'deal_id' => $deal_id,
            'category_id' => $contract->category_id,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
        ]);

        // Update contract with partial payment
        $contract->left = max(0,$contract->left-$partialAmount);
        $contract->collected += $partialAmount;
        $contract->provided_amount = max(0, $contract->provided_amount - $partialAmount);
        $contract->save();
        $pawnshop =  auth()->user()->pawnshop ?? Pawnshop::where('id',1)->first();
        $pawnshop->given -= $partialAmount;
        $pawnshop->save();
        // Create the partial payment record
//        if ($isActionable) {
//            return $this->createPayment($contract->id, $partialAmount, 'partial', $payer, $cash,$history, $deal_id);
//        }
        return $this->createPayment($contract->id, $partialAmount, 'partial', $payer, $cash,$history,$deal_id,$date);

    }



    public function processFullPayment($contract, $amount, $payer, $cash,$deal_id = null)
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
        // Remove remaining initial payments
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
            'old_estimated' => $contract->estimated_amount,
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
        $payment = $this->createPayment($contract->id, $amount, 'full', $payer, $cash,$history,$deal_id);

        $contract->status = 'completed';
        $contract->left = 0;
        $contract->collected += $amount;
        $contract->provided_amount = 0;
        $contract->estimated_amount = 0;
        $contract->save();

        return [
            'payment_id' => $payment,
            'penalty' => $penalty,
            'delay_days' => $delayDays,
            'interest_amount' => $interestAmount
        ];
    }
    public function calcAmount($amount,$days,$rate): int
    {
        return intval(ceil($days * $rate * $amount * 0.01 /10) * 10);
    }


}
