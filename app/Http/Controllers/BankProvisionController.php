<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDailyBankProvision;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BankProvisionController extends Controller
{
//    public function run(Request $request): \Illuminate\Http\JsonResponse
//    {
//        try {
//            ProcessDailyBankProvision::dispatchSync();
//            return response()->json([
//                'success' => true,
//                'message' => 'Պահուստավորումը կատարվեց'
//            ]);
//        } catch (\Throwable $e) {
//            Log::error('Manual bank provision failed: ' . $e->getMessage());
//
//            return response()->json([
//                'success' => false,
//                'message' => 'Սխալ առաջացավ',
//            ], 500);
//        }
//    }
    public function run(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $from = Carbon::parse($request->date_from)->startOfDay();
        $to   = Carbon::parse($request->date_to)->startOfDay();

        $processed = [];
        $failed    = [];

        $current = $from->copy();
        while ($current->lte($to)) {
            $dateStr = $current->format('Y-m-d');
            try {
                ProcessDailyBankProvision::dispatchSync(0.01, $dateStr);
                $processed[] = $dateStr;
            } catch (\Throwable $e) {
                Log::error("Bank provision failed for {$dateStr}: " . $e->getMessage());
                $failed[] = [
                    'date'  => $dateStr,
                    'error' => $e->getMessage(),
                ];
            }
            $current->addDay();
        }

        return response()->json([
            'success'   => empty($failed),
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => empty($failed)
                ? 'Բոլոր օրերի պահուստավորումը կատարվեց'
                : 'Մի քանի օրերի պահուստավորումը ձախողվեց',
        ]);
    }
}
