<?php

namespace Tests\Unit;

use App\Services\Reports\OverdueScheduleService as S;
use PHPUnit\Framework\TestCase;

class OverdueScheduleServiceTest extends TestCase
{
    private const D = '2026-09-30';

    private function row(array $o = []): array
    {
        return $o + [
            'id' => 1, 'date' => '2026-09-01', 'principal_payment' => 1000, 'interest_payment' => 200,
            'status' => 'initial', 'to_date' => null, 'entry_principal' => 0, 'entry_interest' => 0,
        ];
    }

    public function test_unpaid_instalment_before_report_date_is_overdue_with_days(): void
    {
        $r = S::classify([$this->row()], self::D);
        $this->assertCount(1, $r);
        $this->assertSame(29, $r[0]['days_overdue']);
        $this->assertSame(1000.0, $r[0]['principal']);
        $this->assertSame(200.0, $r[0]['interest']);
    }

    public function test_instalment_due_on_report_date_is_not_overdue(): void
    {
        $this->assertSame([], S::classify([$this->row(['date' => '2026-09-30'])], self::D));
    }

    public function test_completed_status_without_entries_proves_nothing(): void
    {
        // Contract 36, instalment 3309: 'completed', to_date = due date, no entries; the ledger
        // principal only adds up if its 150.31 is still outstanding.
        $row = $this->row(['status' => 'completed', 'to_date' => '2026-09-01', 'principal_payment' => 150.31, 'interest_payment' => 0]);
        $r = S::classify([$row], self::D);
        $this->assertCount(1, $r);
        $this->assertEqualsWithDelta(150.31, $r[0]['principal'], 1e-9);
    }

    public function test_completed_instalment_paid_after_report_date_is_recomputed_from_entries(): void
    {
        // Paid on 02.10: status is 'completed' and to_date is the due date, but at 30.09 it was unpaid.
        $row = $this->row(['status' => 'completed', 'to_date' => '2026-09-01', 'last_entry_date' => '2026-10-02']);
        $this->assertCount(1, S::classify([$row], self::D));
    }

    public function test_completed_instalment_settled_before_report_date_ignores_small_interest_difference(): void
    {
        // Contract 13: settled 12.08, entered interest 1690.81 lower than the schedule.
        $row = $this->row([
            'status' => 'completed', 'to_date' => '2026-08-11', 'last_entry_date' => '2026-08-12',
            'principal_payment' => 1000, 'interest_payment' => 1690.81, 'entry_principal' => 1000, 'entry_interest' => 0,
        ]);
        $this->assertSame([], S::classify([$row], self::D));
    }

    public function test_partial_payment_leaves_only_the_remainder(): void
    {
        $r = S::classify([$this->row(['entry_principal' => 400, 'entry_interest' => 200])], self::D);
        $this->assertSame(600.0, $r[0]['principal']);
        $this->assertSame(0.0, $r[0]['interest']);
    }

    public function test_noise_below_half_dram_is_ignored(): void
    {
        $row = $this->row(['entry_principal' => 999.7, 'entry_interest' => 199.8]);
        $this->assertSame([], S::classify([$row], self::D));
    }

    public function test_small_overdue_amounts_are_kept_there_is_no_1000_threshold(): void
    {
        $row = $this->row(['principal_payment' => 232, 'interest_payment' => 0]);
        $this->assertCount(1, S::classify([$row], self::D));
    }

    public function test_instalments_are_per_instalment_and_ordered_oldest_first(): void
    {
        $r = S::classify([
            $this->row(['id' => 2, 'date' => '2026-09-20']),
            $this->row(['id' => 1, 'date' => '2026-05-29']),
        ], self::D);
        $this->assertSame([1, 2], array_column($r, 'payment_id'));
        $this->assertSame([124, 10], array_column($r, 'days_overdue'));
    }

