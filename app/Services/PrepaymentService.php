<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Prepayment;
use Illuminate\Support\Collection;

class PrepaymentService
{
    public function createFromEarlyPayment(int $contractId, int $paymentId, ?int $dealId, Collection $remainingPayments): void
    {
        foreach ($remainingPayments as $remaining) {
            if (!$remaining->principal_payment || $remaining->principal_payment <= 0) {
                continue;
            }

            $this->createSingle($contractId, $paymentId, $dealId, $remaining->principal_payment, $remaining->date);
        }
    }

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
