<?php

namespace Tests\Unit;

use App\Services\Schedule\AnnuityScheduleCalculator;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Golden numbers of A-26-00115: 1,512,727 left after the 540,000 payment of 2026-10-30,
 * 0.11 %/day, payment day 6, deadline 2031-09-09, kept row due 2026-11-06.
 */
class AnnuityScheduleCalculatorTest extends TestCase
{
    private function a26_00115(): array
    {
        return (new AnnuityScheduleCalculator())->build(
            1512727.0,
            0.11,
            0.0,
            Carbon::parse('2026-11-06'),
            Carbon::parse('2026-11-06'),
            Carbon::parse('2031-09-09')
        );
    }

    public function test_row_count_and_last_row_is_deadline(): void
    {
        $rows = $this->a26_00115();

        $this->assertCount(58, $rows);
        $this->assertSame('2031-09-09', $rows[57]['business_due_date']);
        $this->assertTrue($rows[57]['is_last']);
        $this->assertSame(0.0, $rows[57]['balance_after']);
    }

    public function test_golden_rows(): void
    {
        $rows = $this->a26_00115();

        $this->assertSame('2026-12-07', $rows[0]['business_due_date']); // 6th is a Sunday
        $this->assertSame(31, $rows[0]['days']);
        $this->assertEqualsWithDelta(51583.99, $rows[0]['interest'], 0.01);
        $this->assertEqualsWithDelta(7839.06, $rows[0]['principal'], 0.01);
        $this->assertEqualsWithDelta(59423.05, $rows[0]['total'], 0.01);
        $this->assertEqualsWithDelta(1504887.94, $rows[0]['balance_after'], 0.01);

        $this->assertSame('2027-01-07', $rows[1]['business_due_date']); // 01-06 holiday
        $this->assertEqualsWithDelta(51316.68, $rows[1]['interest'], 0.01);
        $this->assertEqualsWithDelta(8106.37, $rows[1]['principal'], 0.01);

        $this->assertSame('2027-02-08', $rows[2]['business_due_date']);
        $this->assertSame(32, $rows[2]['days']);
        $this->assertEqualsWithDelta(52686.71, $rows[2]['interest'], 0.01);

        $this->assertSame('2031-08-06', $rows[56]['business_due_date']);
        $this->assertEqualsWithDelta(3900.87, $rows[56]['interest'], 0.01);
        $this->assertEqualsWithDelta(55522.18, $rows[56]['principal'], 0.01);

        $last = $rows[57];
        $this->assertSame('2031-08-06', $last['from_date']);
        $this->assertSame(34, $last['days']);
        $this->assertEqualsWithDelta(2344.46, $last['interest'], 0.01);
        $this->assertEqualsWithDelta(62686.11, $last['principal'], 0.01);
        $this->assertEqualsWithDelta(65030.57, $last['total'], 0.01);
    }

    public function test_totals(): void
    {
        $rows = $this->a26_00115();

        $this->assertEqualsWithDelta(1512727.0, array_sum(array_column($rows, 'principal')), 0.01);
        $this->assertEqualsWithDelta(1939417.42, array_sum(array_column($rows, 'interest')), 1.0);
    }

    public function test_each_row_interest_is_balance_times_days_times_rate(): void
    {
        $rows    = $this->a26_00115();
        $balance = 1512727.0;
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta(round($balance * 0.0011 * $row['days'], 2), $row['interest'], 0.005);
            $balance = $row['balance_after'];
        }
    }

    public function test_dates_are_contiguous(): void
    {
        $rows = $this->a26_00115();
        $prev = '2026-11-06';
        foreach ($rows as $row) {
            $this->assertSame($prev, $row['from_date']);
            $prev = $row['business_due_date'];
        }
    }

    public function test_service_fee_is_charged_on_actual_days(): void
    {
        $rows = (new AnnuityScheduleCalculator())->build(
            1000000.0, 0.1, 1.0,
            Carbon::parse('2026-11-06'), Carbon::parse('2026-11-06'), Carbon::parse('2027-02-09')
        );

        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(round(1000000 * (1.0 / 365 / 100) * $rows[0]['days'], 2), $rows[0]['service_fee'], 0.005);
        $this->assertSame(0.0, $rows[2]['balance_after']);
    }

    public function test_single_row_when_deadline_is_next_month(): void
    {
        $rows = (new AnnuityScheduleCalculator())->build(
            100000.0, 0.1, 0.0,
            Carbon::parse('2026-11-06'), Carbon::parse('2026-11-06'), Carbon::parse('2026-12-20')
        );

        $this->assertCount(1, $rows);
        $this->assertSame('2026-12-21', $rows[0]['business_due_date']); // 12-20 is a Sunday
        $this->assertEqualsWithDelta(100000.0, $rows[0]['principal'], 0.01);
    }

    public function test_rejects_deadline_in_the_same_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AnnuityScheduleCalculator())->build(
            100000.0, 0.1, 0.0,
            Carbon::parse('2026-11-06'), Carbon::parse('2026-11-06'), Carbon::parse('2026-11-25')
        );
    }

    public function test_zero_rate_splits_principal_evenly(): void
    {
        $rows = (new AnnuityScheduleCalculator())->build(
            120000.0, 0.0, 0.0,
            Carbon::parse('2026-11-06'), Carbon::parse('2026-11-06'), Carbon::parse('2027-02-09')
        );

        $this->assertEqualsWithDelta(40000.0, $rows[0]['principal'], 0.01);
        $this->assertSame(0.0, $rows[2]['balance_after']);
    }
}
