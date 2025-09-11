<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Transaction;

class BalanceService
{
    public function getBalanceByDate(string $date)
    {
        $debits = Transaction::query()
            ->upToDate($date)
            ->selectRaw('debit_account_id as account_id,SUM(amount_amd) as total_debit')
            ->groupBy('debit_account_id');

        $credits = Transaction::query()
            ->upToDate($date)
            ->selectRaw('credit_account_id as account_id, SUM(amount_amd) as total_credit')
            ->groupBy('credit_account_id');
        $rows = ChartOfAccount::query()
            ->leftJoinSub()
    }

}
