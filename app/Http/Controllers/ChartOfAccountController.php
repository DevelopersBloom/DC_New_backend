<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChartOfAccountRequest;
use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChartOfAccountController
{
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
            ->select('id','parent_id','name','code','type')
            ->whereNull('parent_id')
            ->with('childrenRecursive')
            ->get();

        return response()->json($accounts);
    }

    public function show($id)
    {
        return ChartOfAccount::with('children')->findOrFail($id);
    }
//    public function store(Request $request)
//    {
//        $validated = $request->validate([
//            'code'           => 'required|string|max:20|unique:chart_of_accounts,code',
//            'name'           => 'required|string|max:255',
//            'type'           => 'required|in:active,passive,active-passive,off-balance',
//            'parent_id'      => 'nullable|exists:chart_of_accounts,id',
//        ]);
//
//        ChartOfAccount::create($validated);
//
//        return response()->json([
//            'message' => 'Chart of Accounts account created successfully'
//        ], 201);
//    }
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
                $q->where('code', 'like', $search . '%')
                    ->orWhere('name', 'like', $search . '%');
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function accountBalances(Request $request)
    {
        $dateTo   = $request->query('to');
        $perPage  = (int) $request->query('per_page', 15);
        $page     = (int) $request->query('page', 1);

        $dateFilter = function($q) use ($dateTo) {
            if ($dateTo) {
                $q->whereDate('t.date', '<=', $dateTo);
            }
        };

        $debit = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
            ->when($dateTo, $dateFilter)
            ->whereNotNull('t.debit_account_id')
            ->selectRaw("
            t.debit_account_id as account_id,
            SUM(CASE
                WHEN a.type IN ('active','expense','off_balance') THEN t.amount_amd
                ELSE -t.amount_amd
            END) as delta
        ")
            ->groupBy('t.debit_account_id');

        $credit = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
            ->when($dateTo, $dateFilter)
            ->whereNotNull('t.credit_account_id')
            ->selectRaw("
            t.credit_account_id as account_id,
            SUM(CASE
                WHEN a.type IN ('active','expense','off_balance') THEN  -t.amount_amd
                ELSE t.amount_amd
            END) as delta
        ")->groupBy('t.credit_account_id');

        $union = $debit->unionAll($credit);

        $balances = DB::query()
            ->fromSub($union, 'u')
            ->join('chart_of_accounts as ca', 'ca.id', '=', 'u.account_id')
            ->select([
                'u.account_id',
                'ca.code',
                'ca.name',
                'ca.type',
                DB::raw('SUM(u.delta) as balance'),
            ])
            ->groupBy('u.account_id', 'ca.code', 'ca.name', 'ca.type')
            ->orderBy('ca.code')
            ->paginate($perPage, ['*'], 'page', $page)
            ->appends(['to' => $dateTo, 'per_page' => $perPage]);

        return response()->json($balances);
    }


}
