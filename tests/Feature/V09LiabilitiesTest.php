<?php

namespace Tests\Feature;

use App\Exports\Reports\V09Export;
use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use App\Models\LoanNdm;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Form 9 section 2 (liabilities by maturity) on the September 2026 snapshot (report date 30.09.2026).
 * Skipped when the snapshot is not in the database:
 *   DB_DATABASE=lomb37 vendor/bin/phpunit tests/Feature/V09LiabilitiesTest.php
 */
class V09LiabilitiesTest extends TestCase
{
    private const DATE = '2026-09-30';

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $present = abs($this->balance('33512NV') - 29000000.0) < 0.01 && LoanNdm::where('contract_number', 'DM-26-0010')->exists();
        } catch (\Throwable $e) {
            $present = false;
        }
        if (!$present) {
            $this->markTestSkipped('The September 2026 snapshot (33512NV 29,000,000 at 30.09) is not in the selected database.');
        }
    }

    private function balance(string $code): float
    {
        $id = ChartOfAccount::idByCode($code);
        return (float)DocumentJournal::where('credit_account_id', $id)->whereDate('date', '<=', self::DATE)->sum('amount_amd')
            - (float)DocumentJournal::where('debit_account_id', $id)->whereDate('date', '<=', self::DATE)->sum('amount_amd');
    }

    private function sheet()
    {
        $path = (new V09Export())->export('2026-07-01', self::DATE);
        $sheet = IOFactory::createReader('Xls')->load($path)->getSheetByName('Sheet1');
        @unlink($path);
        return $sheet;
    }

    public function test_agreement_buckets(): void
    {
        $buckets = (new V09Export())->participantLoanBuckets(self::DATE, Carbon::parse(self::DATE));

        $this->assertEqualsWithDelta(19000000, $buckets['E'], 0.01);
        $this->assertEqualsWithDelta(10000000, $buckets['N'], 0.01);
        $this->assertCount(2, $buckets);
    }

    public function test_section_2_matches_accountant_table(): void
    {
        $s = $this->sheet();
        $v = fn ($c) => (float)$s->getCell($c)->getCalculatedValue();

        $this->assertEqualsWithDelta(19000.0, $v('E50'), 0.0005);
        $this->assertEqualsWithDelta(10000.0, $v('N50'), 0.0005);
        $this->assertEqualsWithDelta(29000.0, $v('P50'), 0.0005);
        $this->assertEqualsWithDelta(1229.754, $v('F52'), 0.0005);
        $this->assertEqualsWithDelta(134.5, $v('F53'), 0.0005);
        $this->assertEqualsWithDelta(134.5, $v('P53'), 0.0005);
        $this->assertEqualsWithDelta(230.328, $v('F59'), 0.0005);

        $this->assertEqualsWithDelta(19000.0, $v('E61'), 0.0005);
        $this->assertEqualsWithDelta(1594.583, $v('F61'), 0.0015);
        $this->assertEqualsWithDelta(10000.0, $v('N61'), 0.0005);
        $this->assertEqualsWithDelta(0.0, $v('O61'), 0.0005);
        $this->assertEqualsWithDelta(30594.583, $v('P61'), 0.0015);
    }

    public function test_total_liabilities_equal_ledger_liabilities(): void
    {
        $ledger = 0.0;
        foreach (['33512NV', '39210', '3910201', '3910202', '3910203', '3910204', '39200', '39220'] as $code) {
            $ledger += $this->balance($code);
        }

        $this->assertEqualsWithDelta($ledger / 1000, (float)$this->sheet()->getCell('P61')->getCalculatedValue(), 0.002);
    }

    public function test_unmatched_entry_aborts(): void
    {
        $journal = DocumentJournal::where('credit_account_id', ChartOfAccount::idByCode('33512NV'))
            ->where('journalable_type', LoanNdm::class)->first();
        $this->assertNotNull($journal);

        \DB::beginTransaction();
        try {
            DocumentJournal::withoutEvents(fn () => $journal->forceFill(['journalable_type' => 'App\\Models\\Client'])->save());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not linked to a loan agreement');
            (new V09Export())->participantLoanBuckets(self::DATE, Carbon::parse(self::DATE));
        } finally {
            \DB::rollBack();
        }
    }
}
