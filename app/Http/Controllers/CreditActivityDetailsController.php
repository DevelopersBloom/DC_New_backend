<?php

namespace App\Http\Controllers;

use App\Services\Reports\CreditActivityReportService;
use App\Services\Reports\PortfolioManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditActivityDetailsController extends Controller
{
    /**
     * GET /api/reports/credit-activity/details?type=&month=&year=&page=&per_page=&sort=&dir=&search=
     * Contracts behind a management indicator. Type and sort field are allow-listed; always scoped to the user's pawnshop.
     */
    public function __invoke(Request $request, CreditActivityReportService $service): JsonResponse
    {
        $v = $request->validate([
            'type' => ['required', Rule::in(array_keys(PortfolioManagementService::DETAIL_TYPES))],
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort' => ['nullable', Rule::in(array_keys(PortfolioManagementService::SORT_FIELDS))],
            'dir' => 'nullable|in:asc,desc',
            'search' => 'nullable|string|max:60',
        ]);

        // Customer names follow the contracts-list rule: only users who may view contracts see them.
        $canSeeNames = (bool) $request->user()->can('view_contracts');
        if (!$canSeeNames) {
            unset($v['search']);   // never let search reveal names the user may not see
        }

        $data = $service->details((int) $request->user()->pawnshop_id, (int) $v['month'], (int) $v['year'], $v['type'], $v);
        if (!$canSeeNames) {
            $data['items'] = array_map(fn ($i) => array_merge($i, ['customer' => null]), $data['items']);
        }

        return response()->json(['data' => $data]);
    }
}
