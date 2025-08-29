<?php

namespace App\Http\Controllers;

use App\Models\PostingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PostingRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = PostingRule::with(['businessEvent', 'debitAccount', 'creditAccount'])->get();
        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_event_id' => 'required|exists:business_events,id',
            'debit_account_id' => 'required|exists:chart_of_accounts,id',
            'credit_account_id' => 'required|exists:chart_of_accounts,id',
        ]);

        $rule = PostingRule::create($data);
        return response()->json($rule, 201);
    }

    public function show(PostingRule $postingRule): JsonResponse
    {
        return response()->json($postingRule->load(['businessEvent', 'debitAccount', 'creditAccount']));
    }

    public function update(Request $request, PostingRule $postingRule): JsonResponse
    {
        $data = $request->validate([
            'business_event_id' => 'sometimes|exists:business_events,id',
            'debit_account_id' => 'sometimes|exists:chart_of_accounts,id',
            'credit_account_id' => 'sometimes|exists:chart_of_accounts,id',
            'description' => 'nullable|string',
        ]);

        $postingRule->update($data);
        return response()->json($postingRule);
    }

    public function destroy(PostingRule $postingRule): JsonResponse
    {
        $postingRule->delete();
        return response()->json(null, 204);
    }
}
