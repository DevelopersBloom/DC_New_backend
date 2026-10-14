<?php

namespace App\Http\Controllers;

use App\Exports\AccountsBalanceReportExport;
use App\Exports\AccountsBalancesExport;
use App\Exports\TransactionsExport;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\DocumentJournal;
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
        $partnerIds     = $request->query('partner_ids', []);
        $comment        = trim((string) $request->query('comment', ''));

        // document_types[]=...: exact match on any of the selected types
        $documentTypes = collect((array) $request->query('document_types', []))
            ->filter(fn ($t) => is_string($t) && trim($t) !== '')
            ->map(fn ($t) => trim($t))
            ->unique()
            ->values()
            ->all();

        $sortMap = [
            'date'               => 'date',
            'document_number'    => 'document_number',
            'document_type'      => 'document_type',
            'amount_amd'         => 'amount_amd',
            'comment'            => 'comment',
            'disbursement_date'  => 'created_at',
            'debit_partner_name' => 'debit_partner_id',
            'credit_partner_name'=> 'credit_partner_id',
        ];
        $sortColumn = $sortMap[(string) $request->query('sort_by', '')] ?? 'id';
        $sortDir    = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DocumentJournal::select([
            'id',
            'date',
            'document_number',
            'document_type',
            'amount_amd',
            'amount_currency',
            'currency_id',
            'debit_account_id',
            'credit_account_id',
            'user_id',
            'debit_partner_id',
            'credit_partner_id',
            'comment',
            'created_at',
        ])
            ->with([
                'debitAccount:id,code,name',
                'creditAccount:id,code,name',
                'currency:id,code',
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

        if (!empty($documentTypes)) {
            $query->whereIn('document_type', $documentTypes);
        }

        if ($comment !== '') {
            $query->where('comment', 'LIKE', '%' . addcslashes($comment, '%_\\') . '%');
        }

        if (!empty($partnerIds)) {
            $ids = is_array($partnerIds) ? $partnerIds : [$partnerIds];
            $query->where(function ($q) use ($ids) {
                $q->whereIn('debit_partner_id', $ids)
                  ->orWhereIn('credit_partner_id', $ids);
            });
        }

        if ($partnerName) {
            $nameIds = Client::where(function ($q) use ($partnerName) {
                $q->where('company_name', 'LIKE', '%' . $partnerName . '%')
                  ->orWhereRaw("CONCAT(COALESCE(name,''), ' ', COALESCE(surname,'')) LIKE ?", ['%' . $partnerName . '%'])
                  ->orWhere('name', 'LIKE', '%' . $partnerName . '%')
                  ->orWhere('surname', 'LIKE', '%' . $partnerName . '%');
            })->pluck('id');

            $query->where(function ($q) use ($nameIds) {
                $q->whereIn('debit_partner_id', $nameIds)
                  ->orWhereIn('credit_partner_id', $nameIds);
            });
        }

        $query->orderBy($sortColumn, $sortDir)->orderByDesc('id');

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        // Footer total covers the whole filtered set, not just the current page.
        $amountSum = (float) (clone $query)->reorder()->sum('amount_amd');
        $page = $query->paginate($perPage);

        // Flat display fields next to the relations the old payload already carried.
        $page->getCollection()->transform(function (DocumentJournal $j) {
            $row = $j->toArray();
            $currencyCode = $j->currency?->code ?? 'AMD';

            $row['debit_partner_name']  = $this->partnerDisplayName($j->debitPartner);
            $row['debit_partner_code']  = $this->partnerCode($j->debitPartner);
            $row['credit_partner_name'] = $this->partnerDisplayName($j->creditPartner);
            $row['credit_partner_code'] = $this->partnerCode($j->creditPartner);
            // A journal row has a single currency, shared by both sides.
            $row['debit_currency_code']  = $currencyCode;
            $row['credit_currency_code'] = $currencyCode;
            $row['disbursement_date']    = optional($j->created_at)->format('Y-m-d H:i:s');

            return $row;
        });

        $payload = $page->toArray();
        $payload['amount_sum'] = $amountSum;

        return response()->json($payload);
    }

    private function partnerDisplayName(?Client $client): ?string
    {
        if (!$client) {
            return null;
        }

        $name = $client->type === 'legal'
            ? ($client->company_name ?? '')
            : trim(($client->name ?? '') . ' ' . ($client->surname ?? ''));

        return $name !== '' ? $name : null;
    }

    private function partnerCode(?Client $client): ?string
    {
        if (!$client) {
            return null;
        }

        return $client->type === 'individual'
            ? ($client->social_card_number ?? null)
            : ($client->tax_number ?? null);
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

        $filters = [
            'document_types'  => array_values(array_filter((array) $request->query('document_types', []), 'is_string')),
            'document_type'   => $request->query('document_type'),
            'document_number' => $request->query('document_number'),
            'partner_name'    => $request->query('partner_name'),
            'comment'         => $request->query('comment'),
        ];

        return Excel::download(new TransactionsExport($from, $to, $filters), 'transactions.xlsx');
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

