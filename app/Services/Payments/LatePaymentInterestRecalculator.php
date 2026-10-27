<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LatePaymentInterestRecalculator
{
    public function __construct(
        protected ScheduledPaymentHandler $scheduledHandler,
    ) {}

    /**
     * Recalculate and persist adjusted interest for all installments that were
     * crossed (fully or partially) by the actual payment date arriving late.
     *
     * Runs only for a LATE payment date (see isLate()); on time or early it is a
     * no-op, so it never overwrites interest that ScheduledPaymentHandler just wrote.
     *
     * The interest itself is ScheduledPaymentHandler::recalculateInterest() with the
     * payment date as "now": one calculation for both paths, so neither can overwrite
     * the other with a different result. There the overdue principal of earlier rows
     * keeps accruing until the payment date (see ScheduledPaymentHandler::scheduleStateFor()).
     * */
    public function recalculate(Contract $contract, string $paymentDate): void
    {
        DB::transaction(function () use ($contract, $paymentDate) {
            $allPayments = Payment::where('contract_id', $contract->id)
                ->where('type', 'regular')
                ->where('status', 'initial')
                ->orderBy('to_date')
                ->orderBy('id')
                ->get();

            if ($allPayments->isEmpty() || !self::isLate($allPayments, $paymentDate)) {
                return;
            }

            // Affected = installments whose period started before the payment date.
            $payDate  = Carbon::parse($paymentDate)->startOfDay();
            $affected = $allPayments->filter(
                fn ($p) => Carbon::parse($p->from_date)->startOfDay()->lt($payDate)
            );

            if ($affected->isNotEmpty()) {
                $this->scheduledHandler->recalculateInterest($contract, $affected, $payDate);
            }
        });
    }

    /**
     * The exact condition for running the late recalculation: some open row's
     * business due date (to_date, already working-day shifted) is strictly before
     * the payment date. Same rule as PaymentDateClassifier::LATE for the earliest
     * open row. A payment on the due date or before it changes nothing here.
     */
    public static function isLate($allPayments, string $paymentDate): bool
    {
        $payDate = Carbon::parse($paymentDate)->toDateString();

        return collect($allPayments)->contains(
            fn ($p) => Carbon::parse($p->to_date ?? $p->date)->toDateString() < $payDate
        );
    }
}
