<?php

namespace Tests\Unit;

use App\Services\Payments\LatePaymentInterestRecalculator;
use PHPUnit\Framework\TestCase;

/** T3: pure parts of the late-payment interest recalculation. */
class LatePaymentInterestRecalculatorTest extends TestCase
{
    private const RATE = 0.0011; // 0.11 % per day

    private function row(int $id, string $from, string $to, float $principal, float $collected = 0): object
    {
        return (object) [
            'id' => $id, 'from_date' => $from, 'to_date' => $to, 'days' => 30,
            'principal_payment' => $principal, 'collected_principal' => $collected,
        ];
    }

    public function test_runs_only_when_a_due_date_is_before_the_payment_date(): void
    {
        $rows = [$this->row(1, '2026-08-01', '2026-08-31', 100000), $this->row(2, '2026-08-31', '2026-09-30', 100000)];

        $this->assertFalse(LatePaymentInterestRecalculator::isLate($rows, '2026-08-20')); // early
        $this->assertFalse(LatePaymentInterestRecalculator::isLate($rows, '2026-08-31')); // on the due date
        $this->assertTrue(LatePaymentInterestRecalculator::isLate($rows, '2026-09-01'));  // one day late
    }

    public function test_overdue_principal_is_netted_by_collected_entries(): void
    {
        // Row 1 is overdue and 40,000 of its 100,000 principal was already collected,
        // so only 60,000 more leaves the balance on the payment date (was the full 100,000).
        $rows = [
            $this->row(1, '2026-08-01', '2026-08-31', 100000, 40000),
            $this->row(2, '2026-08-31', '2026-09-30', 100000),
        ];

        $updates = (new LatePaymentInterestRecalculator())->computeUpdates($rows, 300000, self::RATE, '2026-09-10');
        $byId = collect($updates)->mapWithKeys(fn ($u) => [$u['payment']->id => $u['interest']]);

        // Fully crossed: whole period on the balance.
        $this->assertEqualsWithDelta(300000 * 30 * self::RATE, $byId[1], 0.001);
        // Split at the payment date: 10 days on 300,000, then 20 days on 300,000 − 60,000.
        $this->assertEqualsWithDelta(300000 * 10 * self::RATE + 240000 * 20 * self::RATE, $byId[2], 0.001);
    }

    public function test_rows_without_collected_principal_keep_the_old_result(): void
    {
        $rows = [
            (object) ['id' => 1, 'from_date' => '2026-08-01', 'to_date' => '2026-08-31', 'days' => 30, 'principal_payment' => 100000],
            (object) ['id' => 2, 'from_date' => '2026-08-31', 'to_date' => '2026-09-30', 'days' => 30, 'principal_payment' => 100000],
        ];

        $updates = (new LatePaymentInterestRecalculator())->computeUpdates($rows, 300000, self::RATE, '2026-09-10');

        $this->assertEqualsWithDelta(300000 * 10 * self::RATE + 200000 * 20 * self::RATE, $updates[1]['interest'], 0.001);
    }
}
