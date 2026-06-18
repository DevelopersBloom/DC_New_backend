<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Services\ContractService;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class PaymentServiceEarlyOverpaymentTest extends TestCase
{
    public function test_recalculate_amortized_interest_updates_remaining_balance(): void
    {
        $contract = new Contract([
            'provided_amount' => 925975.09,
            'interest_rate' => 0.11, // daily %
            'date' => '2026-04-07',
        ]);

        $payment1 = new FakeSchedulePayment([
            'id' => 101,
            'status' => 'initial',
            'date' => '2026-05-05',
            'from_date' => '2026-04-07',
            'to_date' => '2026-05-05',
            'principal_payment' => 70000.00,
            'interest_payment' => 0,
            'amount' => 0,
            'remaining' => 999999.99, // stale old value
        ]);

        $payment2 = new FakeSchedulePayment([
            'id' => 102,
            'status' => 'initial',
            'date' => '2026-06-02',
            'from_date' => '2026-05-05',
            'to_date' => '2026-06-02',
            'principal_payment' => 71000.00,
            'interest_payment' => 0,
            'amount' => 0,
            'remaining' => 999999.99, // stale old value
        ]);

        $service = new TestablePaymentService(Mockery::mock(ContractService::class));
        $service->recalculateForTest($contract, collect([$payment1, $payment2]));

        $expectedInterest1 = round(925975.09 * 28 * 0.11 / 100, 10);
        $expectedRemaining1 = round(925975.09 - 70000.00, 10);
        $expectedInterest2 = round($expectedRemaining1 * 28 * 0.11 / 100, 10);
        $expectedRemaining2 = round($expectedRemaining1 - 71000.00, 10);

        $this->assertSame($expectedInterest1, round((float) $payment1->interest_payment, 10));
        $this->assertSame($expectedRemaining1, round((float) $payment1->remaining, 10));
        $this->assertSame($expectedInterest2, round((float) $payment2->interest_payment, 10));
        $this->assertSame($expectedRemaining2, round((float) $payment2->remaining, 10));
        $this->assertTrue($payment1->saved);
        $this->assertTrue($payment2->saved);
    }
}

class TestablePaymentService extends PaymentService
{
    public function recalculateForTest(Contract $contract, Collection $payments): void
    {
        $this->recalculateAmortizedInterestFromSchedule($contract, $payments);
    }
}

class FakeSchedulePayment
{
    public int $id;
    public string $status;
    public string $date;
    public string $from_date;
    public string $to_date;
    public float $principal_payment;
    public float $interest_payment;
    public float $amount;
    public float $remaining;
    public bool $saved = false;

    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function save(): void
    {
        $this->saved = true;
    }
}