    public function test_ledger_cap_scales_instalments_proportionally(): void
    {
        $overdue = S::classify([
            $this->row(['id' => 1, 'date' => '2026-05-29', 'principal_payment' => 600]),
            $this->row(['id' => 2, 'date' => '2026-09-20', 'principal_payment' => 400]),
        ], self::D);
        $rec = S::reconcile($overdue, 500, 1000, 50, 0);
        $this->assertSame(500.0, $rec['principal']);
        $this->assertEqualsWithDelta(300, $rec['instalments'][0]['principal'], 1e-9);
        $this->assertEqualsWithDelta(200, $rec['instalments'][1]['principal'], 1e-9);
        $this->assertTrue($rec['capped']);
        $this->assertSame(400.0, $rec['interest']);
    }

    public function test_penalties_are_added_to_overdue_interest_and_capped_at_16201ni(): void
    {
        $overdue = S::classify([$this->row(['principal_payment' => 1000, 'interest_payment' => 200])], self::D);
        $this->assertSame(500.0, S::reconcile($overdue, 5000, 10000, 0, 300)['interest']);   // 200 + 300 penalties
        $this->assertSame(250.0, S::reconcile($overdue, 5000, 250, 0, 300)['interest']);     // capped at NI balance
    }

    public function test_16200_adjustment_stays_in_the_term_row(): void
    {
        $overdue = S::classify([$this->row(['principal_payment' => 1000, 'interest_payment' => 0])], self::D);
        $rec = S::reconcile($overdue, 10000, 0, 500, 0);
        $this->assertSame(0.0, $rec['adjustment']);
        $this->assertSame(1000.0, $rec['gross']);
    }

