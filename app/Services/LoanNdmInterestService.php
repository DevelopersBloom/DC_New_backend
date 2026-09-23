<?php
//
//namespace App\Services;
//
//use App\Models\LoanNdm;
//use Carbon\Carbon;
//
//class LoanNdmInterestService
//{
//    public function calculate(LoanNdm $loan, string $fromDate, string $toDate): array
//    {
//        $from = Carbon::parse($fromDate)->startOfDay();
//        $to   = Carbon::parse($toDate)->startOfDay();
//        if ($to->lt($from)) {
//            throw new \InvalidArgumentException('toDate must be >= fromDate');
//        }
//
//        $annualRate = (float)($loan->interest_rate ?? 0) / 100.0;
//        [$baseDaysFunc, $fixedBaseDays] = $this->resolveDayCount($loan->day_count_convention ?? 'calendar_year');
//
//        $txs = $loan->journals()
//            ->with(['transactions' => function ($q) use ($to) {
//                $q->whereDate('date', '<=', $to->toDateString());
//            }])
//            ->get()
//            ->pluck('transactions')
//            ->flatten();
//
//        $events = collect();
//        foreach ($txs as $trx) {
//            $d = Carbon::parse($trx->date)->startOfDay();
//            if ((int)$trx->credit_account_id === (int)$loan->account_id) {
//                $events->push(['date' => $d, 'delta' => (float)$trx->amount_amd]);   // utilization ↑
//            } elseif ((int)$trx->debit_account_id === (int)$loan->account_id) {
//                $events->push(['date' => $d, 'delta' => -(float)$trx->amount_amd]);  // repayment ↓
//            }
//        }
//
//        $initialPrincipal = $events->filter(fn($e) => $e['date']->lt($from))->sum('delta');
//
//        $periodEvents = $events
//            ->filter(fn($e) => !$e['date']->lt($from) && !$e['date']->gt($to))
//            ->sortBy('date')
//            ->values();
//
//        $timeline = collect();
//        $timeline->push(['date' => $from->copy(), 'delta' => 0.0]);
//        foreach ($periodEvents as $e) $timeline->push($e);
//        $timeline->push(['date' => $to->copy(), 'delta' => 0.0]);
//        $timeline = $timeline->sortBy('date')->values();
//
//        // Հաշվարկ
//        $principal = (float)$initialPrincipal;
//        $interestTotal = 0.0;
//        $weightedPrincipalYearFractions = 0.0;
//
//        for ($i = 0; $i < $timeline->count() - 1; $i++) {
//            $curr = $timeline[$i];
//            $next = $timeline[$i + 1];
//
//            if ($i > 0 || $curr['delta'] != 0.0) {
//                $principal += (float)$curr['delta'];
//                if ($principal < 0) $principal = 0.0;
//            }
//
//            $segFrom = $curr['date'];
//            $segTo   = $next['date'];
//            $days    = $segFrom->diffInDays($segTo);
//
//            if ($days > 0 && $principal > 0) {
//                $baseDays = $fixedBaseDays ?? $baseDaysFunc($segFrom);
//
//                // տոկոսագումար՝ nominal տոկոսադրույքով
//                $interest = $principal * $annualRate * ($days / $baseDays);
//
//                $interestTotal += $interest;
//                $weightedPrincipalYearFractions += $principal * ($days / $baseDays);
//            }
//        }
//
//        // Արդյունավետ տոկոսադրույք (annualized)
//        $effAnnual = $weightedPrincipalYearFractions > 0
//            ? $interestTotal / $weightedPrincipalYearFractions
//            : 0.0;
//
//        // Արդյունավետ տոկոսագումար (effective amount)
//        $effectiveInterestAmount = $effAnnual * $weightedPrincipalYearFractions;
//
//        return [
//            // տոկոսագումար՝ loan->interest_rate nominal տոկոսադրույքով
//            'interest_amount'          => round($interestTotal, 2),
//
//            // հաշվարկված արդյունավետ տարեկան տոկոսադրույք
//
//            // տոկոսագումար՝ արդյունավետ տոկոսադրույքով
//            'effective_interest_amount'=> round($effectiveInterestAmount, 2),
//        ];
//    }
//
//    protected function resolveDayCount(string $dcc): array
//    {
//        $dcc = strtolower($dcc);
//        if ($dcc === 'days_360' || $dcc === 'fixed_day') {
//            return [fn(Carbon $d) => 360, 360];
//        }
//        $fn = fn(Carbon $d) => $d->isLeapYear() ? 366 : 365;
//        return [$fn, null];
//    }
//}


namespace App\Services;

use App\Models\DocumentJournal;
use App\Models\LoanNdm;
use Carbon\Carbon;

class LoanNdmInterestService
{
    public function calculate(LoanNdm $loan, string $fromDate, string $toDate): array
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();



