<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\Payment;
use App\Services\Payments\PaymentEntryRecorder;
use App\Services\Payments\ScheduledPaymentHandler;
use Carbon\Carbon;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class PaymentServiceAmortizedAllocationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function makeHandler(): ScheduledPaymentHandler
    {
        return new ScheduledPaymentHandler(Mockery::mock(PaymentEntryRecorder::class));
    }

    public function test_try_early_amortized_payment_split_returns_null_when_as_of_before_period_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Asia/Yerevan'));

        $handler  = $this->makeHandler();
        $contract = new Contract([
            'provided_amount' => 100_000.0,
            'interest_rate'   => 0.11,
        ]);
        $payment = new Payment([
            'type'              => 'regular',
            'status'            => 'initial',
            'from_date'         => '2026-06-10',
            'to_date'           => '2026-07-08',
            'date'              => '2026-07-08',
            'principal_payment' => 50_000.0,
            'interest_payment'  => 3_000.0,
        ]);

        $m = new ReflectionMethod(ScheduledPaymentHandler::class, 'tryEarlyAmortizedPaymentSplit');
        $m->setAccessible(true);

        $this->assertNull($m->invoke($handler, $contract, $payment, 60_000.0, '2026-06-01'));
    }

    public function test_try_early_amortized_payment_split_uses_payment_date_not_server_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00', 'Asia/Yerevan'));

        $handler  = $this->makeHandler();
        $contract = new Contract([
            'provided_amount' => 100_000.0,
            'interest_rate'   => 0.11,
        ]);
        $payment = new Payment([
            'type'              => 'regular',
            'status'            => 'initial',
            'from_date'         => '2026-06-01',
            'to_date'           => '2026-06-29',
            'date'              => '2026-06-29',
            'principal_payment' => 40_000.0,
            'interest_payment'  => 2_000.0,
        ]);

        $m = new ReflectionMethod(ScheduledPaymentHandler::class, 'tryEarlyAmortizedPaymentSplit');
        $m->setAccessible(true);

        $earlyJune = $m->invoke($handler, $contract, $payment, 55_000.0, '2026-06-10');
        $this->assertNotNull($earlyJune);

        $midJune = $m->invoke($handler, $contract, $payment, 55_000.0, '2026-06-18');
        $this->assertNotNull($midJune);

        $this->assertNotEquals(
            round((float) $earlyJune['paid_interest'], 4),
            round((float) $midJune['paid_interest'], 4),
            'Backdated as-of dates must change elapsed vs future interest split'
        );
    }

    public function test_try_early_split_matches_closed_form_for_known_fixture(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00', 'Asia/Yerevan'));

        $P    = 100_000.0;
        $rate = 0.11;

        $contract = new Contract([
            'provided_amount' => $P,
            'interest_rate'   => $rate,
        ]);
        $payment = new Payment([
            'type'              => 'regular',
            'status'            => 'initial',
            'from_date'         => '2026-06-01',
            'to_date'           => '2026-06-29',
            'date'              => '2026-06-29',
            'principal_payment' => 35_000.0,
            'interest_payment'  => 1_500.0,
        ]);

        $asOf = Carbon::parse('2026-06-10', 'Asia/Yerevan')->startOfDay();
        $from = Carbon::parse('2026-06-01', 'Asia/Yerevan')->startOfDay();
        $due  = Carbon::parse('2026-06-29', 'Asia/Yerevan')->startOfDay();

        $elapsedDays = max(1, $from->diffInDays($asOf));
        $futureDays  = $asOf->diffInDays($due);
        $this->assertGreaterThanOrEqual(1, $futureDays);

        $cash         = 50_000.0;
        $pastInterest = $P * $elapsedDays * $rate / 100;
        $kFuture      = $futureDays * ($rate / 100);
        $denom        = 1 - $kFuture;
        $x            = ($cash - $pastInterest - $P * $kFuture) / $denom;
        $x            = min(max(0, $x), $P, $cash);
        $futureInterest      = max(0, max(0, $P - $x) * (int) $futureDays * ($rate / 100));
        $expectedPaidInterest = $pastInterest + $futureInterest;

        $handler = $this->makeHandler();
        $m       = new ReflectionMethod(ScheduledPaymentHandler::class, 'tryEarlyAmortizedPaymentSplit');
        $m->setAccessible(true);
        $split = $m->invoke($handler, $contract, $payment, $cash, $asOf->toDateString());

        $this->assertNotNull($split);
        $this->assertEqualsWithDelta(
            $expectedPaidInterest,
            (float) $split['paid_interest'],
            0.02,
            'Split interest should match the same closed form used in ScheduledPaymentHandler'
        );
    }
}
