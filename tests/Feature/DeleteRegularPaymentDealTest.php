<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientClassification;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\HistoryType;
use App\Models\Modification;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\PostingRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Exercises the real PaymentControllerNew::makePayment() flow against a minimal
 * amortized contract, then reverts it through AdminControllerNew::deleteDeal()
 * and checks three things the user specifically asked about:
 *
 *  1. The scheduled `payments` row itself must NOT be deleted — only its
 *     paid-state (status) should be undone.
 *  2. The DealAction shape that makePayment/PaymentService actually write today
 *     is asserted explicitly (the 'Regular payment' description). If a future
 *     change to PaymentService/PaymentEntryRecorder changes that shape, this
 *     assertion fails loudly instead of handleRegularPaymentDeal() silently
 *     reverting the wrong/no fields.
 *  3. Contract, PaymentEntry, DealAction, DocumentJournal and Modification
 *     state are all asserted back to their exact pre-payment snapshot.
 *
 * Wrapped in DatabaseTransactions so the whole test runs inside one DB
 * transaction that is rolled back at the end — nothing persists in the shared
 * dev database regardless of pass/fail.
 *
 * NOTE: this test has not been executed in this environment (no reachable
 * MySQL instance here — see conversation). Please run it locally and adjust
 * any field-level assumptions (posting rule names, seeded reference data)
 * that don't match your actual dev DB before relying on it.
 */
class DeleteRegularPaymentDealTest extends TestCase
{
    use DatabaseTransactions;

