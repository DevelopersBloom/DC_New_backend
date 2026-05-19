<?php

namespace Tests\Unit;

use App\Models\DocumentJournal;
use App\Services\DealUpdateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DealUpdateServiceTest extends TestCase
{
    private function invokeJournalAmount(string $documentType, float $interest, float $principal, float $penalty, float $total): ?float
    {
        $service = new DealUpdateService();
        $method = (new ReflectionClass($service))->getMethod('journalAmountForType');
        $method->setAccessible(true);

        return $method->invoke($service, $documentType, $interest, $principal, $penalty, $total);
    }

    public function test_journal_amount_for_interest_type_returns_zero(): void
    {
        $this->assertSame(0.0, $this->invokeJournalAmount(
            DocumentJournal::PAY_INTEREST_AMOUNT,
            0,
            100,
            0,
            100
        ));
    }

    public function test_journal_amount_for_interest_type_returns_value_when_positive(): void
    {
        $this->assertSame(50.0, $this->invokeJournalAmount(
            DocumentJournal::PAY_INTEREST_AMOUNT,
            50,
            100,
            0,
            150
        ));
    }
}
