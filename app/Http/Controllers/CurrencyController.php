<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use Illuminate\Http\JsonResponse;

class CurrencyController
{
    public function index(): JsonResponse
    {
        $currencies = Currency::select('id', 'code', 'name', 'symbol')->get();

        return response()->json([
            'success' => true,
            'data' => $currencies
        ]);
    }

}
