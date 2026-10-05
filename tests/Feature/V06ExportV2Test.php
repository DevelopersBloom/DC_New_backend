<?php

namespace Tests\Feature;

use App\Exports\Reports\V06ExportV2;
use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Form 6 v2, September 2026 (report date 30.09.2026), against a database that holds the September
 * data (lomb-35 or later; the result must not depend on payments made after the report date).
 * Skipped when that data is not there. Run with the database selected, e.g.
 *   DB_DATABASE=lomb_35 vendor/bin/phpunit tests/Feature/V06ExportV2Test.php
 *
 * Acceptance scope: rows 21/22 (all columns), N/P/R of rows 15/16, and N14/P14/R14. The remainder
 * columns B..L of rows 15/16/28 stay on the legacy final-deadline buckets and are not asserted.
 */
class V06ExportV2Test extends TestCase
{
    private $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            // Data signature of the September snapshot: total 16200NV balance at 30.09.2026.
            $nv = ChartOfAccount::idByCode('16200NV');
            $balance = DocumentJournal::where('debit_account_id', $nv)->whereDate('date', '<=', '2026-09-30')->sum('amount_amd')
                - DocumentJournal::where('credit_account_id', $nv)->whereDate('date', '<=', '2026-09-30')->sum('amount_amd');
            $present = Contract::where('num', 'A-26-00005')->exists() && abs($balance - 126669674.0) < 1000;
        } catch (\Throwable $e) {
            $present = false;
        }
        if (!$present) {
            $this->markTestSkipped('The September 2026 snapshot (16200NV 126,669,674 at 30.09) is not in the selected database.');
        }

        $path = (new V06ExportV2())->export('2026-09-01', '2026-09-30');
        $this->sheet = IOFactory::createReader('Xls')->load($path)->getSheetByName('Sheet1');
        @unlink($path);
    }

    private function v(string $cell): float
    {
        return (float) $this->sheet->getCell($cell)->getCalculatedValue();
    }

    public function test_row_1_3_overdue_assets(): void
    {
        foreach ([21, 22] as $r) {
            foreach (['B' => 506.906, 'D' => 57.988, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0, 'N' => 564.894, 'P' => 2896.130, 'R' => 25] as $col => $expected) {
                $this->assertEqualsWithDelta($expected, $this->v($col . $r), 0.0005, "$col$r");
            }
        }
    }

    public function test_row_1_1_totals_are_the_ledger_less_the_overdue_part(): void
    {
        foreach ([15, 16] as $r) {
            $this->assertEqualsWithDelta(126104.780, $this->v('N' . $r), 0.0005, "N$r");
            $this->assertEqualsWithDelta(128029.519, $this->v('P' . $r), 0.0005, "P$r");
            $this->assertEquals(57, $this->v('R' . $r), "R$r");
        }
    }

    public function test_row_14_equals_the_ledger_and_has_no_error(): void
    {
        $this->assertSame(126669.674, round($this->v('N14'), 3));      // ledger 16200NV
        $this->assertEqualsWithDelta(130925.646, $this->v('P14'), 0.005);   // ledger NV+NI+16200 (3 AMD: contract 2 interest keying)
        $this->assertEquals(82, $this->v('R14'));
        foreach (['N14', 'P14', 'R14'] as $cell) {
            $this->assertNotSame('Error!', $this->sheet->getCell($cell)->getCalculatedValue(), $cell);
        }
        $this->assertEquals($this->v('N15') + $this->v('N21'), $this->v('N14'), '', 0.0005);
    }
}
