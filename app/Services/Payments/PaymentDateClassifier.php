<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * A1: classifies a payment date against the earliest open installment's
 * business_due_date. That date is already working-day-shifted at
 * schedule-creation time and stored directly on Payment::to_date / Payment::date
 * (see ContractService::regenerateSchedule()) — including for the first,
 * broken-period row — so no shifting logic is needed here, only the comparison.
 */
class PaymentDateClassifier
{
    public const SOONER   = 'sooner';
    public const DUE_DATE = 'due_date';
    public const LATE     = 'late';

    /**
     * @return string|null one of self::SOONER | self::DUE_DATE | self::LATE,
     *                      or null if the contract has no open installment left.
     */
    public function classify(Contract $contract, ?string $date = null): ?string
    {
        $payment = $this->earliestOpenInstallment($contract);
        if (!$payment) {
            return null;
        }

        return $this->classifyAgainstDueDate($payment->to_date ?? $payment->date, $date);
    }

    /**
     * Pure comparison (no DB) — exposed so the date logic can be unit tested
     * without needing a real schedule.
     */
    public function classifyAgainstDueDate(string $dueDate, ?string $date = null): string
    {
        $now = $date
            ? Carbon::parse($date, 'Asia/Yerevan')->startOfDay()
            : Carbon::now('Asia/Yerevan')->startOfDay();

        $due = Carbon::parse($dueDate, 'Asia/Yerevan')->startOfDay();
        dd($now,$due,$now->eq($due));

        if ($now->lt($due)) {
            return self::SOONER;
        }
        if ($now->eq($due)) {
            return self::DUE_DATE;
        }

        return self::LATE;
    }

    private function earliestOpenInstallment(Contract $contract): ?Payment
    {
        return Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->orderBy('to_date')
            ->orderBy('id')
            ->first();
    }
}
