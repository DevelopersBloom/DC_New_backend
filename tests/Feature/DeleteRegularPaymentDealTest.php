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
 * (and, separately, previews it through previewDeleteDeal()) and checks what
 * the user specifically asked about:
 *
 *  1. The scheduled `payments` row itself must NOT be deleted — only its
 *     paid-state (status) should be undone.
 *  2. The DealAction shape that makePayment/PaymentService actually write today
 *     is asserted explicitly (the 'Regular payment' description). If a future
 *     change to PaymentService/PaymentEntryRecorder changes that shape, this
 *     assertion fails loudly instead of RegularPaymentDealReversalService
 *     silently reverting the wrong/no fields.
 *  3. Contract, PaymentEntry, DealAction, DocumentJournal and Modification
 *     state are all asserted back to their exact pre-payment snapshot.
 *  4. The pawnshop cashbox is asserted back to its pre-payment value —
 *     createDeal() adds the payment amount to it, and nothing reversed that
 *     until RegularPaymentDealReversalService started calling
 *     its own reverseCashboxMovement().
 *  5. The preview endpoint (GET .../delete-deal/{id}/preview) returns the same
 *     kind of table-by-table diff the full-payment preview does, and — being a
 *     dry run — leaves the database completely untouched.
 *
 * Note on Contract::left / provided_amount: RegularPaymentDealReversalService
 * itself only restores `collected`. left/provided_amount come back via a
 * separate, easy-to-miss mechanism —
 * DocumentJournal::syncContractProvidedAmountOnMotherPaymentDelete(), which
 * fires automatically when Deal::delete()'s cascade deletes the deal's
 * PAY_MOTHER_AMOUNT journal row. An earlier pass at this fix added an explicit
 * left/provided_amount restore in the reversal path on the assumption that
 * nothing else did it — that turned out to double-count with this cascade
 * (200000 correct + 100000 re-added = 300000), and was caught by running this
 * test against a real database rather than by static reading.
 *
 * Wrapped in DatabaseTransactions so the whole test runs inside one DB
 * transaction that is rolled back at the end — nothing persists in the shared
 * dev database regardless of pass/fail.
 *
 * Verified passing against a live MySQL-compatible database (migrations +
 * TypeSeeder/ChartOfAccountsSeeder/PostingRuleSeeder/PawnshopSeeder/CurrencySeeder).
 */
class DeleteRegularPaymentDealTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Sets up a minimal amortized contract and pays off row 1 exactly on its
     * due date via the real makePayment() flow, returning everything a test
     * needs to then preview and/or delete that deal.
     *
     * @return array{pawnshop: Pawnshop, initialCashbox: float, contract: Contract,
     *     row1: Payment, row2: Payment, deal: Deal, payAmount: float}|null
     *     null when required reference data isn't seeded (caller should
     *     markTestSkipped in that case).
     */
    private function payOffRow1(): ?array
    {
        $interestRule = PostingRule::where('business_event_filter', 'pay_interest_amount_cash')->first();
        $motherRule   = PostingRule::where('business_event_filter', 'pay_mother_amount_cash')->first();
        $historyType  = HistoryType::where('name', 'regular_payment')->first();

        if (!$interestRule || !$motherRule || !$historyType) {
            return null;
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

        $initialCashbox = (float) $pawnshop->cashbox;

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

        // PostingDatePolicy requires the payment date to be exactly today unless
        // the user has the admin role, so these are relative to now() rather than
        // a fixed calendar date.
        $contractDate = Carbon::now()->subDays(30);
        $row1Due      = Carbon::now();
        $row2Due      = Carbon::now()->addDays(30);

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
        $this->assertEquals(
            $initialCashbox + $payAmount,
            (float) $pawnshop->refresh()->cashbox,
            'cash received should have been added to the pawnshop cashbox'
        );

        $dealActionDescriptions = DealAction::where('deal_id', $deal->id)->pluck('description')->all();
        $this->assertContains(
            'Regular payment',
            $dealActionDescriptions,
            "makePayment is expected to log a 'Regular payment' DealAction via PaymentEntryRecorder. "
            . 'If this assertion fails, PaymentService/PaymentEntryRecorder changed shape and '
            . 'RegularPaymentDealReversalService must be updated to match — that is exactly '
            . 'the "old version" mismatch risk this test guards against.'
        );

        return compact('pawnshop', 'initialCashbox', 'contract', 'row1', 'row2', 'deal', 'payAmount');
    }

    public function test_deleting_a_regular_payment_deal_fully_reverts_the_payment(): void
    {
        $state = $this->payOffRow1();
        if ($state === null) {
            $this->markTestSkipped('Required posting rules / history type are not seeded in this database.');
        }
        ['pawnshop' => $pawnshop, 'initialCashbox' => $initialCashbox, 'contract' => $contract,
            'row1' => $row1, 'row2' => $row2, 'deal' => $deal] = $state;

        // --- now revert it via the new delete-deal path -----------------------------
        $deleteResponse = $this->deleteJson('/api/admin/delete-deal/' . $deal->id);
        $deleteResponse->assertStatus(200);
        $deleteResponse->assertJsonStructure(['message', 'diff', 'warnings', 'skipped_modifications']);

        $contract->refresh();

        // The scheduled Payment row itself must survive the revert — only its
        // paid-state is undone. This is the specific concern the user raised.
        $this->assertNotNull(Payment::find($row1->id), 'row 1 must NOT be deleted by the revert, only reset to initial');
        $this->assertSame('initial', $row1->refresh()->status, 'row 1 status must be restored to initial');
        $this->assertSame('initial', $row2->refresh()->status, 'row 2 must remain initial');

        $this->assertEquals(200000.0, (float) $contract->provided_amount, 'provided_amount must return to its pre-payment value');
        $this->assertEquals(200000.0, (float) $contract->left, 'left must return to its pre-payment value');
        $this->assertEquals(0.0, (float) $contract->collected, 'collected must return to its pre-payment value');
        $this->assertEquals(
            $initialCashbox,
            (float) $pawnshop->refresh()->cashbox,
            'cashbox must return to its pre-payment value'
        );

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

    /**
     * The preview endpoint used to hard-code 'Preview not supported for this
     * deal type' for anything but filter_type=full_payment. This checks that
     * a regular-payment deal now gets a real diff, and — the whole point of a
     * preview — that building it doesn't change anything: the deal, contract,
     * payments, and cashbox are all still in their post-payment state
     * afterwards, and a real delete run right after produces the same kind of
     * result as the preview promised.
     */
    public function test_previewing_a_regular_payment_deal_returns_a_diff_without_changing_anything(): void
    {
        $state = $this->payOffRow1();
        if ($state === null) {
            $this->markTestSkipped('Required posting rules / history type are not seeded in this database.');
        }
        ['pawnshop' => $pawnshop, 'initialCashbox' => $initialCashbox, 'contract' => $contract,
            'row1' => $row1, 'row2' => $row2, 'deal' => $deal] = $state;

        $previewResponse = $this->getJson('/api/admin/delete-deal/' . $deal->id . '/preview');
        $previewResponse->assertStatus(200);
        $previewResponse->assertJsonStructure(['sections', 'warnings']);

        $preview = $previewResponse->json();
        $this->assertNotSame(
            'Preview not supported for this deal type',
            $preview['message'] ?? null,
            'regular-payment deals should now get a real preview, not the full-payment-only placeholder'
        );
        $this->assertNotEmpty($preview['sections'], 'the preview should list at least one table it would change');

        $tables = array_column($preview['sections'], 'table');
        $this->assertContains('pawnshops', $tables, 'the preview should show the cashbox reversal');
        $this->assertContains('deals', $tables, 'the preview should show the deal itself being deleted');

        // --- the preview must be a true dry run: nothing persisted -----------------
        $contract->refresh();
        $this->assertSame('completed', $row1->refresh()->status, 'preview must not actually revert row 1');
        $this->assertEquals(100000.0, (float) $contract->provided_amount, 'preview must not touch provided_amount');
        $this->assertEquals(100000.0, (float) $contract->left, 'preview must not touch left');
        $this->assertEquals(6000.0, (float) $contract->collected, 'preview must not touch collected');
        $this->assertEquals(
            $initialCashbox + $state['payAmount'],
            (float) $pawnshop->refresh()->cashbox,
            'preview must not touch the cashbox'
        );
        $this->assertNotNull(Deal::find($deal->id), 'preview must not delete the deal');
        $this->assertGreaterThan(0, DealAction::where('deal_id', $deal->id)->count(), 'preview must not delete deal actions');

        // --- a real delete right after should still work, and match the preview ---
        $deleteResponse = $this->deleteJson('/api/admin/delete-deal/' . $deal->id);
        $deleteResponse->assertStatus(200);

        $this->assertSame('initial', $row1->refresh()->status, 'the real delete must still fully revert row 1');
        $this->assertEquals(200000.0, (float) $contract->refresh()->provided_amount);
        $this->assertEquals(
            $initialCashbox,
            (float) $pawnshop->refresh()->cashbox,
            'the real delete must still restore the cashbox'
        );
        $this->assertNull(Deal::find($deal->id));
    }
}
