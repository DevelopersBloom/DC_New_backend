<?php

namespace App\Http\Controllers;

use App\Exports\LoanNdmJournalExport;
use App\Exports\ReportsJournalExport;
use App\Exports\TransactionsExport;
use App\Models\LoanNdm;
use App\Models\Transaction;
use App\Traits\CalculatesAccountBalancesTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TransactionController
{
    use CalculatesAccountBalancesTrait;
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
            'is_system',
            'debit_partner_id',
            'credit_partner_id',
        ])
            ->with([
                'debitAccount:id,code,name',
                'debitCurrency:id,code',
                'creditAccount:id,code,name',
                'creditCurrency:id,code',
                'amountCurrencyRelation:id,code',
                'user:id,name,surname',
                'debitPartner:id,type,name,surname,company_name,tax_number,social_card_number',
                'creditPartner:id,type,name,surname,company_name,tax_number,social_card_number',
            ]);

        if ($from && $to) {
            $query->whereBetween('date', [$from, $to]);
        } elseif ($from) {
            $query->where('date', '>=', $from);
        } elseif ($to) {
            $query->where('date', '<=', $to);
        }

        $transactions = $query->orderBy('date', 'desc')->paginate(20);

        return response()->json($transactions);
    }

    public function export(Request $request)
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        return Excel::download(
            new TransactionsExport($from, $to),
            'ԳործառնություններիՄատյան.xlsx'
        );
    }

    public function loanNdmJournal1(Request $request): JsonResponse
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        $query = Transaction::with([
            'amountCurrencyRelation:id,code',
            'user:id,name,surname',
        ])
            ->where('document_type', Transaction::LOAN_NDM_TYPE)
            ->select([
                'id',
                'date',
                'document_number',
                'document_type',
                'amount_amd',
                'amount_currency_id',
                'debit_partner_code',
                'debit_partner_name',
                'comment',
                'user_id',
                'disbursement_date',
            ]) ->with([
                'user:id,name,surname'
            ]);;

        if ($from && $to) {
            $query->whereBetween('date', [$from, $to]);
        } elseif ($from) {
            $query->where('date', '>=', $from);
        } elseif ($to) {
            $query->where('date', '<=', $to);
        }

        $transactions = $query->orderBy('date', 'desc')->paginate(20);

        return response()->json($transactions);
    }


    public function reportsJournal(Request $request): JsonResponse
    {
        $dateTo   = $request->query('to');
        $perPage  = (int) $request->query('per_page', 15);
        $page     = (int) $request->query('page', 1);

        $rows = $this->balancesRowsQuery($dateTo)
            ->paginate($perPage, ['*'], 'page', $page);

        $summary = $this->balancesSummary($dateTo);

        return response()->json([
            'data'    => $rows->items(),
            'summary' => $summary,
        ]);
    }
    public function exportReportsJournal(Request $request)
    {
        $to = $request->query('to');

        $filename = 'Հաշվետվություն' . ($to ? "_to_{$to}" : '') . '.xlsx';

        return Excel::download(new ReportsJournalExport($to), $filename);
    }
}

