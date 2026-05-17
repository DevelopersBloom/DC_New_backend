<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostingRuleResource;
use App\Models\PostingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostingRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = PostingRule::select('id','business_event_filter','debit_account_id','credit_account_id','debit_partner_id','credit_partner_id')
            ->with([
                'debitAccount:id,code,name',
                'creditAccount:id,code,name',
                'debitPartner:id,name,surname',
                'creditPartner:id,name,surname',
            ])
            ->get();
        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_event_filter' => 'required',
            'debit_account_id' => 'required|exists:chart_of_accounts,id',
            'credit_account_id' => 'required|exists:chart_of_accounts,id',
            'debit_partner_id' => 'nullable|exists:clients,id',
            'credit_partner_id' => 'nullable|exists:clients,id',
        ]);

        $rule = PostingRule::create($data);
        return response()->json($rule, 201);
    }

    public function show(PostingRule $postingRule): JsonResponse
    {
        $postingRule->load(['debitAccount', 'creditAccount', 'debitPartner', 'creditPartner']);

        return response()->json(new PostingRuleResource($postingRule));
    }


    public function update(Request $request, PostingRule $postingRule): JsonResponse
    {
        $data = $request->validate([
            'business_event_filter' => 'sometimes',
            'debit_account_id' => 'sometimes|exists:chart_of_accounts,id',
            'credit_account_id' => 'sometimes|exists:chart_of_accounts,id',
            'debit_partner_id' => 'nullable|exists:clients,id',
            'credit_partner_id' => 'nullable|exists:clients,id',
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
