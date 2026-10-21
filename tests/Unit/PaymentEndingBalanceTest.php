<?php

namespace Tests\Unit;

use App\Services\Payments\ScheduledPaymentHandler;
use PHPUnit\Framework\TestCase;

/**
 * T1: payments.remaining is the row's ending balance (opening balance minus the
 * row's unpaid principal), the same meaning it has when the schedule is created.
 */
class PaymentEndingBalanceTest extends TestCase
{
    private function row(int $id, float $principal, float $collected = 0): object
    {
        return (object) ['id' => $id, 'principal_payment' => $principal, 'collected_principal' => $collected];
    }

    public function test_task_example_ending_balance_is_opening_minus_principal(): void
    {
        // Opening 900,000; row principal 50,000 → remaining 850,000 (was written as 900,000).
        $balances = ScheduledPaymentHandler::endingBalancesFor([
            $this->row(1, 50000),
            $this->row(2, 400000),
            $this->row(3, 450000),
        ]);

        $this->assertEquals([1 => 850000, 2 => 450000, 3 => 0], $balances);
    }

    public function test_remaining_plus_unpaid_principal_is_the_opening_balance(): void
    {
        $rows = [$this->row(1, 50000, 20000), $this->row(2, 400000), $this->row(3, 450000)];
        $balances = ScheduledPaymentHandler::endingBalancesFor($rows, [1 => 10000]);

        // Row 1 unpaid = 50,000 − 20,000 collected − 10,000 being paid now = 20,000.
        $this->assertEquals(850000, $balances[1]);
        $this->assertEquals(870000, $balances[1] + 20000);
        $this->assertEquals(0, $balances[3]);
    }

    public function test_collected_and_pending_principal_of_later_rows_lowers_earlier_balances(): void
    {
        $balances = ScheduledPaymentHandler::endingBalancesFor(
            [$this->row(1, 100000), $this->row(2, 100000, 30000), $this->row(3, 100000)],
            [3 => 100000]
        );

        $this->assertEquals([1 => 70000, 2 => 0, 3 => 0], $balances);
    }
}
