<?php

namespace App\Http\Controllers;

use App\Exports\AccountsBalanceReportExport;
use App\Exports\AccountsBalancesExport;
use App\Exports\TransactionsExport;
use App\Models\ChartOfAccount;
use App\Models\Client;
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
        $from           = $request->query('from_date');
        $to             = $request->query('to_date');
        $documentType   = $request->query('document_type');
        $documentNumber = $request->query('document_number');
        $partnerName    = $request->query('partner_name');

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
        $query->byDocument($documentType, $documentNumber);

        if ($partnerName) {
            $partnerIds = Client::where(function ($q) use ($partnerName) {
                $q->where('company_name', 'LIKE', '%' . $partnerName . '%')
                  ->orWhereRaw("CONCAT(COALESCE(name,''), ' ', COALESCE(surname,'')) LIKE ?", ['%' . $partnerName . '%'])
                  ->orWhere('name', 'LIKE', '%' . $partnerName . '%')
                  ->orWhere('surname', 'LIKE', '%' . $partnerName . '%');
            })->pluck('id');

            $query->where(function ($q) use ($partnerIds) {
                $q->whereIn('debit_partner_id', $partnerIds)
                  ->orWhereIn('credit_partner_id', $partnerIds);
            });
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $transactions = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json($transactions);
    }
    public function getAccountTransactions(Request $request)
    {
        $from        = $request->query('from_date');
        $to          = $request->query('to_date');
        $accountCode = $request->query('account');

        $account = ChartOfAccount::where('code', $accountCode)->first();

        if (!$account) {
            return response()->json(['error' => 'Հաշիվը չի գտնվել'], 404);
        }

        $accountId = $account->id;

        $query = Transaction::query()->select([
            'id',
            'amount_amd',
            'debit_account_id',
            'credit_account_id'
        ]);

        $query->where(function ($q) use ($accountId) {
            $q->where('debit_account_id', $accountId)
                ->orWhere('credit_account_id', $accountId);
        });

        if ($from && $to) {
            $query->whereBetween('date', [$from, $to]);
        } elseif ($from) {
            $query->where('date', '>=', $from);
        } elseif ($to) {
            $query->where('date', '<=', $to);
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $transactionsPagination = $query->orderBy('id', 'desc')->paginate($perPage);

        $items = $transactionsPagination->getCollection();

        $debits = $items->where('debit_account_id', $accountId)->values()->map(function($item) {
            return ['id' => $item->id, 'amount' => $item->amount_amd];
        });

        $credits = $items->where('credit_account_id', $accountId)->values()->map(function($item) {
            return ['id' => $item->id, 'amount' => $item->amount_amd];
        });

        $response = $transactionsPagination->toArray();
        $response['data'] = [
            'debits' => $debits,
            'credits' => $credits
        ];

        return response()->json($response);
    }    public function exportAccountBalanceReport(Request $request)
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');

        return Excel::download(
            new AccountsBalanceReportExport($from, $to),
            'ՀաշիվներիՄնացորդ.xlsx'
        );
    }
    public function exportAccountBalances(Request $request)
    {
        $to = $request->query('to_date') ?? now()->format('Y-m-d');
        return Excel::download(new AccountsBalancesExport($to), 'account_balances.xlsx');
    }
    public function export(Request $request)
    {
        $from = $request->query('from_date');
        $to = $request->query('to_date');

        return Excel::download(new TransactionsExport($from, $to), 'transactions.xlsx');
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

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;
        $transactions = $query->orderBy('id', 'desc')->paginate($perPage);

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

}

