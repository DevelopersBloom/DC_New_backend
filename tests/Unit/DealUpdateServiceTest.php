<?php

namespace Tests\Unit;

use App\Models\DocumentJournal;
use App\Services\DealUpdateService;
use App\Services\PaymentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DealUpdateServiceTest extends TestCase
{
    private function invokeJournalAmount(string $documentType, float $interest, float $principal, float $penalty, float $total): ?float
    {
        $paymentService = $this->createMock(PaymentService::class);
        $service = new DealUpdateService($paymentService);
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

    public function test_sequential_journal_amounts_decrease_takes_from_smallest_first(): void
    {
        $j1 = new DocumentJournal(['id' => 1, 'amount_amd' => 1000]);
        $j2 = new DocumentJournal(['id' => 2, 'amount_amd' => 18000]);
        $collection = collect([$j1, $j2]);

        $paymentService = $this->createMock(PaymentService::class);
        $service = new DealUpdateService($paymentService);
        $method = (new ReflectionClass($service))->getMethod('sequentialJournalAmounts');
        $method->setAccessible(true);

        $out = $method->invoke($service, $collection, 16000.0);

        $this->assertSame([0.0, 16000.0], $out);
    }

    public function test_sequential_journal_amounts_smaller_decrease_keeps_large_row(): void
    {
        $j1 = new DocumentJournal(['id' => 1, 'amount_amd' => 1000]);
        $j2 = new DocumentJournal(['id' => 2, 'amount_amd' => 18000]);
        $collection = collect([$j1, $j2]);

        $paymentService = $this->createMock(PaymentService::class);
        $service = new DealUpdateService($paymentService);
        $method = (new ReflectionClass($service))->getMethod('sequentialJournalAmounts');
        $method->setAccessible(true);

        $out = $method->invoke($service, $collection, 18600.0);

        $this->assertSame([600.0, 18000.0], $out);
    }

    public function test_sequential_journal_amounts_increase_adds_to_smallest_line_first(): void
    {
        $j1 = new DocumentJournal(['id' => 1, 'amount_amd' => 1000]);
        $j2 = new DocumentJournal(['id' => 2, 'amount_amd' => 18000]);
        $collection = collect([$j1, $j2]);

        $paymentService = $this->createMock(PaymentService::class);
        $service = new DealUpdateService($paymentService);
        $method = (new ReflectionClass($service))->getMethod('sequentialJournalAmounts');
        $method->setAccessible(true);

        $out = $method->invoke($service, $collection, 20000.0);

        $this->assertSame([2000.0, 18000.0], $out);
    }
}
