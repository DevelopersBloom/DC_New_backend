<?php

namespace App\Http\Controllers;

use App\Services\Reports\PortfolioQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioQualityController extends Controller
{
    /** GET /api/reports/portfolio-quality/{month}/{year}?months=6|12 — delinquency, PAR and collections (lazy-loaded by the UI). */
    public function __invoke(int $month, int $year, Request $request, PortfolioQualityService $service): JsonResponse
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return response()->json(['message' => 'Invalid month or year'], 422);
        }
        return response()->json(['data' => $service->build((int) $request->user()->pawnshop_id, $month, $year, (int) $request->query('months', 6))]);
    }
}
