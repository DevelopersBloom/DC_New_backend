<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Carbon;

class PaymentService {
    public function processPayments($contract, $amount, $penalty, $payer, $cash, $payments) {
        $paymentsSum = 0;
        foreach ($payments as $item) {
            $paymentsSum += $item['amount'] + $item['mother'] + $item['penalty'];
        }

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
            if ($amount > 0) {
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
        $paymentFinal = ($payment['amount'] + $payment['mother'] + $payment['penalty']) - $payment['paid'];
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
        $payment->status = 'completed';
        $payment->paid = $payment['amount'] + $payment['mother'] + $payment['penalty'];
        $payment->date = Carbon::now()->format('Y.m.d');
        $payment->penalty = $payment['penalty'];
        $payment->cash = $cash;

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
       // $payment->amount -= $amount;
        $payment->paid += $paid;
        $payment->save();
    }

    private function handleRemainingAmount($contract, $amount, $cash): void
    {
        $decrease = $amount % 1000;
        $amount -= $decrease;

        $nextPayment = Payment::where('contract_id', $contract->id)->where('status', 'initial')->first();
        if ($nextPayment) {
            $nextPayment->amount -= $decrease;
            $nextPayment->paid = $decrease;
            $nextPayment->save();
            $contract->collected += $decrease;
        } else {
            $this->payPartial($contract->id, $amount, false, $cash);
        }

    }
    public function createPayment($contractId, $amount, $type, $payer, $cash): void
    {
        $status = ($type === 'penalty' ||  $type === 'full') ? 'completed' : 'initial';
        $payment = new Payment();
        $payment->contract_id = $contractId;
        $payment->amount = $amount;
        $payment->paid = $amount;
        $payment->type = $type;
        $payment->cash = $cash;
        $payment->pawnshop_id = auth()->user()->pawnshop_id;
        $payment->date = Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d');
        $payment->status = $status;
        if ($payer) {
            $payment->another_payer = true;
            $payment->name = $payer['name'];
            $payment->surname = $payer['surname'];
            $payment->phone = $payer['phone'];
        }

        $payment->save();
    }

    public function payPartial($contractId, $amount, $payer, $cash): void
    {
        $payment = new Payment();
        $payment->contract_id = $contractId;
        $payment->amount = $amount;
        $payment->type = 'partial';
        $payment->cash = $cash;

        if ($payer) {
            $payment->another_payer = true;
            $payment->name = $payer['name'];
            $payment->surname = $payer['surname'];
            $payment->phone = $payer['phone'];
        }

        $payment->save();
    }
    public function processFullPayment($contract, $amount, $payer, $cash)
    {
        // Remove remaining initial payments
        $p =Payment::where('contract_id', $contract->id)
            ->where('status', 'initial')->delete();

        // process full payment
        $this->createPayment($contract->id, $amount, 'full', $payer, $cash);
        $contract->status = 'completed';
        $contract->left = 0;
        $contract->collected += $amount;
        $contract->save();

        return $contract;
    }
}
