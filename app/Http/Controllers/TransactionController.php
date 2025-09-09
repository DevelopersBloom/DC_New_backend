<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController
{
    public function index(Request $request)
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        $query = Transaction::select([
            'id',
            'date',
            'document_number',
            'document_type',
            'amount_amd',
            'amount_currency',
            'amount_currency_id',
            'debit_account_id',
            'credit_account_id',
            'user_id',
            'debit_currency_id',
            'credit_currency_id',
            'is_system'
        ])
            ->with([
                'debitAccount:id,code,name',
                'debitCurrency:id,code',
                'creditAccount:id,code,name',
                'creditCurrency:id,code',
                'amountCurrencyRelation:id,code',
                'user:id,name,surname'
            ]);

        if ($from && $to) {
            $query->whereBetween('date', [$from, $to]);
        } elseif ($from) {
            $query->where('date', '>=', $from);
        } elseif ($to) {
            $query->where('date', '<=', $to);
        }

        $transactions = $query->orderBy('date','desc')->paginate(20);

        return response()->json($transactions);
    }
}
