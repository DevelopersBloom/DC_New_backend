<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\PaymentEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LatePaymentInterestRecalculator
{
    /**
     * Recalculate and persist adjusted interest for all installments that were
     * crossed (fully or partially) by the actual payment date arriving late.
     * */
    public function recalculate(Contract $contract, string $paymentDate, $payments = null): void
    {
        DB::transaction(function () use ($contract, $paymentDate, $payments) {
            $allPayments = $payments !== null
                ? collect($payments)->where('type', 'regular')->where('status', 'initial')->sortBy('to_date')->values()
                : Payment::where('contract_id', $contract->id)
                    ->where('type', 'regular')
                    ->where('status', 'initial')
                    ->orderBy('to_date')
                    ->orderBy('id')
                    ->get();

            if ($allPayments->isEmpty()) {
                return;
            }

            $totalCollected  = (float) PaymentEntry::where('contract_id', $contract->id)
                ->sum('principal_amount');
//            $originalBalance = (float) $contract->mother;
            $originalBalance = (float) $contract->provided_amount;

            $historicalEntries = PaymentEntry::where('contract_id', $contract->id)
                ->where('principal_amount', '>', 0)
                ->orderBy('date')
                ->get(['date', 'principal_amount'])
                ->map(fn ($e) => ['date' => (string) $e->date, 'principal_amount' => (float) $e->principal_amount])
                ->all();

            $dailyRate = (float) $contract->interest_rate / 100.0;

            $updates = $this->computeUpdates($allPayments, $originalBalance, $dailyRate, $paymentDate, $historicalEntries);
            foreach ($updates as ['payment' => $payment, 'interest' => $recalc_interest]) {
                $recalc_interest = round($recalc_interest, 2);

                $entries             = $payment->entries()->get();
                $alreadyPaidInterest = (float) $entries->sum('interest_amount');
                if ($entries->isEmpty()) {
                    $alreadyPaidInterest = min(
                        (float) $payment->paid,
                        max(0, (float) $payment->original_interest_payment - (float) $payment->interest_payment)
                    );
                }
                //        $payment->original_interest_payment = $recalc_interest;
                $payment->interest_payment           = max(0, round($recalc_interest - $alreadyPaidInterest, 2));
                dd($recalc_interest,$alreadyPaidInterest);
                $payment->amount                     = round((float) $payment->principal_payment + $payment->interest_payment, 2);
                $payment->save();
            }
        });
    }

    // ── Pure calculation (no DB I/O — exposed for unit testing) ─────────────────

    /**
     * Compute recalculated interest for every affected installment without touching the DB.
     *
     * @param iterable $allPayments       All initial regular payments ordered by to_date.
     *                                     Each item must expose: from_date, to_date, principal_payment.
     * @param float    $originalBalance   Loan amount at inception (provided_amount + all collected principals).
     * @param float    $dailyRate         Daily interest rate, e.g. 0.0011 for a 0.11 % daily rate.
     * @param string   $paymentDate       Y-m-d actual payment date.
     * @param array    $historicalEntries [['date' => 'Y-m-d', 'principal_amount' => float], ...]
     *                                     Principals already collected via PaymentEntry records.
     *
     * @return array  [['payment' => $payment, 'interest' => float], ...]  only for affected installments.
     */
    public function computeUpdates(
        $allPayments,
        float $originalBalance,
        float $dailyRate,
        string $paymentDate,
        array $historicalEntries = []
    ): array {
        $payDate     = Carbon::parse($paymentDate)->startOfDay();
        $allPayments = collect($allPayments);

        // Affected = installments whose period started before the payment date.
        $affected = $allPayments->filter(
            fn ($p) => Carbon::parse($p->from_date)->startOfDay()->lt($payDate)
        );

        if ($affected->isEmpty()) {
            return [];
        }

        // Principals of fully-overdue installments (to_date ≤ paymentDate, status=initial).
        // These are collected AT paymentDate, reducing the balance for the segment that follows.
        $overdueByPayDatePrincipals = (float) $allPayments
            ->filter(fn ($p) => Carbon::parse($p->to_date)->startOfDay()->lte($payDate))
            ->sum(fn ($p) => (float) $p->principal_payment);

        $results = [];

        foreach ($affected as $payment) {
            $fromDate  = Carbon::parse($payment->from_date)->startOfDay();
            $toDate    = Carbon::parse($payment->to_date)->startOfDay();
            $totalDays = (int) $fromDate->diffInDays($toDate);

            // Balance at the start of this period:
            // original amount minus principals that were historically collected on or before from_date.
            $collectedBeforeFrom = 0.0;
//            foreach ($historicalEntries as $entry) {
//                if ($entry['date'] <= $fromDate->toDateString()) {
//                    $collectedBeforeFrom += $entry['principal_amount'];
//                }
//            }
//            $balanceAtStart = $originalBalance - $collectedBeforeFrom;
            $balanceAtStart = $originalBalance;

            if ($payDate->gte($toDate)) {
                // Fully crossed: the entire period elapsed before the client paid.
                // No principal was collected during this period → constant balance.
                $interest = self::segmentInterest($balanceAtStart, $dailyRate, $totalDays);
                dd($payment->id,$interest,$balanceAtStart,$totalDays,$dailyRate);

            } else {
                // Partially crossed: split at paymentDate.
                $daysBefore = (int) $fromDate->diffInDays($payDate);
                $daysAfter  = $totalDays - $daysBefore;

                // After paymentDate the overdue installments' principals are collected,
                // reducing the balance for the remainder of this period.
                $balanceAtPayDate = max(0.0, $balanceAtStart - $overdueByPayDatePrincipals);
                $interest = self::segmentInterest($balanceAtStart, $dailyRate, $daysBefore)
                    + self::segmentInterest($balanceAtPayDate, $dailyRate, $daysAfter);
dd($payment->id,$interest,$balanceAtStart,$balanceAtPayDate,$dailyRate,$daysAfter,$daysBefore);
            }
            $results[] = ['payment' => $payment, 'interest' => $interest];
        }
        return $results;
    }

    /**
     * Interest accrued over a single segment: balance × dailyRate × days.
     */
    public static function segmentInterest(float $balance, float $dailyRate, int $days): float
    {
        return $balance * $dailyRate * $days;
    }
}
