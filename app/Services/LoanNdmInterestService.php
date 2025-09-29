<?php

namespace App\Services;

use App\Models\LoanNdm;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LoanNdmInterestService
{
    public function calculate(LoanNdm $loan, string $fromDate, string $toDate): array
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to   = Carbon::parse($toDate)->startOfDay();
        if ($to->lt($from)) {
            throw new \InvalidArgumentException('toDate must be >= fromDate');
        }

        $annualRate = (float)($loan->interest_rate ?? 0) / 100.0;
        [$baseDaysFunc, $fixedBaseDays] = $this->resolveDayCount($loan->day_count_convention ?? 'calendar_year');

        $txs = $loan->journals()
            ->with(['transactions' => function ($q) use ($to) {
                $q->whereDate('date', '<=', $to->toDateString());
            }])
            ->get()
            ->pluck('transactions')
            ->flatten();

        $events = collect();
        foreach ($txs as $trx) {
            $d = Carbon::parse($trx->date)->startOfDay();
            $delta = 0.0;

            if ((int)$trx->credit_account_id === (int)$loan->account_id) {
                $delta = (float)$trx->amount_amd; // utilization ↑
            } elseif ((int)$trx->debit_account_id === (int)$loan->account_id) {
                $delta = -(float)$trx->amount_amd; // repayment ↓
            } else {
                continue;
            }

            $events->push([
                'date'  => $d,
                'delta' => $delta,
            ]);
        }

        // Սկզբնական principal՝ մինչև from եղած Δ-ների գումարը
        $initialPrincipal = $events
            ->filter(fn($e) => $e['date']->lt($from))
            ->sum('delta');

        // Կտրում/բերում ենք միայն from..to միջակայքի իրադարձությունները
        $periodEvents = $events
            ->filter(fn($e) => !$e['date']->lt($from) && !$e['date']->gt($to))
            ->sortBy('date')
            ->values();

        // Timeline կետեր՝ from (initial), ...events..., to
        $timeline = collect();
        $timeline->push(['date' => $from->copy(), 'delta' => 0.0]); // initial marker
        foreach ($periodEvents as $e) $timeline->push($e);
        $timeline->push(['date' => $to->copy(), 'delta' => 0.0]);

        $timeline = $timeline->sortBy('date')->values();

        // Գլխավոր հաշվարկ
        $principal = (float)$initialPrincipal;
        $interestTotal = 0.0;
        $weightedPrincipalYearFractions = 0.0;
        $daysTotal = 0;
        $breakdown = [];

        for ($i = 0; $i < $timeline->count() - 1; $i++) {
            $curr = $timeline[$i];
            $next = $timeline[$i + 1];

            // Իրադարձության օրվա ՍԿԻԶԲԻՆ կիրառենք delta-ն
            if ($i > 0 || $curr['delta'] != 0.0) {
                $principal += (float)$curr['delta'];
                if ($principal < 0) $principal = 0.0; // ασφαλιστική δικλείδα
            }

            $segFrom = $curr['date']->copy();
            $segTo   = $next['date']->copy();
            $days    = $segFrom->diffInDays($segTo);

            if ($days > 0 && $principal > 0) {
                $baseDays = $fixedBaseDays ?? $baseDaysFunc($segFrom);
                $interest = $principal * $annualRate * ($days / $baseDays);

                $interestTotal += $interest;
                $weightedPrincipalYearFractions += $principal * ($days / $baseDays);
                $daysTotal += $days;

                $breakdown[] = [
                    'from'      => $segFrom->toDateString(),
                    'to'        => $segTo->toDateString(),
                    'days'      => $days,
                    'principal' => round($principal, 2),
                    'interest'  => round($interest, 2),
                ];
            } else {
                $breakdown[] = [
                    'from'      => $segFrom->toDateString(),
                    'to'        => $segTo->toDateString(),
                    'days'      => $days,
                    'principal' => round($principal, 2),
                    'interest'  => 0.00,
                ];
            }
        }

        $effAnnual = 0.0;
        if ($weightedPrincipalYearFractions > 0) {
            $effAnnual = $interestTotal / $weightedPrincipalYearFractions; // already per-annum
        }

        return [
            'interest_amount'       => round($interestTotal, 2),
            'effective_annual_rate' => round($effAnnual * 100, 4),
            'days_total'            => $daysTotal,
            'base_days'             => $fixedBaseDays ?? $baseDaysFunc($from),
            'breakdown'             => $breakdown,
        ];
    }

    protected function resolveDayCount(string $dcc): array
    {
        $dcc = strtolower($dcc);
        if ($dcc === 'days_360' || $dcc === 'fixed_day') {
            return [fn(Carbon $d) => 360, 360];
        }
        // default: calendar_year (Actual/365(366))
        $fn = fn(Carbon $d) => $d->isLeapYear() ? 366 : 365;
        return [$fn, null];
    }
}
