<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ScheduledPaymentHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * T6: ScheduledPaymentHandler::recalculateSchedule() used to throw ArgumentCountError
 * because recalculateInterest() had a required $timing after optional parameters.
 *
 * Wrapped in DatabaseTransactions — nothing persists in the shared dev database.
 */
class RecalculateScheduleTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAmortizedContract(): Contract
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00'));

        $pawnshop = Pawnshop::find(1);
        if (!$pawnshop) {
            $pawnshop = new Pawnshop();
            $pawnshop->id = 1;
            $pawnshop->save();
        }

        $client = Client::create(['type' => 'individual']);

        $contract = Contract::create([
            'client_id'        => $client->id,
            'estimated_amount' => 200000,
            'provided_amount'  => 200000,
            'interest_rate'    => 0.1,
            'penalty'          => 0.13,
            'payment_type'     => 'amortized',
            'deadline'         => '2026-12-31',
            'pawnshop_id'      => $pawnshop->id,
            'left'             => 200000,
            'collected'        => 0,
            'mother'           => 200000,
            'date'             => '2026-09-01',
            'status'           => 'initial',
        ]);

        foreach ([['2026-09-01', '2026-10-01'], ['2026-10-01', '2026-11-01']] as [$from, $to]) {
            Payment::create([
                'contract_id'       => $contract->id,
                'pawnshop_id'       => $pawnshop->id,
                'type'              => 'regular',
                'status'            => 'initial',
                'date'              => $to,
                'from_date'         => $from,
                'to_date'           => $to,
                'days'              => 30,
                'principal_payment' => 100000,
                'interest_payment'  => 0,
                'amount'            => 100000,
            ]);
        }

        return $contract;
    }

    public function test_recalculate_schedule_runs_without_timing(): void
    {
        $contract = $this->makeAmortizedContract();

        app(ScheduledPaymentHandler::class)->recalculateSchedule($contract);
        app(ScheduledPaymentHandler::class)->recalculateSchedule($contract, '2026-09-25');
        app(PaymentService::class)->recalculateAmortizedSchedule($contract, '2026-09-25');

        $rows = Payment::where('contract_id', $contract->id)->orderBy('to_date')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            // Null timing must not close rows: that logic belongs to early payments only.
            $this->assertSame('initial', $row->status);
            $this->assertGreaterThan(0, (float) $row->interest_payment);
        }
    }
}
