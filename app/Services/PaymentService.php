<?php

namespace App\Services;

use App\Models\Payment;
use App\Traits\ContractTrait;
use Illuminate\Support\Carbon;

class PaymentService {
    use ContractTrait;
    public function processPayments($contract, $amount, $payer, $cash, $payments) {
        $paymentsSum = 0;
        foreach ($payments as $item) {
            $paymentsSum += $item['amount'] + $item['mother'];
        }
        $penalty = $this->countPenalty($contract->id);

        // Process penalty
        if ($penalty) {
            $amount = $this->processPenalty($contract->id, $amount, $penalty, $payer, $cash);
        }
        // Process payments
        if ($amount > 0) {
            foreach ($payments as $payment) {
                $amount = $this->processSinglePayment($contract, $payment, $amount, $payer, $cash);
            }
            // Handle any remaining amount
            if ($amount > 0 ) {
                $this->handleRemainingAmount($contract, $amount, $cash);
            }
        }
        return $paymentsSum;
    }

    private function processPenalty($contractId, $amount, $penalty, $payer, $cash) {
        if ($amount <= $penalty) {
            $this->createPayment($contractId, $amount, 'penalty', $payer, $cash);
            return 0;
        } else {
            $this->createPayment($contractId, $penalty, 'penalty', $payer, $cash);
            return $amount - $penalty;
        }
    }

    private function processSinglePayment($contract, $payment, $amount, $payer, $cash) {
        $paymentFinal = ($payment['amount'] + $payment['penalty']);
        if ($amount >= $paymentFinal) {
            $this->completePayment($payment,$payer, $cash);
            $contract->collected += $paymentFinal;
            return $amount - $paymentFinal;
        } else {
            $this->partiallyCompletePayment($payment, $amount);
            $contract->collected += $amount;
            return 0;
        }
    }

    private function completePayment($payment, $payer, $cash): void
    {
        $payment->paid += $payment['amount'] + $payment['penalty'];
        $payment->date = Carbon::now()->format('d.m.Y');
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
    }

    private function partiallyCompletePayment($payment, $paid): void
    {
        $payment->amount -= $paid;
        $payment->paid += $paid;
        if ($payment->last_payment && $payment->amount == 0) {
            $payment->mother -= $payment->paid;
        }
        $payment->save();
    }

    private function handleRemainingAmount($contract, $amount, $cash): void
    {
        $decrease = $amount % 1000;
        $amount -= $decrease;
        $nextPayment = Payment::where('contract_id', $contract->id)->where('status', 'initial')->first();
        if ($nextPayment && $decrease > 0) {
            $nextPayment->amount -= $decrease;
            $nextPayment->paid += $decrease;
            $nextPayment->save();
            $contract->collected += $decrease;
        }
        if ($amount > 0) {
            $this->payPartial($contract, $amount, false, $cash);
        }


    }
    public function createPayment($contractId, $amount, $type, $payer, $cash): void
    {
       // $status = ($type === 'penalty' ||  $type === 'full') ? 'completed' : 'initial';
        if ($type === 'penalty' || $type === 'full') {
            $status = 'completed';
        } elseif ($type === 'partial') {
            $status = 'partial';
        }else {
            $status = 'initial';
        }
        $payment = new Payment();
        $payment->contract_id = $contractId;
        $payment->amount = $amount;
        $payment->paid = $amount;
        $payment->type = $type;
        $payment->cash = $cash;
        $payment->pawnshop_id = auth()->user()->pawnshop_id;
        $payment->date = Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y');
        $payment->status = $status;
        if ($payer) {
            $payment->another_payer = true;
            $payment->name = $payer['name'];
            $payment->surname = $payer['surname'];
            $payment->phone = $payer['phone'];
        }

        $payment->save();
    }

    public function payPartial($contract, $partialAmount, $payer, $cash): void
    {
        $now = Carbon::now();
        $payments = Payment::where('contract_id', $contract->id)->where('type', 'regular')->get();
        $startedToChange = false;
        $daysToCalc = 0;
        foreach ($payments as $index => $payment) {
            $dateToCheck = Carbon::createFromFormat('d.m.Y', $payment->date);
            if ($dateToCheck->gt($now)) {
                if ($startedToChange) {
                    $coeff = ($contract->left - $partialAmount) / $contract->left;
                    $payment->amount = intval(ceil($payment->amount * $coeff / 10) * 10);
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
                    $payment->amount = $sum;
                }
                $payment->save();
            }
            if ($payment->last_payment) {
                $payment->mother = $contract->left - $partialAmount;
                $payment->save();
            }
        }
        // Update contract with partial payment
        $contract->left -= $partialAmount;
        $contract->collected += $partialAmount;
        $contract->save();

        auth()->user()->pawnshop->given -= $partialAmount;
        auth()->user()->pawnshop->save();

        // Create the partial payment record
        $this->createPayment($contract->id, $partialAmount, 'partial', $payer, $cash);


    }



    public function processFullPayment($contract, $amount, $payer, $cash)
    {
        // Remove remaining initial payments
        $p =Payment::where('contract_id', $contract->id)
            ->where('status', 'initial')->delete();

        // process full payment
        $this->createPayment($contract->id, $amount, 'full', $payer, $cash);
        $contract->status = 'full';
        $contract->left = 0;
        $contract->collected += $amount;
        $contract->save();

        return $contract;
    }
    public function calcAmount($amount,$days,$rate): int
    {
        return intval(ceil($days * $rate * $amount * 0.01 /10) * 10);
    }


}
