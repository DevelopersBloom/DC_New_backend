<?php

namespace Tests\Unit;

use App\Services\ContractService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verifies the pure broken-period annuity schedule builder against the
 * numerical example supplied in the spec:
 *
 *   Principal:        AMD 10,000,000
 *   Annual rate:      18 %
 *   Term:             24 months
 *   Disbursement:     June 20, 2025
 *   Payment day:      5th of each month
 */
class BrokenPeriodScheduleTest extends TestCase
{
    // Daily rate as stored in contracts.interest_rate: 18 % / 365
    private const INTEREST_RATE  = 18.0 / 365;
    private const LOAN_AMOUNT    = 10_000_000.0;
    private const MONTHS         = 24;
    private const MONTHLY_RATE   = 0.015; // 18 % / 12

    private function compute(
        float  $loanAmount,
        float  $interestRate,
        float  $feeAnnualPct,
        int    $months,
        string $disbursementDate,
        int    $paymentDay
    ): array {
        $svc = new ContractService();
        $m   = new ReflectionMethod(ContractService::class, 'computeBrokenPeriodAnnuitySchedule');
        $m->setAccessible(true);

        return $m->invoke(
            $svc,
            $loanAmount,
            $interestRate,
            $feeAnnualPct,
            $months,
            Carbon::parse($disbursementDate),
            $paymentDay
        );
    }

    // ── Main spec example ────────────────────────────────────────────────────

    public function test_broken_period_row_exists_and_is_interest_only(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertTrue($schedule[0]['is_broken_period']);
        $this->assertEquals(0.0, $schedule[0]['principal']);
    }

    public function test_broken_period_interest_matches_spec(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        // 10,000,000 × (18/100/365) × 15 days = 73,972.60
        $this->assertEqualsWithDelta(73_972.60, $schedule[0]['interest'], 0.01);
    }

    public function test_broken_period_balance_unchanged(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertEquals(self::LOAN_AMOUNT, $schedule[0]['balance_after']);
    }

    public function test_broken_period_calendar_date_is_july_5(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertEquals('2025-07-05', $schedule[0]['calendar_due_date']);
    }

    public function test_broken_period_business_date_shifts_saturday_to_monday(): void
    {
        // July 5 2025 is a Saturday → next working day = July 7 (Monday).
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertEquals('2025-07-07', $schedule[0]['business_due_date']);
    }

    public function test_broken_period_days_count_is_15(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertEquals(15, $schedule[0]['days']);
    }

    public function test_schedule_has_25_rows_broken_period_plus_24_installments(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertCount(25, $schedule);
    }

    public function test_installment_1_interest_is_exactly_1_5_percent_of_principal(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        // 10,000,000 × 0.015 = 150,000
        $this->assertEqualsWithDelta(150_000.0, $schedule[1]['interest'], 0.01);
    }

    public function test_installment_1_calendar_date_is_august_5(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertEquals('2025-08-05', $schedule[1]['calendar_due_date']);
    }

    public function test_installment_3_calendar_date_is_october_5_business_october_6(): void
    {
        // October 5 2025 is a Sunday → next working day = October 6 (Monday).
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertEquals('2025-10-05', $schedule[3]['calendar_due_date']);
        $this->assertEquals('2025-10-06', $schedule[3]['business_due_date']);
    }

    public function test_all_regular_installments_total_equals_pmt(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        // Derive the expected PMT using the same formula used by the service
        // (excelPmt returns a negative cashflow, so we negate it).
        $pow         = pow(1 + self::MONTHLY_RATE, self::MONTHS);
        $expectedPmt = round((self::MONTHLY_RATE * self::LOAN_AMOUNT * $pow) / ($pow - 1), 2);

        // Rows 1–23 must all equal the same PMT (within AMD 1 for accumulated rounding).
        for ($i = 1; $i <= 23; $i++) {
            $this->assertEqualsWithDelta($expectedPmt, $schedule[$i]['total'], 1.0, "Row $i total mismatch");
        }
    }

    public function test_last_installment_balance_is_zero(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $this->assertEqualsWithDelta(0.0, $schedule[24]['balance_after'], 0.01);
    }

    public function test_sum_of_principal_rows_1_to_24_equals_loan_amount(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $sumPrincipal = 0.0;
        for ($i = 1; $i <= 24; $i++) {
            $sumPrincipal += $schedule[$i]['principal'];
        }

        $this->assertEqualsWithDelta(self::LOAN_AMOUNT, $sumPrincipal, 1.0);
    }

    public function test_installment_2_interest_is_1_5_percent_of_balance_after_row_1(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $balanceAfter1 = $schedule[1]['balance_after'];
        $expectedInterest2 = round($balanceAfter1 * self::MONTHLY_RATE, 2);

        $this->assertEqualsWithDelta($expectedInterest2, $schedule[2]['interest'], 0.02);
    }

    public function test_installment_3_interest_is_1_5_percent_of_balance_after_row_2(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        $balanceAfter2 = $schedule[2]['balance_after'];
        $expectedInterest3 = round($balanceAfter2 * self::MONTHLY_RATE, 2);

        $this->assertEqualsWithDelta($expectedInterest3, $schedule[3]['interest'], 0.02);
    }

    public function test_balance_decreases_by_principal_each_period(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-20', 5);

        for ($i = 1; $i <= 23; $i++) {
            $expectedBalance = round($schedule[$i - 1 === 0 ? 0 : $i]['balance_after'] ?? 0.0, 2);
            // balance[i] = balance[i-1] - principal[i]
            $prevBalance = $i === 1 ? self::LOAN_AMOUNT : $schedule[$i - 1]['balance_after'];
            $expected    = round($prevBalance - $schedule[$i]['principal'], 2);
            $this->assertEqualsWithDelta($expected, $schedule[$i]['balance_after'], 0.02, "Balance after row $i mismatch");
        }
    }

    // ── Edge case: same-month payment day (June 1 → June 5, 4 broken days) ──

    public function test_edge_case_same_month_payment_day_4_days(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-01', 5);

        $this->assertTrue($schedule[0]['is_broken_period']);
        $this->assertEquals(4, $schedule[0]['days']);
        $this->assertEquals('2025-06-05', $schedule[0]['calendar_due_date']);

        // 10,000,000 × (18/100/365) × 4 = 19,726.03
        $this->assertEqualsWithDelta(19_726.03, $schedule[0]['interest'], 0.01);
    }

    public function test_edge_case_same_month_first_regular_installment_is_july_5(): void
    {
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-01', 5);

        $this->assertEquals('2025-07-05', $schedule[1]['calendar_due_date']);
    }

    // ── Disbursement on payment day: 30-day broken period to next month ──────

    public function test_disbursement_on_payment_day_creates_30_day_broken_period(): void
    {
        // Disbursed June 5, payment_day = 5.
        // "Strictly after June 5" → next occurrence of the 5th is July 5 (30 days).
        // A 30-day broken-period row exists, followed by 24 regular installments.
        $schedule = $this->compute(self::LOAN_AMOUNT, self::INTEREST_RATE, 0.0, self::MONTHS, '2025-06-05', 5);

        $this->assertCount(25, $schedule);
        $this->assertTrue($schedule[0]['is_broken_period']);
        $this->assertEquals(30, $schedule[0]['days']);
        $this->assertEquals('2025-07-05', $schedule[0]['calendar_due_date']);
        $this->assertEquals('2025-07-07', $schedule[0]['business_due_date']); // Saturday → Monday
    }
}
