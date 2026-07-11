<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\Payment;
use App\Services\Payments\PaymentEntryRecorder;
use App\Services\Payments\PrepaymentHandler;
use App\Services\PrepaymentService;
use Mockery;
use Tests\TestCase;

/**
 * Verifies PrepaymentHandler::handle()'s accrued-vs-deferred interest split:
 *  - Interest actually accrued between the installment's from_date and the payment
 *    date is settled normally (posted as regular interest income).
 *  - The rest of the row's scheduled interest, plus principal, is NOT yet due before
 *    the business due date, so both go into the Prepayment bucket together.
 */
class PrepaymentHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Stubs Payment::entries() to behave as if no PaymentEntry rows exist yet
     * (sum() always returns 0), without touching the database.
     */
    private function paymentWithNoPriorEntries(array $attributes): Payment
    {
        // 'id' is never mass-assignable, so it can't go through the constructor's
        // fill() — set it directly on the instance afterwards instead.
        $id = $attributes['id'] ?? null;
        unset($attributes['id']);

        $payment = Mockery::mock(Payment::class . '[entries]', [$attributes]);
        if ($id !== null) {
            $payment->id = $id;
        }

        $entries = Mockery::mock();
        $entries->shouldReceive('sum')->andReturn(0.0);
        $payment->shouldReceive('entries')->andReturn($entries);

        return $payment;
    }

    private function makeHandler(PrepaymentService $prepaymentService): PrepaymentHandler
    {
        $recorder = Mockery::mock(PaymentEntryRecorder::class);
        $recorder->shouldReceive('completePayment', 'partiallyCompletePayment')->andReturnNull();

        return new PrepaymentHandler($prepaymentService, $recorder);
    }

    public function test_early_payment_defers_unaccrued_interest_and_principal_to_prepayment(): void
    {
        // balance=300 000, daily rate=0.1% → interest_payment for a 30-day period is
        // exactly 9 000, and 10 elapsed days accrue exactly 3 000 (clean fixture numbers).
        $contract = new Contract([
            'provided_amount' => 300_000.0,
            'left'            => 300_000.0,
            'interest_rate'   => 0.1,
        ]);
        $contract->id = 42;

        $payment = $this->paymentWithNoPriorEntries([
            'id'                => 7,
            'type'              => 'regular',
            'status'            => 'initial',
            'from_date'         => '2026-06-30',
            'to_date'           => '2026-07-30',
            'date'              => '2026-07-30',
            'principal_payment' => 20_000.0,
            'interest_payment'  => 9_000.0,
            'amount'            => 29_000.0,
        ]);

        $prepaymentService = Mockery::mock(PrepaymentService::class);
        $prepaymentService->shouldReceive('createSingle')
            ->once()
            ->with(42, 7, 99, 20_000.0, 6_000.0, '2026-07-30')
            ->andReturnNull();

        $handler = $this->makeHandler($prepaymentService);

        // Cash covers the full row (9 000 interest + 20 000 principal = 29 000) with
        // 11 000 left over for the next installment (handled by applyRemaining()).
        $result = $handler->handle(
            $contract, $payment, null, true, 99,
            40_000.0, 0.0, '2026-07-10', 300_000.0
        );

        $this->assertEqualsWithDelta(3_000.0, $result['interest_amount'], 0.01, 'Only the 10 accrued days should post as interest income');
        $this->assertEqualsWithDelta(0.0, $result['principal_amount'], 0.01, 'Principal is deferred into the Prepayment record, not posted directly');
        $this->assertEqualsWithDelta(26_000.0, $result['prepayment_principal'], 0.01, 'Prepayment = deferred interest (6 000) + principal (20 000)');
        $this->assertEqualsWithDelta(11_000.0, $result['amount'], 0.01, 'Leftover cash after covering the full row');

        $this->assertEqualsWithDelta(280_000.0, $contract->provided_amount, 0.01, 'Principal reduction still happens immediately');
    }

    public function test_partial_cash_still_splits_only_the_interest_actually_consumed(): void
    {
        // Same fixture, but cash only covers part of the scheduled interest —
        // the accrued portion must be capped by what was actually consumed, not
        // by the full scheduled interest_payment.
        $contract = new Contract([
            'provided_amount' => 300_000.0,
            'left'            => 300_000.0,
            'interest_rate'   => 0.1,
        ]);
        $contract->id = 42;

        $payment = $this->paymentWithNoPriorEntries([
            'id'                => 7,
            'type'              => 'regular',
            'status'            => 'initial',
            'from_date'         => '2026-06-30',
            'to_date'           => '2026-07-30',
            'date'              => '2026-07-30',
            'principal_payment' => 20_000.0,
            'interest_payment'  => 9_000.0,
            'amount'            => 29_000.0,
        ]);

        $prepaymentService = Mockery::mock(PrepaymentService::class);
        // Only 2 000 of interest was consumed by the cash, all of it already accrued
        // (accrued cap is 3 000) → nothing deferred, no principal reached either.
        $prepaymentService->shouldReceive('createSingle')->never();

        $handler = $this->makeHandler($prepaymentService);

        $result = $handler->handle(
            $contract, $payment, null, true, 99,
            2_000.0, 0.0, '2026-07-10', 300_000.0
        );

        $this->assertEqualsWithDelta(2_000.0, $result['interest_amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['principal_amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['prepayment_principal'], 0.01);
    }

    public function test_on_or_after_due_date_nothing_is_deferred_to_prepayment(): void
    {
        $contract = new Contract([
            'provided_amount' => 300_000.0,
            'left'            => 300_000.0,
            'interest_rate'   => 0.1,
        ]);
        $contract->id = 42;

        $payment = $this->paymentWithNoPriorEntries([
            'id'                => 7,
            'type'              => 'regular',
            'status'            => 'initial',
            'from_date'         => '2026-06-30',
            'to_date'           => '2026-07-30',
            'date'              => '2026-07-30',
            'principal_payment' => 20_000.0,
            'interest_payment'  => 9_000.0,
            'amount'            => 29_000.0,
        ]);

        $prepaymentService = Mockery::mock(PrepaymentService::class);
        $prepaymentService->shouldReceive('createSingle')->never();

        $handler = $this->makeHandler($prepaymentService);

        // Paid exactly on the due date (2026-07-30) — the full row is genuinely due,
        // so nothing should be deferred to the bucket.
        $result = $handler->handle(
            $contract, $payment, null, true, 99,
            40_000.0, 0.0, '2026-07-30', 300_000.0
        );

        $this->assertEqualsWithDelta(9_000.0, $result['interest_amount'], 0.01);
        $this->assertEqualsWithDelta(20_000.0, $result['principal_amount'], 0.01);
        $this->assertEqualsWithDelta(0.0, $result['prepayment_principal'], 0.01);
    }
}
