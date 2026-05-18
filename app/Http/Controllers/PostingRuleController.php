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
        $rules = PostingRule::select('id','business_event_filter','debit_account_id','credit_account_id','debit_partner','credit_partner')
            ->with([
                'debitAccount:id,code,name',
                'creditAccount:id,code,name',
            ])
            ->get();
        return response()->json($rules);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_event_filter' => 'required|string',
            'debit_account_id'      => 'nullable|exists:chart_of_accounts,id',
            'credit_account_id'     => 'nullable|exists:chart_of_accounts,id',
            'debit_partner'         => 'nullable|string|max:255',
            'credit_partner'        => 'nullable|string|max:255',
        ]);

        $rule = PostingRule::create($data);
        return response()->json($rule, 201);
    }

    public function show(PostingRule $postingRule): JsonResponse
    {
        $postingRule->load(['debitAccount', 'creditAccount']);

        return response()->json(new PostingRuleResource($postingRule));
    }


    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'rules'        => 'required|array',
            'rules.*.id'   => 'required|exists:posting_rules,id',
        ]);

        foreach ($request->rules as $item) {
            PostingRule::find($item['id'])->update([
                'debit_account_id'  => $item['debit_account_id'] ?? null,
                'credit_account_id' => $item['credit_account_id'] ?? null,
                'debit_partner'     => $item['debit_partner'] ?? null,
                'credit_partner'    => $item['credit_partner'] ?? null,
            ]);
        }

        return response()->json(PostingRule::with(['debitAccount:id,code,name', 'creditAccount:id,code,name'])->get());
    }

    public function destroy(PostingRule $postingRule): JsonResponse
    {
        $postingRule->delete();
        return response()->json(null, 204);
    }
}
