<?php

namespace Tests\Feature;

use App\Exports\Reports\V06Export;
use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Services\Reports\OverdueScheduleService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Form 6 row 1.3 on the September 2026 snapshot (report date 30.09.2026): which contracts are
 * overdue, judged as of that date. Must give the same answer on any database that contains the
 * snapshot or anything later (lomb-35, lomb-36 ...), because payments made after 30.09 and the
 * contract's current status must not matter. Skipped when the snapshot is not in the database:
 *   DB_DATABASE=lomb_36 vendor/bin/phpunit tests/Feature/V06OverdueRowTest.php
 */
class V06OverdueRowTest extends TestCase
{
    private const DATE = '2026-09-30';

    protected function setUp(): void
    {
        parent::setUp();
        try {
            // Data signature of the September snapshot: total 16200NV balance at 30.09.2026.
            $nv = ChartOfAccount::idByCode('16200NV');
            $balance = DocumentJournal::where('debit_account_id', $nv)->whereDate('date', '<=', self::DATE)->sum('amount_amd')
                - DocumentJournal::where('credit_account_id', $nv)->whereDate('date', '<=', self::DATE)->sum('amount_amd');
            $present = Contract::where('num', 'A-26-00005')->exists() && abs($balance - 126669674.0) < 1000;
        } catch (\Throwable $e) {
            $present = false;
        }
        if (!$present) {
            $this->markTestSkipped('The September 2026 snapshot (16200NV 126,669,674 at 30.09) is not in the selected database.');
        }
    }

    public function test_required_contracts_are_overdue_at_report_date(): void
    {
        $ids = Contract::whereIn('num', ['A-26-00005', 'P-26-00044'])->pluck('id', 'num');
        $overdue = (new OverdueScheduleService())->overdueAmountsAtDate($ids->values()->all(), self::DATE, V06Export::V06_OVERDUE_MIN_AMOUNT);

        // Both were repaid on 01.10; the schedule rows of the second were deleted on 02.10 and its status is 'completed'.
        $this->assertEqualsWithDelta(42575.63 + 260181.92, $overdue[$ids['A-26-00005']], 0.2);
        $this->assertEqualsWithDelta(166576.29 + 1009800.00, $overdue[$ids['P-26-00044']], 0.01);
    }

    public function test_contracts_below_the_minimum_or_paid_are_not_overdue(): void
    {
        $nums = Contract::whereIn('id', array_keys($this->overdueIds()))->pluck('num')->all();
        // 61, 66, 75, 113 are overdue by 232-776 AMD only; 93 is fully paid by entries but its instalment status is still 'initial';
        // 13 was settled on 12.08 (interest differs from the schedule).
        $under = Contract::whereIn('id', [61, 66, 75, 113, 93, 13])->pluck('num')->all();
        $this->assertSame([], array_intersect($under, $nums));
        $this->assertContains('G-26-00058', $nums, 'written-off contract 63 stays overdue');
        $this->assertCount(21, $nums);
    }

    private function overdueIds(): array
    {
        // Contracts open on the report date: provided by then and not closed before it (closed_at is the
        // only thing that matters, not the current status).
        $ids = Contract::where('provided_at', '<=', self::DATE)
            ->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', self::DATE))
            ->pluck('id')->all();

        return (new OverdueScheduleService())->overdueAmountsAtDate($ids, self::DATE, V06Export::V06_OVERDUE_MIN_AMOUNT);
    }

    public function test_report_rows_1_1_and_1_3(): void
    {
        $path = (new V06Export())->export('2026-09-01', self::DATE);
        $sheet = IOFactory::createReader('Xls')->load($path)->getSheetByName('Sheet1');
        @unlink($path);

        foreach ([21, 22] as $r) {
            $this->assertEqualsWithDelta(40812.758, (float) $sheet->getCell('N' . $r)->getCalculatedValue(), 0.0005, "N$r");
            $this->assertEqualsWithDelta(43646.642, (float) $sheet->getCell('P' . $r)->getValue(), 0.0005, "P$r");
            $this->assertEquals(21, $sheet->getCell('R' . $r)->getValue(), "R$r");
        }
        foreach ([15, 16] as $r) {
            $this->assertEquals(61, $sheet->getCell('R' . $r)->getValue(), "R$r");
        }
        $this->assertEquals(82, $sheet->getCell('R14')->getCalculatedValue());
    }
}
