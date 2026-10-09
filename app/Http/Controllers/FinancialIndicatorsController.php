<?php

namespace App\Http\Controllers;

use App\Services\Reports\CreditActivityReportService;
use App\Services\Reports\FinancialIndicatorsService;
use Illuminate\Http\JsonResponse;

class FinancialIndicatorsController extends Controller
{
    /**
     * GET /api/reports/financial-indicators/{month}/{year}
     * Daily position (stock) and activity (flow) table of the /reports "Ֆինանսական ցուցանիշներ" tab, scoped to the
     * user's pawnshop. The same dataset feeds the on-screen table and its Excel export.
     */
    public function __invoke(int $month, int $year, CreditActivityReportService $base): JsonResponse
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json(['message' => 'Invalid month or year'], 422);
        }

        return response()->json(['data' => (new FinancialIndicatorsService($base))->build((int) auth()->user()->pawnshop_id, $month, $year)]);
    }
}