        [$baseDaysFunc, $fixedBaseDays] = $this->resolveDayCount($loan->day_count_convention ?? 'calendar_year');
        $W = $this->weightedYearFractions($loan, $from, $to, $baseDaysFunc, $fixedBaseDays);

        $nominalRate = (float)($loan->interest_rate ?? 0);            // % / year
        $effectiveRate = (float)($loan->effective_interest_rate ?? 0);  // % / year
        $interestAmount = $W * ($nominalRate / 100.0);
        $effectiveInterestAmount = $W * ($effectiveRate / 100.0);

        return [
            'interest_amount' => round($interestAmount, 2),
            'effective_interest_amount' => round($effectiveInterestAmount, 2),
            // 'interest_rate'            => round($nominalRate, 4),
            // 'effective_interest_rate'  => round($effectiveRate, 4),
        ];
    }

    /**
     * Journal row for an interest-accrual posting built for `transactions`, so both tables carry the
     * same entry (number, accounts, partners, amount and parent journal).
     */
    public function recordAccrualJournal(array $posting): DocumentJournal
    {
        return DocumentJournal::create([
            'date'              => $posting['date'],
            'document_number'   => $posting['document_number'],
            'document_type'     => $posting['document_type'],
            'amount_amd'        => $posting['amount_amd'],
            'currency_id'       => $posting['amount_currency_id'] ?? null,
            'amount_currency'   => $posting['amount_currency'] ?? null,
            'debit_partner_id'  => $posting['debit_partner_id'] ?? null,
            'credit_partner_id' => $posting['credit_partner_id'] ?? null,
            'debit_account_id'  => $posting['debit_account_id'],
            'credit_account_id' => $posting['credit_account_id'],
            'comment'           => $posting['comment'] ?? null,
            'user_id'           => $posting['user_id'] ?? null,
            'journalable_type'  => $posting['transactionable_type'],
            'journalable_id'    => $posting['transactionable_id'],
        ]);
    }

    protected function weightedYearFractions(LoanNdm  $loan, Carbon $from,
        Carbon   $to,
        callable $baseDaysFunc,
        ?int     $fixedBaseDays
    ): float
    {
        // The loan's own rows (base, repayments) and the rows attached to them (attractions, accruals).
        $loanJournalIds = $loan->journals()->pluck('id');

        $txs = DocumentJournal::query()
            ->where(function ($q) use ($loan, $loanJournalIds) {
                $q->where(function ($q) use ($loan) {
                    $q->where('journalable_type', LoanNdm::class)->where('journalable_id', $loan->id);
                })->orWhere(function ($q) use ($loanJournalIds) {
                    $q->where('journalable_type', DocumentJournal::class)->whereIn('journalable_id', $loanJournalIds);
                });
            })
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('id')
            ->get(['date', 'debit_account_id', 'credit_account_id', 'amount_amd']);

        $events = collect();
        foreach ($txs as $trx) {
            $d = Carbon::parse($trx->date)->startOfDay();
            if ((int)$trx->debit_account_id === (int)$loan->account_id) {
                $events->push(['date' => $d, 'delta' => (float)$trx->amount_amd]);   // utilization ↑
            } elseif (((int)$trx->credit_account_id === (int)$loan->account_id)) {
                $events->push(['date' => $d, 'delta' => -(float)$trx->amount_amd]);  // repayment ↓
            }
        }

        $initialPrincipal = $events->filter(fn($e) => $e['date']->lt($from))->sum('delta');

        $periodEvents = $events
            ->filter(fn($e) => !$e['date']->lt($from) && !$e['date']->gt($to))
            ->sortBy('date')
            ->values();

        $timeline = collect();
        $timeline->push(['date' => $from->copy(), 'delta' => 0.0]);  // start marker
        foreach ($periodEvents as $e) $timeline->push($e);
        $timeline->push(['date' => $to->copy(), 'delta' => 0.0]);    // end marker
        $timeline = $timeline->sortBy('date')->values();

        $principal = (float)$initialPrincipal;
        $W = 0.0;

        for ($i = 0; $i < $timeline->count() - 1; $i++) {
            $curr = $timeline[$i];
            $next = $timeline[$i + 1];

            if ($i > 0 || $curr['delta'] != 0.0) {
                $principal += (float)$curr['delta'];
                if ($principal < 0) $principal = 0.0;
            }

            $segFrom = $curr['date'];
            $segTo = $next['date'];
            $days = $segFrom->diffInDays($segTo); // last-day-exclusive

            if ($days > 0 && $principal > 0) {
                $baseDays = $fixedBaseDays ?? $baseDaysFunc($segFrom);
                $W += $principal * ($days / $baseDays);
            }
        }

        return $W;
    }

    protected function resolveDayCount(string $dcc): array
    {
        $dcc = strtolower($dcc);
        if ($dcc === 'days_360' || $dcc === 'fixed_day') {
            return [fn(Carbon $d) => 360, 360];
        }
        $fn = fn(Carbon $d) => $d->isLeapYear() ? 366 : 365;
        return [$fn, null];
    }
}
