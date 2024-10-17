<?php

namespace App\Http\Controllers;

use App\Models\CategoryRate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
class CategoryRateController extends Controller
{
    public function getRates(Request $request): JsonResponse
    {
        $validated  = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'amount' => 'required|numeric|min:0',
        ]);
        $rate = CategoryRate::getRateByCategoryAndAmount($validated['category_id'], $validated['amount']);
        if ($rate) {
            return response()->json([
                'success' => true,
                'interest_rate' => $rate->interest_rate,
                'penalty' => $rate->penalty,
                'lump_rate' => $rate->lump_rate
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No rates found for the given category and amount.'
        ], 404);
    }
}
