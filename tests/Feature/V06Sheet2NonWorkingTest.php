<?php

namespace Tests\Feature;

use App\Exports\Reports\V06Export;
use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Form 6 Sheet2 table IV, left block (non-working assets, columns B/D/F, rows 89/91/92): must be as of
 * the report date, not the contracts' status today. Needs the 30.09.2026 snapshot (lomb36 or later):
 *   DB_DATABASE=lomb36 vendor/bin/phpunit tests/Feature/V06Sheet2NonWorkingTest.php
 */
class V06Sheet2NonWorkingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $nv = ChartOfAccount::idByCode('16200NV');
            $balance = DocumentJournal::where('debit_account_id', $nv)->whereDate('date', '<=', '2026-09-30')->sum('amount_amd')
                - DocumentJournal::where('credit_account_id', $nv)->whereDate('date', '<=', '2026-09-30')->sum('amount_amd');
            $present = Contract::where('num', 'P-26-00044')->exists() && abs($balance - 126669674.0) < 1000;
        } catch (\Throwable $e) {
            $present = false;
        }
        if (!$present) {
            $this->markTestSkipped('The September 2026 snapshot is not in the selected database.');
        }
    }

    private function sheet2(string $from, string $to): array
    {
        $path = (new V06Export())->export($from, $to);
        $sheet = IOFactory::createReader('Xls')->load($path)->getSheetByName('Sheet2');
        @unlink($path);

        $out = [];
        foreach ([6, 89, 91, 92] as $r) {
            foreach (['B', 'D', 'F'] as $c) {
                $out["$c$r"] = (float) $sheet->getCell($c . $r)->getCalculatedValue();
            }
        }

        return $out;
    }

    public function test_august_closing(): void
    {
        $s = $this->sheet2('2026-08-01', '2026-08-31');
        $this->assertEqualsWithDelta(10193.028, $s['F6'], 0.002);
        $this->assertEqualsWithDelta(2773.077, $s['F89'], 0.002);
        $this->assertEqualsWithDelta(492.303, $s['F91'], 0.002);
        $this->assertEqualsWithDelta(6927.648, $s['F92'], 0.002);
    }

    public function test_september_opens_with_august_closing(): void
    {
        $aug = $this->sheet2('2026-08-01', '2026-08-31');
        $sep = $this->sheet2('2026-09-01', '2026-09-30');

        $this->assertEqualsWithDelta(10193.028, $sep['B6'], 0.002);
        foreach ([89, 91, 92] as $r) {
            $this->assertEqualsWithDelta($aug["F$r"], $sep["B$r"], 0.002, "row $r");
            $this->assertEqualsWithDelta($sep["B$r"] + $sep["D$r"], $sep["F$r"], 0.002, "row $r");
        }
        $this->assertEqualsWithDelta(2803.678, $sep['F89'], 0.002);
        $this->assertEqualsWithDelta(496.261, $sep['F91'], 0.002);
        $this->assertEqualsWithDelta(7191.286, $sep['F92'], 0.002);
        $this->assertEqualsWithDelta(298.2, $sep['D6'], 0.2);
    }

    public function test_july_closing_equals_august_opening(): void
    {
        $jul = $this->sheet2('2026-07-01', '2026-07-31');
        $aug = $this->sheet2('2026-08-01', '2026-08-31');
        // Sent as 1260.667, which still counted contract 9 (closed 14.04.2026, before the date).
        $this->assertEqualsWithDelta(1260.570, $jul['F6'], 0.002);
        $this->assertEqualsWithDelta($jul['F6'], $aug['B6'], 0.002);
    }

    public function test_result_does_not_depend_on_closure_after_report_date(): void
    {
        $contract = Contract::where('num', 'P-26-00044')->firstOrFail();
        $before = $this->sheet2('2026-09-01', '2026-09-30');

        DB::beginTransaction();
        try {
            // Reopened, and closed one day after the report date: same report either way.
            foreach ([[null, 'initial'], ['2026-10-01', 'completed']] as [$closedAt, $status]) {
                DB::table('contracts')->where('id', $contract->id)->update(['closed_at' => $closedAt, 'status' => $status]);
                $this->assertEquals($before, $this->sheet2('2026-09-01', '2026-09-30'));
            }
        } finally {
            DB::rollBack();
        }
    }
}