    public function test_deleting_a_regular_payment_deal_fully_reverts_the_payment(): void
    {
        $interestRule = PostingRule::where('business_event_filter', 'pay_interest_amount_cash')->first();
        $motherRule   = PostingRule::where('business_event_filter', 'pay_mother_amount_cash')->first();
        $historyType  = HistoryType::where('name', 'regular_payment')->first();

        if (!$interestRule || !$motherRule || !$historyType) {
            $this->markTestSkipped('Required posting rules / history type are not seeded in this database.');
        }

        // ContractTrait::createDeal() (called from makePayment) hardcodes pawnshop_id = 1
        // for the deal's own pawnshop bookkeeping (unrelated to auth()->user()->pawnshop_id),
        // so pawnshop #1 must exist or Deal creation fatals on a null Pawnshop. Reuse it if
        // already seeded (PawnshopSeeder normally provides it), otherwise create it here —
        // rolled back with everything else by DatabaseTransactions.
        $pawnshop = Pawnshop::find(1);
        if (!$pawnshop) {
            $pawnshop = new Pawnshop();
            $pawnshop->id = 1;
            $pawnshop->save();
        }

        $user = User::factory()->create(['pawnshop_id' => $pawnshop->id]);

        $classification = ClientClassification::create([
            'title'           => 'Standard',
            'name'            => 'standard',
            'reserve_percent' => 0,
        ]);

        $client = Client::create([
            'type'              => 'individual',
            'classification_id' => $classification->id,
        ]);

        $contractDate = Carbon::parse('2026-01-01');
        $row1Due      = (clone $contractDate)->addDays(30);
        $row2Due      = (clone $contractDate)->addDays(60);

        $contract = Contract::create([
            'client_id'        => $client->id,
            'estimated_amount' => 200000,
            'provided_amount'  => 200000,
            'interest_rate'    => 0.1, // 0.1% daily
            'penalty'          => 13,
            'payment_type'     => 'amortized',
            'deadline'         => $row2Due->format('Y-m-d'),
            'pawnshop_id'      => $pawnshop->id,
            'left'             => 200000,
            'collected'        => 0,
            'mother'           => 200000,
            'date'             => $contractDate->format('Y-m-d'),
            'status'           => 'initial',
        ]);

        $row1 = Payment::create([
            'contract_id'       => $contract->id,
            'pawnshop_id'       => $pawnshop->id,
            'type'              => 'regular',
            'status'            => 'initial',
            'date'              => $row1Due->format('Y-m-d'),
            'from_date'         => $contractDate->format('Y-m-d'),
            'to_date'           => $row1Due->format('Y-m-d'),
            'days'              => 30,
            'principal_payment' => 100000,
            'interest_payment'  => 6000,
            'amount'            => 106000,
            'remaining'         => 200000,
        ]);

        $row2 = Payment::create([
            'contract_id'       => $contract->id,
            'pawnshop_id'       => $pawnshop->id,
            'type'              => 'regular',
            'status'            => 'initial',
            'date'              => $row2Due->format('Y-m-d'),
            'from_date'         => $row1Due->format('Y-m-d'),
            'to_date'           => $row2Due->format('Y-m-d'),
            'days'              => 30,
            'principal_payment' => 100000,
            'interest_payment'  => 3000,
            'amount'            => 103000,
            'remaining'         => 100000,
            'last_payment'      => true,
        ]);

        DocumentJournal::create([
            'date'             => $contractDate->format('Y-m-d'),
            'document_type'    => DocumentJournal::PROVIDE_CONTRACT_AMOUNT,
            'journalable_type' => Contract::class,
            'journalable_id'   => $contract->id,
            'contract_id'      => $contract->id,
            'pawnshop_id'      => $pawnshop->id,
        ]);

        $this->actingAs($user);
        $this->withoutMiddleware();

        // Exactly row 1's interest + principal, paid exactly on its due date:
        // stays well below the full-payoff threshold (row2 is still outstanding)
        // and avoids the SOONER/prepayment and late/penalty branches entirely,
        // so this exercises the plain "pay one scheduled installment" path.
        $payAmount = 106000;

        $response = $this->postJson('/api/contracts/make-payment', [
            'contract_id'            => $contract->id,
            'amount'                 => $payAmount,
            'cash'                   => true,
            'contract_created_date'  => $row1Due->format('Y-m-d'),
        ]);

        $response->assertStatus(200);

        $deal = Deal::where('contract_id', $contract->id)
            ->where('filter_type', 'payment')
            ->latest('id')
            ->first();

        $this->assertNotNull($deal, 'makePayment should have created a filter_type=payment deal');

        $row1->refresh();
        $contract->refresh();

        // --- sanity on the "after payment / before revert" state -------------------
        $this->assertSame('completed', $row1->status, 'row 1 should be fully paid off by this payment');
        $this->assertSame('initial', $row2->refresh()->status, 'row 2 must be untouched');
        $this->assertGreaterThan(0, PaymentEntry::where('deal_id', $deal->id)->count(), 'payment entries should have been recorded');
        $this->assertEquals(6000.0, (float) $deal->interest_amount, 'deal should record the exact interest collected (this is what the revert subtracts back from Contract::collected)');
        $this->assertEquals(100000.0, (float) $contract->provided_amount, 'row 1 principal should have been subtracted from provided_amount');
        $this->assertEquals(100000.0, (float) $contract->left, 'row 1 principal should have been subtracted from left');
        $this->assertEquals(6000.0, (float) $contract->collected, 'row 1 interest should have been added to collected');

        $dealActionDescriptions = DealAction::where('deal_id', $deal->id)->pluck('description')->all();
        $this->assertContains(
            'Regular payment',
            $dealActionDescriptions,
            "makePayment is expected to log a 'Regular payment' DealAction via PaymentEntryRecorder. "
            . 'If this assertion fails, PaymentService/PaymentEntryRecorder changed shape and '
            . 'AdminControllerNew::handleRegularPaymentDeal() must be updated to match — that is exactly '
            . 'the "old version" mismatch risk this test guards against.'
        );

        // --- now revert it via the new delete-deal path -----------------------------
        $deleteResponse = $this->deleteJson('/api/admin/delete-deal/' . $deal->id);
        $deleteResponse->assertStatus(200);

        $contract->refresh();

        // The scheduled Payment row itself must survive the revert — only its
        // paid-state is undone. This is the specific concern the user raised.
        $this->assertNotNull(Payment::find($row1->id), 'row 1 must NOT be deleted by the revert, only reset to initial');
        $this->assertSame('initial', $row1->refresh()->status, 'row 1 status must be restored to initial');
        $this->assertSame('initial', $row2->refresh()->status, 'row 2 must remain initial');

        $this->assertEquals(200000.0, (float) $contract->provided_amount, 'provided_amount must return to its pre-payment value');
        $this->assertEquals(200000.0, (float) $contract->left, 'left must return to its pre-payment value');
        $this->assertEquals(0.0, (float) $contract->collected, 'collected must return to its pre-payment value');

        $this->assertSame(0, PaymentEntry::where('deal_id', $deal->id)->count(), 'all PaymentEntry rows for the deal must be gone');
        $this->assertSame(0, DealAction::where('deal_id', $deal->id)->count(), 'all DealAction rows for the deal must be gone');
        $this->assertSame(0, DocumentJournal::where('deal_id', $deal->id)->count(), 'all DocumentJournal rows for the deal must be gone (cascaded by Deal::delete())');
        $this->assertNull(Deal::find($deal->id), 'the deal itself must be gone (soft-deleted)');

        $this->assertSame(
            0,
            Modification::where('subject_type', Contract::class)
                ->where('subject_id', $contract->id)
                ->where('effective_date', $deal->date)
                ->whereIn('field_code', ['PrincipalAmount', 'PercentsPaid', 'AmountsPaid'])
                ->count(),
            'Modification rows created for this payment must be removed (none of them had been sent to the registry)'
        );
    }
}
