<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use Illuminate\Http\JsonResponse;

class PostingDatePolicy
{
    /**
     * Validate a posting date against all business-date rules.
     * Returns null on success, or a ready-made error JsonResponse on failure.
     */
    public function validate(string $date): ?JsonResponse
    {
        $today = now()->toDateString();

        if ($date > $today) {
            return response()->json([
                'success'    => false,
                'error_code' => 'FUTURE_DATE',
                'message'    => 'Posting date cannot be in the future.',
            ], 422);
        }

        if ($date < $today && !auth()->user()->hasRole('admin')) {
            return response()->json([
                'success'    => false,
                'error_code' => 'BACKDATE_NOT_ALLOWED',
                'message'    => 'Only administrators can backdate payments.',
            ], 403);
        }

        if (AccountingPeriod::isClosed($date)) {
            return response()->json([
                'success'    => false,
                'error_code' => 'PERIOD_CLOSED',
                'message'    => 'The accounting period for this date is closed.',
            ], 422);
        }

        return null;
    }
}
