<?php

namespace Tests\Unit;

use App\Services\Payments\LatePaymentInterestRecalculator;
use App\Services\Payments\ScheduledPaymentHandler as H;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * T3: the late-payment recalculation uses the same calculation as
 * ScheduledPaymentHandler::recalculateInterest (scheduleStateFor + periodInterest).
 */
class LatePaymentInterestRecalculatorTest extends TestCase
{
    private const RATE = 0.0011; // 0.11 % per day

    private function row(int $id, string $from, string $to, float $principal, float $collected = 0): object
    {
        return (object) [
            'id' => $id, 'date' => $to, 'from_date' => $from, 'to_date' => $to, 'days' => 30,
            'principal_payment' => $principal, 'collected_principal' => $collected,
        ];
    }

    /** Three 100,000 rows: Aug, Sep, Oct 2026. */
    private function schedule(float $collectedOnRow1 = 0): array
    {
        return [
            $this->row(1, '2026-08-01', '2026-08-31', 100000, $collectedOnRow1),
            $this->row(2, '2026-08-31', '2026-09-30', 100000),
            $this->row(3, '2026-09-30', '2026-10-30', 100000),
        ];
    }

    private function interest(object $row, array $state): float
    {
        return H::periodInterest(
            Carbon::parse($row->from_date), Carbon::parse($row->to_date), $row->days,
            $state[$row->id]['opening'], $state[$row->id]['reductions'], self::RATE
        );
    }

    public function test_runs_only_when_a_due_date_is_before_the_payment_date(): void
    {
        $rows = [$this->row(1, '2026-08-01', '2026-08-31', 100000), $this->row(2, '2026-08-31', '2026-09-30', 100000)];

        $this->assertFalse(LatePaymentInterestRecalculator::isLate($rows, '2026-08-20')); // early
        $this->assertFalse(LatePaymentInterestRecalculator::isLate($rows, '2026-08-31')); // on the due date
        $this->assertTrue(LatePaymentInterestRecalculator::isLate($rows, '2026-09-01'));  // one day late
    }

    public function test_overdue_principal_keeps_accruing_until_the_payment_date(): void
    {
        // Row 1 is overdue; the client pays on 2026-09-10.
        $rows  = $this->schedule();
        $state = H::scheduleStateFor($rows, [], [], '2026-09-10');

        // Fully crossed: whole period on 300,000.
        $this->assertEqualsWithDelta(300000 * 30 * self::RATE, $this->interest($rows[0], $state), 0.001);
        // Split at the payment date: 10 days on 300,000 (row 1 still owed), then 20 on 200,000.
        $this->assertEqualsWithDelta(300000 * 10 * self::RATE + 200000 * 20 * self::RATE, $this->interest($rows[1], $state), 0.001);
    }

    public function test_overdue_principal_is_netted_by_collected_entries(): void
    {
        // 40,000 of row 1 was paid early on 2026-08-15, so only 60,000 is overdue.
        $rows    = $this->schedule(40000);
        $entries = [['payment_id' => 1, 'row_date' => '2026-08-31', 'row_due' => '2026-08-31', 'date' => '2026-08-15', 'amount' => 40000]];
        $state   = H::scheduleStateFor($rows, $entries, [], '2026-09-10');

        // Row 1: 14 days on 300,000, then 16 on 260,000.
        $this->assertEqualsWithDelta(300000 * 14 * self::RATE + 260000 * 16 * self::RATE, $this->interest($rows[0], $state), 0.001);
        // Row 2: 10 days on 260,000 (the 60,000 still overdue), then 20 on 200,000.
        $this->assertEqualsWithDelta(260000 * 10 * self::RATE + 200000 * 20 * self::RATE, $this->interest($rows[1], $state), 0.001);
    }

    public function test_on_time_client_gets_the_plain_schedule_balance(): void
    {
        // Row 1 was paid on its due date and is completed; row 2 is the first open row.
        $rows    = array_slice($this->schedule(), 1);
        $entries = [['payment_id' => 1, 'row_date' => '2026-08-31', 'row_due' => '2026-08-31', 'date' => '2026-08-31', 'amount' => 100000]];
        $state   = H::scheduleStateFor($rows, $entries, [], '2026-09-10');

        $this->assertEqualsWithDelta(200000 * 30 * self::RATE, $this->interest($rows[0], $state), 0.001);
    }

    public function test_earlier_row_paid_late_still_counts_after_it_is_completed(): void
    {
        // Row 1 was paid 10 days late (2026-09-10) and is completed; recalculating row 2
        // later must keep those 10 days on 300,000.
        $rows    = array_slice($this->schedule(), 1);
        $entries = [['payment_id' => 1, 'row_date' => '2026-08-31', 'row_due' => '2026-08-31', 'date' => '2026-09-10', 'amount' => 100000]];
        $state   = H::scheduleStateFor($rows, $entries, [], '2026-09-20');

        $this->assertEqualsWithDelta(300000 * 10 * self::RATE + 200000 * 20 * self::RATE, $this->interest($rows[0], $state), 0.001);
    }
}
