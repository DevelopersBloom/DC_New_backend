<?php

namespace Tests\Unit;

use App\Exports\Acra\AcraExport;
use PHPUnit\Framework\TestCase;

/** The cutoff shared by the Credit-sheet snapshot and the "classification changed" report trigger. */
class AcraClassificationCutoffTest extends TestCase
{
    public function test_cutoff_is_end_of_day_before_to_minus_ten_minutes(): void
    {
        $this->assertSame('2026-11-09 23:49:59', AcraExport::classificationCutoff('2026-11-10')->format('Y-m-d H:i:s'));
    }

    public function test_manual_change_dated_on_last_day_is_inside_window_and_snapshot(): void
    {
        $cutoff = AcraExport::classificationCutoff('2026-11-10');
        $changeDate = \Carbon\Carbon::parse('2026-11-09 13:03:22'); // client 132, history 468

        $this->assertTrue($changeDate->lte($cutoff));
        $this->assertTrue($changeDate->gte(\Carbon\Carbon::parse('2026-11-09')));
    }
}
