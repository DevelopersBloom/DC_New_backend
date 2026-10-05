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
            'status' => 'initial', 'entry_principal' => 0, 'entry_interest' => 0, 'last_entry_date' => null,
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

    public function test_instalments_are_ordered_oldest_first(): void
    {
        $r = S::classify([
            $this->row(['id' => 2, 'date' => '2026-09-20']),
            $this->row(['id' => 1, 'date' => '2026-05-29']),
        ], self::D);
        $this->assertSame([1, 2], array_column($r, 'payment_id'));
        $this->assertSame([124, 10], array_column($r, 'days_overdue'));
    }

    public function test_completed_instalment_paid_after_report_date_is_unpaid_at_report_date(): void
    {
        // Contracts 4, 7: status is 'completed' and to_date is the due date, but the money came on 01/02.10.
        $row = $this->row(['status' => 'completed', 'last_entry_date' => '2026-10-01']);
        $this->assertCount(1, S::classify([$row], self::D));
    }

    public function test_completed_status_without_entries_proves_nothing(): void
    {
        $row = $this->row(['status' => 'completed', 'principal_payment' => 150.31, 'interest_payment' => 0]);
        $r = S::classify([$row], self::D);
        $this->assertCount(1, $r);
        $this->assertEqualsWithDelta(150.31, $r[0]['principal'], 1e-9);
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
    }

    public function test_fully_settled_instalment_is_not_reported(): void
    {
        $row = $this->row(['status' => 'completed', 'last_entry_date' => '2026-09-02', 'entry_principal' => 1000, 'entry_interest' => 200]);
        $r = S::analyse([$row], self::D);
        $this->assertSame([], $r['overdue']);
        $this->assertSame([], $r['settled_with_gap']);
    }

    public function test_an_initial_instalment_fully_paid_by_entries_is_not_overdue(): void
    {
        // Contract 93, instalment 7774: status still 'initial' but entries cover it in full.
        $row = $this->row(['status' => 'initial', 'entry_principal' => 1000, 'entry_interest' => 200, 'last_entry_date' => '2026-09-14']);
        $this->assertSame([], S::classify([$row], self::D));
    }
}
