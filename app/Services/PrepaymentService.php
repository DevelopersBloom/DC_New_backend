<?php

namespace App\Services;

use App\Models\Prepayment;

class PrepaymentService
{
    public function createSingle(
        int $contractId, int $paymentId, ?int $dealId,
        float $principalAmount, float $interestAmount, float $partialAmount, string $dueDate,
        bool $cash = false
    ): void {
        $amount = $principalAmount + $interestAmount + $partialAmount;
        if ($amount <= 0) {
            return;
        }

        $existing = Prepayment::where('contract_id', $contractId)
            ->where('due_date', $dueDate)
            ->where('status', 'unpaid')
            ->first();

        if ($existing) {
            // Accumulate partial prepayments for the same installment
            $existing->increment('amount', $amount);
            $existing->increment('principal_amount', $principalAmount);
            $existing->increment('interest_amount', $interestAmount);
            $existing->increment('partial_amount', $partialAmount);
            $existing->cash = $cash;
            $existing->save();
            return;
        }

        Prepayment::create([
            'contract_id'      => $contractId,
            'payment_id'       => $paymentId,
            'deal_id'          => $dealId,
            'amount'           => $amount,
            'principal_amount' => $principalAmount,
            'interest_amount'  => $interestAmount,
            'partial_amount'   => $partialAmount,
            'due_date'         => $dueDate,
            'status'           => 'unpaid',
            'cash'             => $cash,
        ]);
    }
}
