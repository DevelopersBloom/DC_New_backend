<?php

namespace App\Services;

use App\Models\AccountingPeriodLock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PaymentPostingPolicyService
{
    public function resolvePostingDate(?string $requestedDate): string
    {
        $date = $requestedDate
            ? Carbon::parse($requestedDate, 'Asia/Yerevan')->toDateString()
            : Carbon::now('Asia/Yerevan')->toDateString();

        $today = Carbon::now('Asia/Yerevan')->toDateString();
        if ($date > $today) {
            throw ValidationException::withMessages([
                'posting_date' => 'Future posting date is not allowed.',
                'code' => 'FUTURE_POSTING_DATE',
            ]);
        }

        return $date;
    }

    public function assertBackdateAllowed(User $user, string $postingDate): void
    {
        $today = Carbon::now('Asia/Yerevan')->toDateString();
        if ($postingDate < $today && $user->role !== 'admin') {
            throw ValidationException::withMessages([
                'posting_date' => 'Only admins can post backdated payments.',
                'code' => 'BACKDATE_NOT_ALLOWED',
            ]);
        }
    }

    public function assertOpenPeriod(string $postingDate): void
    {
        $isClosed = AccountingPeriodLock::query()
            ->where('is_closed', true)
            ->whereDate('from_date', '<=', $postingDate)
            ->whereDate('to_date', '>=', $postingDate)
            ->exists();

        if ($isClosed) {
            throw ValidationException::withMessages([
                'posting_date' => 'Posting date is in a closed accounting period.',
                'code' => 'PERIOD_CLOSED',
            ]);
        }
    }
}
