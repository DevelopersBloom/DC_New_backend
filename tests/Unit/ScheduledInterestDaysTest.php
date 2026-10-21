<?php

namespace Tests\Unit;

use App\Services\Payments\ScheduledPaymentHandler;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * T4: an overdue row keeps the interest of its whole scheduled period when the
 * schedule is recalculated, instead of only the days since it became overdue.
 */
class ScheduledInterestDaysTest extends TestCase
{
    private ScheduledPaymentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        // interestDays() is pure; skip the service's injected dependencies.
        $this->handler = (new \ReflectionClass(ScheduledPaymentHandler::class))->newInstanceWithoutConstructor();
    }

    /** Row 2026-08-01 → 2026-08-31, 30 scheduled days. */
    private function days(string $now, int $scheduledDays = 30): int
    {
        return $this->handler->interestDays(
            Carbon::parse('2026-08-31'),
            Carbon::parse('2026-08-01'),
            Carbon::parse($now),
            $scheduledDays,
            Carbon::parse('2026-08-31')
        );
    }

    public function test_overdue_row_uses_full_period_task_example(): void
    {
        // Overdue by 10 days: 900,000 × 30 × 0.11% = 29,700 (was 900,000 × 10 × 0.11% = 9,900).
        $days = $this->days('2026-09-10');

        $this->assertSame(30, $days);
        $this->assertEqualsWithDelta(29700, 900000 * $days * 0.0011, 0.001);
    }

    public function test_overdue_by_more_than_the_period_is_the_same_as_overdue_by_one_day(): void
    {
        $this->assertSame(30, $this->days('2026-09-01'));
        $this->assertSame(30, $this->days('2026-10-15'));
    }

    public function test_row_due_today_uses_full_period(): void
    {
        $this->assertSame(30, $this->days('2026-08-31'));
    }

    public function test_rows_not_yet_due_are_unchanged(): void
    {
        // Not started: whole period.
        $this->assertSame(30, $this->days('2026-07-15'));
        // In progress (T2 is a separate fix): remaining days only, as before.
        $this->assertSame(20, $this->days('2026-08-11'));
    }

    public function test_missing_scheduled_days_falls_back_to_the_row_dates(): void
    {
        $this->assertSame(30, $this->days('2026-09-10', 0));
    }
}
