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

        $alreadyExists = Prepayment::where('contract_id', $contractId)
            ->where('due_date', $dueDate)
            ->exists();

        if ($alreadyExists) {
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
