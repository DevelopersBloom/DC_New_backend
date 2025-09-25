<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IncomeExpenseMonthlyReport
{


    public function build(string $from, string $to): array
    {
        // Debit կողմ
        $debit = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
            ->whereNotNull('t.debit_account_id')
            ->whereNotNull('a.income_expense')
            ->whereBetween('t.date', [$from, $to])
            ->selectRaw("
            a.income_expense as code,
            SUM(CASE WHEN a.type IN ('active','expense','off_balance') THEN t.amount_amd ELSE 0 END) as inflow,
            SUM(CASE WHEN a.type IN ('passive','equity','income') THEN t.amount_amd ELSE 0 END) as outflow
        ")
            ->groupBy('a.income_expense');   // <<=== ԱՆՊԱՅՄԱՆ

        // Credit կողմ
        $credit = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
            ->whereNotNull('t.credit_account_id')
            ->whereNotNull('a.income_expense')
            ->whereBetween('t.date', [$from, $to])
            ->selectRaw("
            a.income_expense as code,
            SUM(CASE WHEN a.type IN ('passive','equity','income') THEN t.amount_amd ELSE 0 END) as inflow,
            SUM(CASE WHEN a.type IN ('active','expense','off_balance') THEN t.amount_amd ELSE 0 END) as outflow
        ")
            ->groupBy('a.income_expense');

        $rows = DB::query()
            ->fromSub($debit->unionAll($credit), 'u')
            ->selectRaw("
            u.code,
            SUM(u.inflow)  as inflow,
            SUM(u.outflow) as outflow,
            SUM(u.inflow) - SUM(u.outflow) as net
        ")
            ->groupBy('u.code')
            ->orderBy('u.code')
            ->get();

        return ( $rows->map(fn($r) => [
            'code'    => (string)$r->code,
            'inflow'  => (float)$r->inflow,
            'outflow' => (float)$r->outflow,
            'net'     => (float)$r->net,
        ])->all());
    }
}
