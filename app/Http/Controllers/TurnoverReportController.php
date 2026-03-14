<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TurnoverReportController extends Controller
{
    /**
     * GET /api/reports/turnover?startDate=YYYY-MM-DD&endDate=YYYY-MM-DD
     *
     * Returns accounts with non-zero turnover in the period. Each row has
     * opening/period/closing debit and credit. Only accounts where
     * periodDebit > 0 OR periodCredit > 0 are included.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $startDate = $request->query('startDate');
        $endDate   = $request->query('endDate');

        if ($startDate === null || $startDate === '') {
            return response()->json(['error' => 'startDate is required'], 400);
        }
        if ($endDate === null || $endDate === '') {
            return response()->json(['error' => 'endDate is required'], 400);
        }

        $startParsed = strtotime($startDate);
        $endParsed   = strtotime($endDate);
        if ($startParsed === false || date('Y-m-d', $startParsed) !== $startDate) {
            return response()->json(['error' => 'startDate must be a valid date'], 400);
        }
        if ($endParsed === false || date('Y-m-d', $endParsed) !== $endDate) {
            return response()->json(['error' => 'endDate must be a valid date'], 400);
        }

        if ($startDate > $endDate) {
            return response()->json(['error' => 'startDate must be before or equal to endDate'], 400);
        }

        $rows = $this->buildTurnoverRows($startDate, $endDate);

        return response()->json($rows);
    }

    /**
     * Build turnover rows using a single grouped query (no N+1).
     * Data source: transactions (each row = one debit line + one credit line).
     */
    private function buildTurnoverRows(string $startDate, string $endDate): array
    {
        $periodLines = $this->periodLinesSubquery($startDate, $endDate);
        $openingLines = $this->openingLinesSubquery($startDate);

        $periodAgg = DB::query()
            ->fromSub($periodLines, 'p')
            ->select([
                'p.account_id',
                DB::raw('SUM(p.debit) as period_debit'),
                DB::raw('SUM(p.credit) as period_credit'),
            ])
            ->groupBy('p.account_id')
            ->havingRaw('SUM(p.debit) > 0 OR SUM(p.credit) > 0');

        $openingAgg = DB::query()
            ->fromSub($openingLines, 'o')
            ->select([
                'o.account_id',
                DB::raw('SUM(o.debit) as opening_debit'),
                DB::raw('SUM(o.credit) as opening_credit'),
            ])
            ->groupBy('o.account_id');

        $base = DB::table('chart_of_accounts as ca')
            ->whereNull('ca.deleted_at')
            ->joinSub($periodAgg, 'period', 'ca.id', '=', 'period.account_id')
            ->leftJoinSub($openingAgg, 'opening', 'ca.id', '=', 'opening.account_id')
            ->select([
                'ca.code',
                'ca.name',
                DB::raw('COALESCE(opening.opening_debit, 0) as opening_debit'),
                DB::raw('COALESCE(opening.opening_credit, 0) as opening_credit'),
                'period.period_debit',
                'period.period_credit',
            ])
            ->orderBy('ca.code');

        $results = $base->get();

        return $results->map(function ($row) {
            $openingDebitRaw  = (float) $row->opening_debit;
            $openingCreditRaw = (float) $row->opening_credit;
            $periodDebit      = (float) $row->period_debit;
            $periodCredit     = (float) $row->period_credit;

            $openingNet = $openingDebitRaw - $openingCreditRaw;
            if ($openingNet > 0) {
                $openingDebit  = $openingNet;
                $openingCredit = 0.0;
            } elseif ($openingNet < 0) {
                $openingDebit  = 0.0;
                $openingCredit = abs($openingNet);
            } else {
                $openingDebit  = 0.0;
                $openingCredit = 0.0;
            }

            $closingNet = ($openingDebit - $openingCredit) + ($periodDebit - $periodCredit);
            if ($closingNet > 0) {
                $closingDebit  = $closingNet;
                $closingCredit = 0.0;
            } elseif ($closingNet < 0) {
                $closingDebit  = 0.0;
                $closingCredit = abs($closingNet);
            } else {
                $closingDebit  = 0.0;
                $closingCredit = 0.0;
            }

            return [
                'code'           => (string) $row->code,
                'name'           => (string) $row->name,
                'openingDebit'   => round($openingDebit, 2),
                'openingCredit'  => round($openingCredit, 2),
                'periodDebit'    => round($periodDebit, 2),
                'periodCredit'   => round($periodCredit, 2),
                'closingDebit'   => round($closingDebit, 2),
                'closingCredit'  => round($closingCredit, 2),
            ];
        })->values()->all();
    }

    /**
     * Subquery: "journal lines" in [startDate, endDate].
     * Each transaction row contributes one debit line and one credit line.
     */
    private function periodLinesSubquery(string $startDate, string $endDate)
    {
        $debitSide = DB::table('transactions as t')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.debit_account_id')
            ->whereDate('t.date', '>=', $startDate)
            ->whereDate('t.date', '<=', $endDate)
            ->select([
                't.debit_account_id as account_id',
                't.amount_amd as debit',
                DB::raw('0 as credit'),
            ]);

        $creditSide = DB::table('transactions as t')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.credit_account_id')
            ->whereDate('t.date', '>=', $startDate)
            ->whereDate('t.date', '<=', $endDate)
            ->select([
                't.credit_account_id as account_id',
                DB::raw('0 as debit'),
                't.amount_amd as credit',
            ]);

        return $debitSide->unionAll($creditSide);
    }

    /**
     * Subquery: "journal lines" with entry_date < startDate (opening).
     */
    private function openingLinesSubquery(string $startDate)
    {
        $debitSide = DB::table('transactions as t')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.debit_account_id')
            ->whereDate('t.date', '<', $startDate)
            ->select([
                't.debit_account_id as account_id',
                't.amount_amd as debit',
                DB::raw('0 as credit'),
            ]);

        $creditSide = DB::table('transactions as t')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.credit_account_id')
            ->whereDate('t.date', '<', $startDate)
            ->select([
                't.credit_account_id as account_id',
                DB::raw('0 as debit'),
                't.amount_amd as credit',
            ]);

        return $debitSide->unionAll($creditSide);
    }
}
