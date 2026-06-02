<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IncomeExpenseMonthlyReport
{
    public function build(string $date): array
    {
        $debit = DB::table('documents_journal as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.debit_account_id')
            ->whereNotNull('a.income_expense')
            ->where('t.date', '<=', $date)
            ->selectRaw("
            a.income_expense as code,
            SUM(CASE WHEN a.type IN ('active','expense','off_balance') THEN t.amount_amd ELSE 0 END) as inflow,
            SUM(CASE WHEN a.type IN ('passive','equity','income') THEN t.amount_amd ELSE 0 END) as outflow
        ")
            ->groupBy('a.income_expense');

        $credit = DB::table('documents_journal as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.credit_account_id')
            ->whereNotNull('a.income_expense')
            ->where('t.date', '<=', $date)
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
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $data[(string)$r->code] = [
                'code' => (string)$r->code,
                'inflow' => (float)$r->inflow,
                'outflow' => (float)$r->outflow,
                'net' => (float)$r->net,
            ];
        }

        $val52 = DB::table('documents_journal as t')
            ->join('chart_of_accounts as da', 'da.id', '=', 't.debit_account_id')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 't.credit_account_id')
            ->whereNull('t.deleted_at')
            ->where('da.code', '73015')
            ->where('ca.code', '16605PS')
            ->where('t.date', '<=', $date)
            ->sum('t.amount_amd');

        $val62 = DB::table('documents_journal as t')
            ->join('chart_of_accounts as da', 'da.id', '=', 't.debit_account_id')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 't.credit_account_id')
            ->whereNull('t.deleted_at')
            ->where('da.code', '16605PS')
            ->where('ca.code', '63015')
            ->where('t.date', '<=', $date)
            ->sum('t.amount_amd');

        $data['5.2'] = ['code' => '5.2', 'inflow' => 0, 'outflow' => 0, 'net' => $val52];
        if (isset($data['5.1'])) {
            $data['5.1']['net'] -= $val52;
        }

        $data['6.2'] = ['code' => '6.2', 'inflow' => 0, 'outflow' => 0, 'net' => $val62];
        if (isset($data['6.1'])) {
            $data['6.1']['net'] -= $val62;
        }

        return array_map(fn($r) => [
            'code' => $r['code'],
            'inflow' => $r['inflow'] / 1000,
            'outflow' => $r['outflow'] / 1000,
            'net' => $r['net'] / 1000,
        ], array_values($data));
    }


    public function buildTr(string $date): array
    {
        $debit = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.debit_account_id')
            ->whereNotNull('a.income_expense')
            ->where('t.date', '<=', $date)
            ->selectRaw("
            a.income_expense as code,
            SUM(CASE WHEN a.type IN ('active','expense','off_balance') THEN t.amount_amd ELSE 0 END) as inflow,
            SUM(CASE WHEN a.type IN ('passive','equity','income') THEN t.amount_amd ELSE 0 END) as outflow
        ")
            ->groupBy('a.income_expense');

        $credit = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.credit_account_id')
            ->whereNotNull('a.income_expense')
            ->where('t.date', '<=', $date)
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
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $data[(string)$r->code] = [
                'code'    => (string)$r->code,
                'inflow'  => (float)$r->inflow,
                'outflow' => (float)$r->outflow,
                'net'     => (float)$r->net,
            ];
        }

        $val52 = DB::table('transactions as t')
            ->join('chart_of_accounts as da', 'da.id', '=', 't.debit_account_id')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 't.credit_account_id')
            ->whereNull('t.deleted_at')
            ->where('da.code', '73015')
            ->where('ca.code', '16605PS')
            ->where('t.date', '<=', $date)
            ->sum('t.amount_amd');

        $val62 = DB::table('transactions as t')
            ->join('chart_of_accounts as da', 'da.id', '=', 't.debit_account_id')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 't.credit_account_id')
            ->whereNull('t.deleted_at')
            ->where('da.code', '16605PS')
            ->where('ca.code', '63015')
            ->where('t.date', '<=', $date)
            ->sum('t.amount_amd');

        $data['5.2'] = ['code' => '5.2', 'inflow' => 0, 'outflow' => 0, 'net' => $val52];
        if (isset($data['5.1'])) {
            $data['5.1']['net'] -= $val52;
        }

        $data['6.2'] = ['code' => '6.2', 'inflow' => 0, 'outflow' => 0, 'net' => $val62];
        if (isset($data['6.1'])) {
            $data['6.1']['net'] -= $val62;
        }

        return array_map(fn($r) => [
            'code'    => $r['code'],
            'inflow'  => $r['inflow'] / 1000,
            'outflow' => $r['outflow'] / 1000,
            'net'     => $r['net'] / 1000,
        ], array_values($data));
    }}



