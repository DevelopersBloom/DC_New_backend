<?php

namespace Tests\Unit;

use App\Services\Payments\ScheduledPaymentHandler as H;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Worked example of an early (SOONER) payment, through the pure parts of
 * ScheduledPaymentHandler::recalculateInterest (scheduleStateFor + periodInterest).
 *
 * Loan 300,000, three 100,000 rows, 0.1 % per day, 30-day periods:
 *   row 1: 2027-01-01 → 2027-01-31, row 2: → 2027-03-02, row 3: → 2027-04-01.
 * On 2027-01-11 (day 10 of row 1) the client pays early.
 */
class EarlyPaymentExampleTest extends TestCase
{
    private const RATE = 0.001; // 0.1 % per day
    private const NOW  = '2027-01-11';

    private function rows(): array
    {
        $row = fn (int $id, string $from, string $to) => (object) [
            'id' => $id, 'date' => $to, 'from_date' => $from, 'to_date' => $to, 'days' => 30,
            'principal_payment' => 100000, 'collected_principal' => 0,
        ];

        return [
            $row(1, '2027-01-01', '2027-01-31'),
            $row(2, '2027-01-31', '2027-03-02'),
            $row(3, '2027-03-02', '2027-04-01'),
        ];
    }

    /** What recalculateInterest writes for one row. */
    private function recalc(object $row, array $state): array
    {
        $interest = H::periodInterest(
            Carbon::parse($row->from_date), Carbon::parse($row->to_date), $row->days,
            $state[$row->id]['opening'], $state[$row->id]['reductions'], self::RATE
        );

        return [
            'interest'  => $interest,
            'amount'    => $interest + $row->principal_payment, // gross, every row
            'remaining' => $state[$row->id]['ending'],
        ];
    }

    public function test_pays_150000_on_day_10(): void
    {
        // Step 2: interest for the 10 elapsed days on 300,000 is collected on row 1.
        $elapsedInterest = 300000 * 10 * self::RATE;
        $this->assertEqualsWithDelta(3000, $elapsedInterest, 0.001);

        // Step 3: the other 147,000 reduces principal: 100,000 on row 1, 47,000 on row 2.
        $pending = [1 => 100000, 2 => 47000];
        $rows    = $this->rows();
        $state   = H::scheduleStateFor($rows, [], $pending, self::NOW);

        // Step 4: row 1 = 10 days on 300,000 + 20 days on 153,000 (was 9,000 in the schedule).
        $row1 = $this->recalc($rows[0], $state);
        $this->assertEqualsWithDelta(6060, $row1['interest'], 0.001);
        $this->assertEqualsWithDelta(106060, $row1['amount'], 0.001);
        $this->assertEqualsWithDelta(153000, $row1['remaining'], 0.001);

        // Row 2: the reduction happened before its period, so 30 days on 153,000.
        $row2 = $this->recalc($rows[1], $state);
        $this->assertEqualsWithDelta(4590, $row2['interest'], 0.001);
        $this->assertEqualsWithDelta(104590, $row2['amount'], 0.001);
        $this->assertEqualsWithDelta(100000, $row2['remaining'], 0.001);

        // Step 5 (recordEntry): still due = amount − everything already entered on the row.
        // Row 1 at its due date: only the interest of the last 20 days.
        $this->assertEqualsWithDelta(3060, $row1['amount'] - (3000 + 100000), 0.001);
        // Row 2: its interest + the 53,000 principal not paid yet (not 10,590 as with
        // the old double-netting of the SOONER row).
        $this->assertEqualsWithDelta(57590, $row2['amount'] - 47000, 0.001);
    }

    public function test_t5_pays_exactly_row_1_principal(): void
    {
        // 103,000 on day 10: 3,000 interest + 100,000 principal, all on row 1.
        $rows  = $this->rows();
        $state = H::scheduleStateFor($rows, [], [1 => 100000], self::NOW);

        // 10 days on 300,000 + 20 days on 200,000. The row is not closed: 4,000 is still owed.
        $row1 = $this->recalc($rows[0], $state);
        $this->assertEqualsWithDelta(7000, $row1['interest'], 0.001);
        $this->assertEqualsWithDelta(107000, $row1['amount'], 0.001);
        $this->assertEqualsWithDelta(4000, $row1['amount'] - (3000 + 100000), 0.001);
        $this->assertGreaterThan(0, $row1['amount']); // recalculateInterest keeps it 'initial'
    }
}