    public function test_settled_with_gap_is_reported_but_not_overdue(): void
    {
        // Contract 13, instalment 3472: settled 12.08, interest 1690.81 lower than scheduled.
        $row = $this->row([
            'id' => 3472, 'date' => '2026-08-11', 'status' => 'completed', 'last_entry_date' => '2026-08-12',
            'principal_payment' => 22745.63, 'interest_payment' => 93014.57,
            'entry_principal' => 22745.63, 'entry_interest' => 91323.76,
        ]);
        $r = S::analyse([$row], self::D);
        $this->assertSame([], $r['overdue']);
        $this->assertCount(1, $r['settled_with_gap']);
        $this->assertSame(3472, $r['settled_with_gap'][0]['payment_id']);
        $this->assertEqualsWithDelta(1690.81, $r['settled_with_gap'][0]['interest_gap'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $r['settled_with_gap'][0]['principal_gap'], 1e-6);
    }

    public function test_fully_settled_instalment_is_not_reported(): void
    {
        $row = $this->row(['status' => 'completed', 'last_entry_date' => '2026-09-02', 'entry_principal' => 1000, 'entry_interest' => 200]);
        $r = S::analyse([$row], self::D);
        $this->assertSame([], $r['overdue']);
        $this->assertSame([], $r['settled_with_gap']);
    }

    public function test_written_off_contract_stays_overdue_with_zero_principal(): void
    {
        $overdue = S::classify([$this->row()], self::D);
        $rec = S::reconcile($overdue, 0.0, 0.0, 0.0, 0.0);
        $this->assertTrue($rec['overdue']);
        $this->assertSame(0.0, $rec['principal']);
        $this->assertSame(0.0, $rec['gross']);
        $this->assertCount(1, $rec['instalments']);
    }

    public function test_interest_only_shortfall_without_ledger_interest_is_not_overdue(): void
    {
        $overdue = S::classify([$this->row(['principal_payment' => 0, 'interest_payment' => 1690.81])], self::D);
        $rec = S::reconcile($overdue, 2917219.67, 0.0, 300, 0);
        $this->assertFalse($rec['overdue']);
        $this->assertSame(0.0, $rec['gross']);
    }

    public function test_interest_only_shortfall_supported_by_ledger_is_overdue(): void
    {
        $overdue = S::classify([$this->row(['principal_payment' => 0, 'interest_payment' => 1690.81])], self::D);
        $rec = S::reconcile($overdue, 100000, 5000, 0, 0);
        $this->assertTrue($rec['overdue']);
        $this->assertSame(1690.81, $rec['interest']);
    }

    public function test_column_boundaries(): void
    {
        foreach ([[1, 'B'], [90, 'B'], [91, 'D'], [180, 'D'], [181, 'F'], [270, 'F'], [271, 'H'], [366, 'H'], [367, 'J'], [1826, 'J'], [1827, 'L']] as [$days, $col]) {
            $this->assertSame($col, S::columnForDays($days), "days=$days");
        }
    }

    public function test_ledger_method_applies_cumulative_paid_oldest_first(): void
    {
        $rows = [
            ['id' => 1, 'date' => '2026-08-26', 'principal_payment' => 27407.98, 'interest_payment' => 0],
            ['id' => 2, 'date' => '2026-09-28', 'principal_payment' => 15603.17, 'interest_payment' => 0],
            ['id' => 3, 'date' => '2026-10-28', 'principal_payment' => 9999, 'interest_payment' => 0],   // not yet due
        ];
        $r = S::allocateOldestFirst($rows, 0.0, 0.0, self::D);
        $this->assertSame([1, 2], array_column($r, 'payment_id'));

        $r = S::allocateOldestFirst($rows, 30000.0, 0.0, self::D);   // covers instalment 1 and part of 2
        $this->assertSame([2], array_column($r, 'payment_id'));
        $this->assertEqualsWithDelta(13011.15, $r[0]['principal'], 1e-6);
        $this->assertSame(2, $r[0]['days_overdue']);
    }

    public function test_ledger_method_uses_original_amount_of_rewritten_rows(): void
    {
        // Contract 7: early instalments were rewritten to 0.00 once paid; original_* keeps what was due.
        $rows = [
            ['id' => 1, 'date' => '2026-03-26', 'principal_payment' => 0, 'interest_payment' => 0, 'original_principal_payment' => 100, 'original_interest_payment' => 0],
            ['id' => 2, 'date' => '2026-08-26', 'principal_payment' => 50, 'interest_payment' => 0, 'original_principal_payment' => 50, 'original_interest_payment' => 0],
        ];
        $r = S::allocateOldestFirst($rows, 100.0, 0.0, self::D);
        $this->assertSame([2], array_column($r, 'payment_id'));
        $this->assertSame(50.0, $r[0]['principal']);
    }

    public function test_small_instalment_without_entries_is_assumed_paid_when_a_later_one_is_paid(): void
    {
        // Contract 36, instalment 3309: 150.31, due 18.05, no entries; instalment due 16.09 is paid.
        $row = $this->row(['id' => 3309, 'date' => '2026-05-18', 'status' => 'completed', 'principal_payment' => 150.31, 'interest_payment' => 0]);
        $r = S::analyse([$row], self::D, '2026-09-16');
        $this->assertSame([], $r['overdue']);
        $this->assertCount(1, $r['assumed_paid']);
        $this->assertSame(3309, $r['assumed_paid'][0]['payment_id']);
        $this->assertSame('2026-09-16', $r['assumed_paid'][0]['paid_instalment_due_date']);
    }

    public function test_assumed_paid_needs_a_later_paid_instalment(): void
    {
        $row = $this->row(['date' => '2026-05-18', 'status' => 'completed', 'principal_payment' => 150.31, 'interest_payment' => 0]);
        $this->assertCount(1, S::analyse([$row], self::D, null)['overdue']);
        $this->assertCount(1, S::analyse([$row], self::D, '2026-05-18')['overdue']);   // same or earlier due date
    }

    public function test_assumed_paid_never_applies_to_large_amounts(): void
    {
        $row = $this->row(['date' => '2026-09-24', 'status' => 'completed', 'principal_payment' => 64388.21, 'interest_payment' => 61278.32]);
        $this->assertCount(1, S::analyse([$row], self::D, '2026-10-24')['overdue']);
    }

    public function test_assumed_paid_never_applies_to_an_instalment_that_has_entries(): void
    {
        // Contract 25, instalment 2734: partly paid, a later instalment is paid too; judged by its entries.
        $row = $this->row([
            'date' => '2026-08-24', 'status' => 'initial', 'principal_payment' => 62264.97, 'interest_payment' => 0,
            'entry_principal' => 23111.69, 'last_entry_date' => '2026-09-14',
        ]);
        $r = S::analyse([$row], self::D, '2026-09-24');
        $this->assertCount(1, $r['overdue']);
        $this->assertEqualsWithDelta(39153.28, $r['overdue'][0]['principal'], 1e-6);
        $this->assertSame([], $r['assumed_paid']);
    }
}
