<?php

namespace Tests\Unit;

use App\Services\Payments\ScheduledPaymentHandler as H;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * T2 (and T4): interest_payment of a row is the interest of its whole period,
 * split into segments at every principal reduction inside the period.
 */
class PeriodInterestTest extends TestCase
{
    private const RATE = 0.0011; // 0.11 % per day

    /** 30-day row 2026-08-01 → 2026-08-31. */
    private function interest(float $opening, array $reductions, int $scheduledDays = 30, string $due = '2026-08-31'): float
    {
        return H::periodInterest(Carbon::parse('2026-08-01'), Carbon::parse($due), $scheduledDays, $opening, $reductions, self::RATE);
    }

    public function test_task_example_partial_payment_on_day_10(): void
    {
        // 1,000,000 for 10 days (11,000) + 900,000 for 20 days (19,800) = 30,800.
        $interest = $this->interest(900000, [['date' => '2026-08-11', 'amount' => 100000]]);

        $this->assertEqualsWithDelta(30800, $interest, 0.001);
        // What is still owed at the due date after the 11,000 collected on day 10.
        $this->assertEqualsWithDelta(19800, $interest - 11000, 0.001);
    }

    public function test_two_partial_payments_make_three_segments(): void
    {
        // 1,000,000 × 10 + 900,000 × 10 + 800,000 × 10.
        $interest = $this->interest(800000, [
            ['date' => '2026-08-11', 'amount' => 100000],
            ['date' => '2026-08-21', 'amount' => 100000],
        ]);

        $this->assertEqualsWithDelta((1000000 + 900000 + 800000) * 10 * self::RATE, $interest, 0.001);
    }

    public function test_row_not_started_is_balance_times_scheduled_days(): void
    {
        $this->assertEqualsWithDelta(900000 * 30 * self::RATE, $this->interest(900000, []), 0.001);
    }

    public function test_overdue_row_keeps_its_full_period(): void
    {
        // T4 example: paid 10 days after the due date → still 30 days, on the balance
        // as it was during the period (the late payment happened after it).
        $interest = $this->interest(800000, [['date' => '2026-09-10', 'amount' => 100000]]);

        $this->assertEqualsWithDelta(900000 * 30 * self::RATE, $interest, 0.001);
    }

    public function test_reductions_before_the_period_do_not_count(): void
    {
        $interest = $this->interest(900000, [['date' => '2026-07-15', 'amount' => 100000]]);

        $this->assertEqualsWithDelta(900000 * 30 * self::RATE, $interest, 0.001);
    }

    public function test_last_segment_takes_the_scheduled_days(): void
    {
        // The days column (31) differs from the calendar span (30): the extra day
        // goes to the last segment, so the total is still 31 days.
        $interest = $this->interest(900000, [['date' => '2026-08-11', 'amount' => 100000]], 31);

        $this->assertEqualsWithDelta(1000000 * 10 * self::RATE + 900000 * 21 * self::RATE, $interest, 0.001);
    }
}
