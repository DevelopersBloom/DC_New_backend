<?php

namespace App\Http\Controllers;

use App\Services\Reports\CreditActivityReportService;
use Illuminate\Http\JsonResponse;

class CreditActivityReportController extends Controller
{
    /**
     * GET /api/reports/credit-activity/{month}/{year}
     * Position (stock) and period activity (flow) summary for the /reports page, scoped to the user's pawnshop.
     */
    public function __invoke(int $month, int $year, CreditActivityReportService $service): JsonResponse
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json(['message' => 'Invalid month or year'], 422);
        }

        return response()->json(['data' => $service->build((int) auth()->user()->pawnshop_id, $month, $year)]);
    }
}
