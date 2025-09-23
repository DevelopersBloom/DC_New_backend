<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChartOfAccountRequest;
use App\Models\ChartOfAccount;
use App\Traits\CalculatesAccountBalancesTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChartOfAccountController
{
    use CalculatesAccountBalancesTrait;
//    public function index()
//    {
//        $accounts = ChartOfAccount::whereNull('parent_id')
//            ->where('is_accumulative', true)
//            ->with('children')
//            ->get();
//
//        return response()->json($accounts);
//    }
    public function index(): JsonResponse
    {
        $accounts = ChartOfAccount::query()
            ->select('id','parent_id','name','code','type','income_expense')
            ->whereNull('parent_id')
            ->with('childrenRecursive')
            ->get();

        return response()->json($accounts);
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
            'type'           => 'required|in:active,passive,active-passive,off-balance',
//            'is_accumulative'=> 'boolean',
//            'currency_id'    => 'nullable|exists:currencies,id',
            'parent_id'      => 'nullable|exists:chart_of_accounts,id',
            'income_expense' => 'nullable|integer',
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
        $dateTo   = $request->query('to');
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
        $dateTo   = $request->query('to');
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
}
