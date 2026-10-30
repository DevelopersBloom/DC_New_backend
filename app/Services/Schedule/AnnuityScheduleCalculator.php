<?php

namespace App\Services\Schedule;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Pure annuity calculation for the part of a schedule that follows the current
 * (kept) installment: due dates on the contract's payment day, the last one on
 * the business day of the contract deadline.
 *
 * Same conventions as ContractService::computeBrokenPeriodAnnuitySchedule():
 * the level payment is sized on the nominal monthly rate (daily % x 365 / 12),
 * while each row's interest is balance x actual days x daily rate.
 */
class AnnuityScheduleCalculator
{
    /**
     * @param float  $balance          principal still owed once the kept row is paid
     * @param float  $dailyRatePct     contracts.interest_rate (percent per day)
     * @param float  $feeAnnualPct     annual service fee percent (0 = none)
     * @param Carbon $periodStart      first day of the first new row (kept row's due date)
     * @param Carbon $calendarStart    calendar due date of the kept row (anchor of the cadence)
     * @param Carbon $deadline         contracts.deadline
     *
     * @return array<int, array{
     *   calendar_due_date: string, business_due_date: string, from_date: string,
     *   days: int, principal: float, interest: float, service_fee: float,
     *   total: float, balance_after: float, is_last: bool
     * }>
     */
    public function build(
        float $balance,
        float $dailyRatePct,
        float $feeAnnualPct,
        Carbon $periodStart,
        Carbon $calendarStart,
        Carbon $deadline
    ): array {
        if ($balance <= 0) {
            throw new InvalidArgumentException('Balance must be positive.');
        }

        $periodStart   = $periodStart->copy()->startOfDay();
        $calendarStart = $calendarStart->copy()->startOfDay();
        $deadline      = $deadline->copy()->startOfDay();
        $lastDue       = WorkingDays::next($deadline->copy());

        // Rows = months from the kept row's calendar due date to the deadline month;
        // the anchor row of the deadline month is replaced by the deadline itself.
        $months = ($deadline->year - $calendarStart->year) * 12 + ($deadline->month - $calendarStart->month);
        if ($months < 1 || $lastDue->lte($periodStart)) {
            throw new InvalidArgumentException('No schedule rows fit between the current installment and the deadline.');
        }

        $dailyRate    = $dailyRatePct / 100.0;
        $feeDailyRate = $feeAnnualPct > 0 ? $feeAnnualPct / 365 / 100.0 : 0.0;
        $monthlyRate  = $dailyRatePct * 365 / 100 / 12 + ($feeAnnualPct > 0 ? $feeAnnualPct / 100 / 12 : 0.0);
        $pmt          = $this->pmt($monthlyRate, $months, $balance);

        $rows     = [];
        $left     = $balance;
        $fromDate = $periodStart;

        for ($i = 1; $i <= $months; $i++) {
            $isLast   = ($i === $months);
            $calendar = $calendarStart->copy()->addMonthsNoOverflow($i);
            $due      = $isLast ? $lastDue->copy() : WorkingDays::next($calendar->copy());

            $days     = (int) $fromDate->diffInDays($due);
            $interest = round($left * $dailyRate * $days, 2);
            $fee      = $feeAnnualPct > 0 ? round($left * $feeDailyRate * $days, 2) : 0.0;

            if ($isLast) {
                $principal = round($left, 2);
                $total     = round($principal + $interest + $fee, 2);
            } else {
                $principal = round($pmt - $interest - $fee, 2);
                $total     = round($pmt, 2);
            }

            $left = round($left - $principal, 2);

            $rows[] = [
                'calendar_due_date' => $isLast ? $lastDue->toDateString() : $calendar->toDateString(),
                'business_due_date' => $due->toDateString(),
                'from_date'         => $fromDate->toDateString(),
                'days'              => $days,
                'principal'         => $principal,
                'interest'          => $interest,
                'service_fee'       => $fee,
                'total'             => $total,
                'balance_after'     => $left,
                'is_last'           => $isLast,
            ];

            $fromDate = $due;
        }

        return $rows;
    }

    private function pmt(float $monthlyRate, int $periods, float $present): float
    {
        if ($monthlyRate == 0.0) {
            return $present / $periods;
        }

        return $present * $monthlyRate / (1 - pow(1 + $monthlyRate, -$periods));
    }
}
