<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDailyBankProvision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BankProvisionController extends Controller
{
    public function run(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            ProcessDailyBankProvision::dispatchSync();
            return response()->json([
                'success' => true,
                'message' => 'Պահուստավորումը կատարվեց'
            ]);
        } catch (\Throwable $e) {
            Log::error('Manual bank provision failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Սխալ առաջացավ',
            ], 500);
        }
    }
}
