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

        $this->notTrashed($q, 't');
        $this->notTrashed($q, 'a');
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
        $this->notTrashed($q, 't');
        $this->notTrashed($q, 'a');

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

        $query = DB::query()
            ->fromSub($union, 'u')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 'u.account_id')
            ->whereNull('ca.deleted_at')
            ->select([
                'u.account_id',
                'ca.code',
                'ca.name',
                'ca.type',
                DB::raw('SUM(u.delta) as balance'),
            ])
            ->groupBy('u.account_id', 'ca.code', 'ca.name', 'ca.type');

        if ($dateTo) {
            $query->whereExists(function ($q) use ($dateTo) {
                $q->select(DB::raw(1))
                    ->from('transactions as t2')
                    ->whereRaw('(t2.debit_account_id = ca.id OR t2.credit_account_id = ca.id)')
                    ->whereNull('t2.deleted_at')
                    ->whereDate('t2.date', '<=', $dateTo);
            });
        }

        return $query;
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

            b.balance AS amd_resident,
            0         AS amd_non_resident,
            0         AS fx_group1_resident,
            0         AS fx_group1_non_resident,
            0         AS usd_resident,
            0         AS usd_non_resident,
            0         AS eur_resident,
            0         AS eur_non_resident,
            0         AS fx_group2_resident,
            0         AS fx_group2_non_resident,
            0         AS rub_resident,
            0         AS rub_non_resident,

            0         AS total_non_resident,
            b.balance AS total
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

    protected function partnerAccountDebitMovements(?string $dateTo)
    {
        $q = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
            ->whereNotNull('t.debit_account_id')
            ->whereNotNull('t.debit_partner_id');


        if ($dateTo) {
            $q->whereDate('t.date','<=',$dateTo);
        }
        $this->notTrashed($q,'t');
        $this->notTrashed($q,'a');

        return $q->selectRaw("
                t.debit_partner_id as partner_id,
                t.debit_account_id as account_id,
                SUM(
                    CASE WHEN a.type IN ('active','expense','off_balance')
                         THEN t.amount_amd
                         ELSE -t.amount_amd
                    END
                ) as delta
            ")
            ->groupBy('t.debit_partner_id','t.debit_account_id');
    }

    protected function partnerAccountCreditMovements(?string $dateTo)
    {
        $q = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
            ->whereNotNull('t.credit_account_id')
            ->whereNotNull('t.credit_partner_id');

        if ($dateTo) {
            $q->whereDate('t.date','<=',$dateTo);
        }
        $this->notTrashed($q,'t');
        $this->notTrashed($q,'a');

        return $q->selectRaw("
                t.credit_partner_id as partner_id,
                t.credit_account_id as account_id,
                SUM(
                    CASE WHEN a.type IN ('active','expense','off_balance')
                         THEN -t.amount_amd
                         ELSE  t.amount_amd
                    END
                ) as delta
            ")
            ->groupBy('t.credit_partner_id','t.credit_account_id');
    }



    protected function partnerAccountBalancesSubquery(?string $dateTo)
    {
        $union = $this->partnerAccountDebitMovements($dateTo)->unionAll(
            $this->partnerAccountCreditMovements($dateTo)
        );

        return DB::query()
            ->fromSub($union, 'u')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 'u.account_id')
            ->leftJoin('clients as c', 'c.id', '=', 'u.partner_id')
            ->select([
                'u.partner_id',
                'u.account_id',
                'ca.code as account_code',
                'ca.name as account_name',
                'ca.type as type',

                DB::raw("MAX(c.type) as partner_type"),
                DB::raw("MAX(CASE WHEN c.type = 'individual' THEN c.social_card_number ELSE c.tax_number END) as partner_code"),
                DB::raw("MAX(CASE WHEN c.type = 'legal' THEN COALESCE(c.company_name,'')
                              ELSE TRIM(CONCAT(COALESCE(c.name,''),' ',COALESCE(c.surname,''))) END) as partner_name"),

                DB::raw('SUM(u.delta) as balance'),
            ])
            ->groupBy('u.partner_id','u.account_id','ca.code','ca.name','ca.type');
    }

    protected function partnerAccountBalancesRowsQuery(?string $dateTo)
    {
        return DB::query()
            ->fromSub($this->partnerAccountBalancesSubquery($dateTo), 'b')
            ->select([
                'b.partner_id',
                'b.partner_code',
                'b.partner_name',
                'b.partner_type',
                'b.account_id',
                'b.account_code',
                'b.account_name',
                'b.type',
                'b.balance',
            ])
            ->orderBy('b.partner_name')
            ->orderBy('b.account_code');
    }
    protected function notTrashed($q, string $alias): void
    {
        $q->whereNull("$alias.deleted_at");
    }

    private function getClientAccountsBalance(int $clientId, array $accountIds, ?string $date = null): float
    {
        if (empty($accountIds)) {
            return 0.0;
        }

        $debit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $clientId)
            ->whereIn('debit_account_id', $accountIds)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        $credit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $clientId)
            ->whereIn('credit_account_id', $accountIds)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        return (float)$debit - (float)$credit;
    }

    /**
     * Get balance of a single account for a given partner.
     */
    private function getClientAccountBalance(int $clientId, ?int $accountId, $date=null): float
    {
        if (!$accountId) {
            return 0.0;
        }

        $debit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $clientId)
            ->where('debit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        $credit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $clientId)
            ->where('credit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        return (float)$debit - (float)$credit;
    }

    private function getClientReserveBalance(int $clientId, ?int $accountId, $date=null): float
    {
        if (!$accountId) {
            return 0.0;
        }

        $credit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $clientId)
            ->where('credit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        $debit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $clientId)
            ->where('debit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        return (float)$debit - (float)$credit;
    }
}

