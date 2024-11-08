<?php

namespace App\Http\Controllers;

use App\Models\CategoryRate;
use App\Models\LumpRate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
class RateController extends Controller
{
    public function getRates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'amount' => 'required|numeric|min:0',
        ]);

        $rate = CategoryRate::getRateByCategoryAndAmount($validated['category_id'], $validated['amount']);

        $lumpRate = LumpRate::getRateByCategoryAndAmount($validated['category_id'], $validated['amount']);
        // Prepare the response
        if ($rate || $lumpRate) {
            return response()->json([
                'success' => true,
                'interest_rate' => $rate ? $rate->interest_rate : null,
                'penalty' => $rate ? $rate->penalty : null,
                'lump_rate' => $lumpRate ? $lumpRate->lump_rate : null,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No rates found for the given category and amount.'
        ], 404);
    }
}
