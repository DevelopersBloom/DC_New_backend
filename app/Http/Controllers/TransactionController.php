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

    public function export(Request $request)
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        return Excel::download(
            new TransactionsExport($from, $to),
            'ԳործառնություններիՄատյան.xlsx'
        );
    }

//    public function loanNdmJournal(Request $request): JsonResponse
//    {
//        $from = $request->query('from_date');
//        $to   = $request->query('to_date');
//
//        $query = Transaction::with([
//            'amountCurrencyRelation:id,code',
//            'user:id,name,surname',
//        ])
//            ->where('document_type', Transaction::LOAN_NDM_TYPE)
//            ->select([
//                'id',
//                'date',
//                'document_number',
//                'document_type',
//                'amount_amd',
//                'amount_currency_id',
//                'debit_partner_code',
//                'debit_partner_name',
//                'comment',
//                'user_id',
//                'disbursement_date',
//            ]) ->with([
//                'user:id,name,surname'
//            ]);;
//
//        if ($from && $to) {
//            $query->whereBetween('date', [$from, $to]);
//        } elseif ($from) {
//            $query->where('date', '>=', $from);
//        } elseif ($to) {
//            $query->where('date', '<=', $to);
//        }
//
//        $transactions = $query->orderBy('date', 'desc')->paginate(20);
//
//        return response()->json($transactions);
//    }
    use App\Models\LoanNdm;
    use App\Models\Transaction; // միայն type constant-ի համար, եթե պահում ես այստեղ
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;

    public function loanNdmJournal(Request $request): JsonResponse
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        $query = LoanNdm::with([
            'client:id,type,name,surname,company_name,social_card_number,tax_number',
            'currency:id,code',
            'user:id,name,surname',
        ])
            ->when($from && $to, fn($q) => $q->whereBetween('contract_date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('contract_date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('contract_date', '<=', $to))
            ->orderBy('contract_date', 'desc');

        $page = $query->paginate(20);

        $page->getCollection()->transform(function (LoanNdm $ndm) {
            $client = $ndm->client;
            $partnerName = $client
                ? ($client->type === 'legal'
                    ? ($client->company_name ?? '')
                    : trim(($client->name ?? '').' '.($client->surname ?? '')))
                : null;

            $partnerCode = $client
                ? ($client->type === 'individual'
                    ? ($client->social_card_number ?? null)
                    : ($client->tax_number ?? null))
                : null;

            return [
                'id'                  => $ndm->id,
                'date'                => optional($ndm->contract_date)->format('Y-m-d'),
                'document_number'     => $ndm->contract_number,
                'document_type'       => Transaction::LOAN_NDM_TYPE, // կամ 'loan_nd
                'amount_amd'          => $ndm->amount,
                'amount_currency_id'  => $ndm->currency_id,
                'debit_partner_code'  => $partnerCode,
                'debit_partner_name'  => $partnerName,
                'comment'             => $ndm->comment ?? null,
                'user_id'             => $ndm->user_id ?? null,
                'disbursement_date'   => optional($ndm->disbursement_date)->format('Y-m-d'),

                'currency'            => $ndm->currency ? ['id' => $ndm->currency->id, 'code' => $ndm->currency->code] : null,
                'user'                => $ndm->user ? ['id' => $ndm->user->id, 'name' => $ndm->user->name, 'surname' => $ndm->user->surname] : null,
            ];
        });

        return response()->json($page);
    }

    public function exportLoanNdmJournal(Request $request)
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        return Excel::download(
            new LoanNdmJournalExport($from, $to),
            'ՓաստաթղթերիՄատյան.xlsx'
        );
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

