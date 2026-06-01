<?php

namespace App\Http\Controllers;

use App\Exports\PartnerAccountBalancesExport;
use App\Http\Requests\StoreChartOfAccountRequest;
use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use App\Traits\CalculatesAccountBalancesTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ChartOfAccountController
{
    use CalculatesAccountBalancesTrait;

    public function index1(): JsonResponse
    {
        $accounts = ChartOfAccount::query()
            ->select('id','parent_id','name','code','type','income_expense')
            ->whereNull('parent_id')
            ->with('childrenRecursive')
            ->get();

        return response()->json($accounts);
    }
    public function index(): JsonResponse
    {
        $accounts = ChartOfAccount::query()
            ->select('id','parent_id','name','code','type','income_expense','risk_weight')
            ->whereNull('parent_id')
            ->get();

        return response()->json($accounts);
    }

    public function getChildren(Request $request): JsonResponse
    {
        $parentId = $request->query('id');

        $account = ChartOfAccount::query()
            ->select('id','parent_id','name','code','type','income_expense','risk_weight')
            ->where('id', $parentId)
            ->with(['children' => function($q) {
                $q->select('id','parent_id','name','code','type','income_expense','risk_weight');
            }])
            ->first();

        if (!$account) {
            return response()->json([]);
        }

        return response()->json($account->children);
    }
    public function show($id)
    {
        return ChartOfAccount::with('children')->findOrFail($id);
    }

    public function store(StoreChartOfAccountRequest $request)
    {
        ChartOfAccount::create($request->validated());

        return response()->json([
            'message' => 'Chart of Accounts account created successfully'
        ], 201);
    }
    public function update(Request $request, $id)
    {
        $account = ChartOfAccount::findOrFail($id);

        $validated = $request->validate([
            'code'           => 'required|string|max:20|unique:chart_of_accounts,code,' . $id,
            'name'           => 'required|string|max:255',
            'type'           => 'required|in:active,passive,equity,income,expense,off_balance',
//            'is_accumulative'=> 'boolean',
//            'currency_id'    => 'nullable|exists:currencies,id',
            'parent_id'      => 'nullable|exists:chart_of_accounts,id',
            'income_expense' => 'nullable|string',
            'risk_weight' => 'nullable|numeric'
        ]);

        if (isset($validated['parent_id']) && $validated['parent_id'] == $id) {
            return response()->json([
                'message' => 'An account cannot be its own parent.'
            ], 400);
        }

        $account->update($validated);

        return response()->json([
            'message' => 'Account successfully updated',
            'data' => $account
        ]);
    }


    public function destroy($id)
    {
        $account = ChartOfAccount::with('children')->findOrFail($id);

        if ($account->children()->count() > 0) {
            return response()->json([
                'message' => 'It cannot be deleted because it has subaccounts.'
            ], 400);
        }

        $account->delete();

        return response()->json([
            'message' => 'The account was successfully deleted.'
        ]);
    }

    public function searchAccount(Request $request)
    {
        $search = $request->query('code');
        $perPage = 15;

        $query = ChartOfAccount::query()
            ->select('id', 'parent_id', 'code', 'name', 'type')
            ->orderBy('code');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%');
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function accountBalances(Request $request): JsonResponse
    {
        $dateTo   = $request->query('to_date');
        $perPage  = (int) $request->query('per_page', 15);
        $page     = (int) $request->query('page', 1);

        $balances = $this->balancesSubquery($dateTo)
            ->orderBy('code')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['to' => $dateTo, 'per_page' => $perPage]);

        return response()->json($balances);
    }

    public function partnerAccountBalances(Request $request): JsonResponse
    {
        $dateTo   = $request->query('to_date');
        $perPage  = (int) $request->query('per_page', 15);
        $page     = (int) $request->query('page', 1);
        $partnerId = $request->query('partner_id');
        $accountId = $request->query('account_id');
        $search    = $request->query('search');

        $q = $this->partnerAccountBalancesRowsQuery($dateTo)
            ->when($partnerId, fn($qq) => $qq->where('b.partner_id', $partnerId))
            ->when($accountId, fn($qq) => $qq->where('b.account_id', $accountId))
            ->when($search, function ($qq) use ($search) {
                $qq->where(function($q2) use ($search) {
                    $q2->where('b.partner_name', 'like', "%{$search}%")
                        ->orWhere('b.partner_code', 'like', "%{$search}%")
                        ->orWhere('b.account_code', 'like', "%{$search}%")
                        ->orWhere('b.account_name', 'like', "%{$search}%");
                });
            });

        $pageData = $q->paginate($perPage, ['*'], 'page', $page)
            ->appends([
                'to' => $dateTo,
                'per_page' => $perPage,
                'partner_id' => $partnerId,
                'account_id' => $accountId,
                'search' => $search,
            ]);

        return response()->json($pageData);
    }
    public function remainingAmount(int $journalId)
    {
        $journal = DocumentJournal::with('journalable')->findOrFail($journalId);

        /** @var \App\Models\LoanNdm|null $loan */
        $loan = $journal->journalable instanceof \App\Models\LoanNdm
            ? $journal->journalable
            : \App\Models\LoanNdm::find($journal->journalable_id);

        if (!$loan) {
            return response()->json(['message' => 'Related LoanNdm not found'], 404);
        }

        $remaining = $journal->remainingCapacity();

        return response()->json([
            'loan_id'   => $loan->id,
            'journal_id'=> $journal->id,
            'amount'    => $remaining,
            'client' => $loan->client->type,
        ]);
    }

    /**
     * Compares 16605PC / 16605PS account-level balance
     * against the sum of all partner balances for those accounts.
     *
     * account_balance  = all transactions (same as /accounts/balances)
     * partners_total   = only transactions that have a partner_id
     * orphan_amount    = account_balance − partners_total
     *                    (transactions WITHOUT partner_id — should be 0)
     */
    public function reserveBalanceCheck(Request $request): JsonResponse
    {
        $dateTo = $request->query('to_date') ?? now()->toDateString();
        $codes  = ['16605PC', '16605PS'];

        // ── 1. Account-level balances (all transactions, same as /accounts/balances)
        $accountBalances = DB::query()
            ->fromSub($this->balancesSubquery($dateTo), 'ab')
            ->whereIn('ab.code', $codes)
            ->select(['ab.account_id', 'ab.code', 'ab.name', 'ab.type', 'ab.balance'])
            ->get()
            ->keyBy('code');

        // ── 3. Orphan transactions (no partner_id) per account ────────────────
        $accountIds = ChartOfAccount::whereIn('code', $codes)->pluck('id', 'code');

        $orphanRows = [];
        foreach ($codes as $code) {
            $accId = $accountIds[$code] ?? null;
            if (!$accId) continue;

            $rows = DB::table('transactions as t')
                ->whereNull('t.deleted_at')
                ->whereDate('t.date', '<=', $dateTo)
                ->where(function ($q) use ($accId) {
                    $q->orWhere(function ($q2) use ($accId) {
                        $q2->where('t.debit_account_id', $accId)
                           ->whereNull('t.debit_partner_id');
                    })->orWhere(function ($q2) use ($accId) {
                        $q2->where('t.credit_account_id', $accId)
                           ->whereNull('t.credit_partner_id');
                    });
                })
                ->select([
                    't.id',
                    't.date',
                    't.document_number',
                    't.document_type',
                    't.amount_amd',
                    't.debit_account_id',
                    't.debit_partner_id',
                    't.credit_account_id',
                    't.credit_partner_id',
                    't.comment',
                ])
                ->orderBy('t.date')
                ->get();

            $orphanRows[$code] = $rows;
        }

        // ── 4. Partner detail rows ────────────────────────────────────────────
        $partnerRows = DB::query()
            ->fromSub($this->partnerAccountBalancesSubquery($dateTo), 'pr')
            ->whereIn('pr.account_code', $codes)
            ->select([
                'pr.partner_id',
                'pr.partner_name',
                'pr.partner_code',
                'pr.partner_type',
                'pr.account_code',
                'pr.account_name',
                'pr.balance',
            ])
            ->orderBy('pr.partner_name')
            ->get()
            ->groupBy('account_code');

        // ── 5. Build result ───────────────────────────────────────────────────
        $result = [];
        foreach ($codes as $code) {
            $accountBalance = (float) ($accountBalances[$code]->balance ?? 0);
            $partnersTotal  = (float) ($partnerRows[$code] ?? collect())->sum('balance');
            $orphanAmount   = round($accountBalance - $partnersTotal, 2);

            $result[$code] = [
                'account_balance' => round($accountBalance, 2),
                'partners_total'  => round($partnersTotal, 2),
                'orphan_amount'   => $orphanAmount,          // transactions without partner_id
                'ok'              => abs($orphanAmount) <= 1,
                'orphan_transactions' => $orphanRows[$code] ?? [],
                'partners'            => $partnerRows[$code] ?? [],
            ];
        }

        return response()->json([
            'date' => $dateTo,
            'data' => $result,
        ]);
    }

    public function exportPartnerAccountBalances(Request $request)
    {
        $dateTo    = $request->query('to_date');
        $partnerId = $request->query('partner_id');
        $accountId = $request->query('account_id');
        $search    = $request->query('search');

        $q = $this->partnerAccountBalancesRowsQuery($dateTo)
            ->when($partnerId, fn($qq) => $qq->where('b.partner_id', $partnerId))
            ->when($accountId, fn($qq) => $qq->where('b.account_id', $accountId))
            ->when($search, function ($qq) use ($search) {
                $qq->where(function($q2) use ($search) {
                    $q2->where('b.partner_name', 'like', "%{$search}%")
                        ->orWhere('b.partner_code', 'like', "%{$search}%")
                        ->orWhere('b.account_code', 'like', "%{$search}%")
                        ->orWhere('b.account_name', 'like', "%{$search}%");
                });
            });

        $rows = $q->get();

        return Excel::download(new PartnerAccountBalancesExport($rows), 'partner_account_balances.xlsx');
    }
}
