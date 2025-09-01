<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->whereNull('parent_id')
            ->with('childrenRecursive')
            ->select('id','parent_id','name','code','type');

        return response()->json($accounts);
    }

    public function show($id)
    {
        return ChartOfAccount::with('children')->findOrFail($id);
    }
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'           => 'required|string|max:20|unique:chart_of_accounts,code',
            'name'           => 'required|string|max:255',
            'type'           => 'required|in:active,passive,active-passive,off-balance',
//            'is_accumulative'=> 'boolean',
//            'currency_id'    => 'nullable|exists:currencies,id',
            'parent_id'      => 'nullable|exists:chart_of_accounts,id',
        ]);

        ChartOfAccount::create($validated);

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


}
