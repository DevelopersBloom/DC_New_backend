<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Tests\TestCase;

class ContractServiceRegenerateScheduleTest extends TestCase
{
    /**
     * Same month-diff formula as ContractService::regenerateSchedule.
     */
    private function countScheduleMonths(string $startDate, string $deadline): int
    {
        $start = Carbon::parse($startDate, 'Asia/Yerevan')->startOfDay();
        $end = Carbon::parse($deadline, 'Asia/Yerevan')->startOfDay();

        return max(1, ($end->year - $start->year) * 12 + ($end->month - $start->month));
    }

    public function test_count_schedule_months_same_month(): void
    {
        $this->assertSame(1, $this->countScheduleMonths('2026-05-30', '2026-05-30'));
    }

    public function test_count_schedule_months_one_year_apart(): void
    {
        $this->assertSame(12, $this->countScheduleMonths('2026-05-30', '2027-05-30'));
    }

    public function test_count_schedule_months_cross_year_boundary(): void
    {
        $this->assertSame(1, $this->countScheduleMonths('2026-12-15', '2027-01-10'));
    }

    public function test_count_schedule_months_twelve_month_span(): void
    {
        $this->assertSame(11, $this->countScheduleMonths('2026-06-01', '2027-05-01'));
    }

    public function test_count_schedule_months_matches_contract_creation_pattern(): void
    {
        $start = '2026-03-15';
        $deadlineDays = 12;
        $deadline = Carbon::parse($start, 'Asia/Yerevan')->addMonths($deadlineDays)->format('Y-m-d');

        $this->assertSame($deadlineDays, $this->countScheduleMonths($start, $deadline));
    }
}
