<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
trait CalculatesAccountBalancesTrait
{
    protected function applyDateFilter($query, ?string $dateTo)
    {
        if ($dateTo) {
            $query->whereDate('t.date', '<=', $dateTo);
        }
        return $query;
    }

    protected function debitMovements(?string $dateTo)
    {
        $q = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
            ->whereNotNull('t.debit_account_id');

        $this->applyDateFilter($q, $dateTo);

        return $q->selectRaw("
                t.debit_account_id as account_id,
                SUM(CASE
                    WHEN a.type IN ('active','expense','off_balance') THEN  t.amount_amd
                    ELSE -t.amount_amd
                END) as delta
            ")
            ->groupBy('t.debit_account_id');
    }

    protected function creditMovements(?string $dateTo)
    {
        $q = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
            ->whereNotNull('t.credit_account_id');

        $this->applyDateFilter($q, $dateTo);

        return $q->selectRaw("
                t.credit_account_id as account_id,
                SUM(CASE
                    WHEN a.type IN ('active','expense','off_balance') THEN -t.amount_amd
                    ELSE  t.amount_amd
                END) as delta
            ")
            ->groupBy('t.credit_account_id');
    }


    protected function balancesSubquery(?string $dateTo)
    {
        $union = $this->debitMovements($dateTo)->unionAll(
            $this->creditMovements($dateTo)
        );

        return DB::query()
            ->fromSub($union, 'u')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 'u.account_id')
            ->select([
                'u.account_id',
                'ca.code',
                'ca.name',
                'ca.type',
                DB::raw('SUM(u.delta) as balance'),
            ])
            ->groupBy('u.account_id', 'ca.code', 'ca.name', 'ca.type');
    }


    protected function balancesRowsQuery(?string $dateTo)
    {
        return DB::query()
            ->fromSub($this->balancesSubquery($dateTo), 'b')
            ->selectRaw("
                b.account_id,
                b.code,
                b.name,
                b.type,
                b.balance as total_resident,
                0 as total_non_resident,
                (b.balance + 0) as total
            ")
            ->orderBy('b.code');
    }

    /**
     * Ամփոփ թվեր՝ ըստ տիպերի
     */
    protected function balancesSummary(?string $dateTo): array
    {
        $totals = DB::query()
            ->fromSub($this->balancesSubquery($dateTo), 'b')
            ->selectRaw("
                SUM(CASE WHEN b.type = 'active'  THEN b.balance ELSE 0 END) AS actives,
                SUM(CASE WHEN b.type = 'passive' THEN b.balance ELSE 0 END) AS liabilities,
                SUM(CASE WHEN b.type IN ('equity','income','expense','off_balance') THEN b.balance ELSE 0 END) AS capital
            ")
            ->first();

        $actives     = (float) ($totals->actives ?? 0);
        $liabilities = (float) ($totals->liabilities ?? 0);
        $capital     = (float) ($totals->capital ?? 0);

        return [
            'Ակտիվներ'         => $actives,
            'Պարտավորություններ' => $liabilities,
            'Կապիտալ'          => $capital,
            'Հաշվեշիռ'        => $actives - $liabilities - $capital,
        ];
    }
}
