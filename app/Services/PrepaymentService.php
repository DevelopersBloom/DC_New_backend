<?php

namespace App\Services;

use App\Models\Prepayment;

class PrepaymentService
{
    public function createSingle(int $contractId, int $paymentId, ?int $dealId, float $amount, string $dueDate): void
    {
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
            return;
        }

        Prepayment::create([
            'contract_id' => $contractId,
            'payment_id'  => $paymentId,
            'deal_id'     => $dealId,
            'amount'      => $amount,
            'due_date'    => $dueDate,
            'status'      => 'unpaid',
        ]);
    }
}
