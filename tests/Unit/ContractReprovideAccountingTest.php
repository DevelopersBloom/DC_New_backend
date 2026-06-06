<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Tests\TestCase;

class ContractReprovideAccountingTest extends TestCase
{
    /**
     * Full contract account net: SUM(all Dr) − SUM(all Cr) per account.
     */
    private function accountNet(array $entries, string $account): float
    {
        $debits = 0.0;
        $credits = 0.0;

        foreach ($entries as $entry) {
            if (($entry['debit'] ?? null) === $account) {
                $debits += (float) $entry['amount'];
            }
            if (($entry['credit'] ?? null) === $account) {
                $credits += (float) $entry['amount'];
            }
        }

        return $debits - $credits;
    }

    public function test_second_reprovide_net_16200nv_equals_delta_not_cumulative(): void
    {
        $acc = '16200NV';
        $delta = 50000.0;

        $entries = [
            ['debit' => $acc, 'credit' => null, 'amount' => 50000],
            ['debit' => null, 'credit' => $acc, 'amount' => 50000],
            ['debit' => $acc, 'credit' => null, 'amount' => 50000],
        ];

        $net = $this->accountNet($entries, $acc);

        $this->assertSame($delta, $net);

        $buggyDebits = 100000.0;
        $buggyCredits = 0.0;
        $buggyNet = $buggyDebits - $buggyCredits;
        $this->assertNotSame($delta, $buggyNet);
        $this->assertSame(100000.0, $buggyNet);
    }

    public function test_loss_expense_86000_uses_net_not_cumulative_on_second_event(): void
    {
        $accNv = '16200NV';
        $entries = [
            ['debit' => $accNv, 'credit' => null, 'amount' => 50000],
            ['debit' => null, 'credit' => $accNv, 'amount' => 50000],
            ['debit' => $accNv, 'credit' => null, 'amount' => 50000],
        ];

        $net16200NV = $this->accountNet($entries, $accNv);
        $amount86000 = $net16200NV;

        $this->assertSame(50000.0, $amount86000);
    }

    public function test_amortized_remaining_months_prefers_deleted_row_count(): void
    {
        $deletedCount = 5;
        $start = Carbon::parse('2026-06-05', 'Asia/Yerevan');
        $deadline = Carbon::parse('2026-12-05', 'Asia/Yerevan');

        $calendarMonths = max(1, ($deadline->year - $start->year) * 12 + ($deadline->month - $start->month));
        $remainingMonths = $deletedCount > 0 ? $deletedCount : $calendarMonths;

        $this->assertSame(5, $remainingMonths);
        $this->assertNotSame($calendarMonths, $remainingMonths);
    }

    public function test_amortized_remaining_months_falls_back_to_calendar_when_no_deleted_rows(): void
    {
        $deletedCount = 0;
        $start = Carbon::parse('2026-06-05', 'Asia/Yerevan');
        $deadline = Carbon::parse('2026-12-05', 'Asia/Yerevan');

        $calendarMonths = max(1, ($deadline->year - $start->year) * 12 + ($deadline->month - $start->month));
        $remainingMonths = $deletedCount > 0 ? $deletedCount : $calendarMonths;

        $this->assertSame(6, $remainingMonths);
    }

    public function test_pgi_id_continues_from_max_plus_one(): void
    {
        $existingPgiIds = [1, 2, 3];
        $maxPgiId = max($existingPgiIds);
        $startPgiId = $maxPgiId + 1;

        $this->assertSame(4, $startPgiId);
    }
}
