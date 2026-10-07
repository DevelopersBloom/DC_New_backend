<?php

namespace App\Http\Controllers;

use App\Services\Reports\PortfolioQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortfolioQualityDetailsController extends Controller
{
    /** GET /api/reports/portfolio-quality/details?type=&month=&year=&page=&per_page=&sort=&dir=&search= */
    public function __invoke(Request $request, PortfolioQualityService $service): JsonResponse
    {
        $v = $request->validate([
            'type' => ['required', Rule::in(array_keys(PortfolioQualityService::DETAIL_TYPES))],
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort' => ['nullable', Rule::in(array_keys(PortfolioQualityService::SORT_FIELDS))],
            'dir' => 'nullable|in:asc,desc',
            'search' => 'nullable|string|max:60',
        ]);

        // Customer names only for users who may view contracts (same rule as the other drill-downs).
        $canSeeNames = (bool) $request->user()->can('view_contracts');
        if (!$canSeeNames) {
            unset($v['search']);
        }
        $data = $service->details((int) $request->user()->pawnshop_id, (int) $v['month'], (int) $v['year'], $v['type'], $v);
        if (!$canSeeNames) {
            $data['items'] = array_map(fn ($i) => array_merge($i, ['customer' => null]), $data['items']);
        }
        return response()->json(['data' => $data]);
    }
}
