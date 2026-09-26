<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Traits\ContractTrait;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ContractTrait::countPenalty() must charge the penalty on the installment's
 * remaining debt (amount − Σ payment_entries.amount), not on its full amount.
 *
 * Wrapped in DatabaseTransactions — nothing persists in the shared dev database.
 */
class CountPenaltyTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeContractWithOverdueRow(float $amount, float $alreadyPaid): Contract
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
            'date'             => '2026-07-01',
            'status'           => 'initial',
        ]);

        $payment = Payment::create([
            'contract_id'       => $contract->id,
            'pawnshop_id'       => $pawnshop->id,
            'type'              => 'regular',
            'status'            => 'initial',
            'date'              => '2026-08-26 12:00:00', // 30 days before "now"
            'from_date'         => '2026-07-27',
            'to_date'           => '2026-08-26',
            'days'              => 30,
            'principal_payment' => $amount,
            'interest_payment'  => 0,
            'amount'            => $amount,
        ]);

        if ($alreadyPaid > 0) {
            PaymentEntry::create([
                'payment_id'  => $payment->id,
                'contract_id' => $contract->id,
                'pawnshop_id' => $pawnshop->id,
                'amount'      => $alreadyPaid,
                'date'        => '2026-08-26',
            ]);
        }

        return $contract;
    }

    private function countPenalty(Contract $contract): array
    {
        $calculator = new class {
            use ContractTrait;
        };

        return $calculator->countPenalty($contract->id);
    }

    public function test_penalty_is_charged_on_remaining_debt_not_full_amount(): void
    {
        // debt = 100000 − 90000 = 10000 (> 1000 gate); 30 days × 0.13% × 10000 = 390
        $contract = $this->makeContractWithOverdueRow(100000, 90000);

        $result = $this->countPenalty($contract);

        $this->assertSame(30, (int) $result['delay_days']);
        $this->assertEquals(390, $result['penalty_amount']);
        $this->assertEquals(390, $contract->fresh()->penalty_amount);
    }

    public function test_unpaid_row_is_charged_on_full_amount(): void
    {
        // Nothing paid yet: debt == amount → 30 × 0.13% × 100000 = 3900
        $contract = $this->makeContractWithOverdueRow(100000, 0);

        $this->assertEquals(3900, $this->countPenalty($contract)['penalty_amount']);
    }

    public function test_no_penalty_when_remaining_debt_is_at_or_below_1000(): void
    {
        $contract = $this->makeContractWithOverdueRow(100000, 99000);

        $this->assertEquals(0, $this->countPenalty($contract)['penalty_amount']);
    }
}
