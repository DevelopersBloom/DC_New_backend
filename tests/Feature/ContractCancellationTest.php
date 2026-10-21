<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\DocumentJournal;
use App\Models\LoanApplication;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

/** BE-001: cancelling contracts that were created but never disbursed. */
class ContractCancellationTest extends TestCase
{
    use DatabaseTransactions, LoanApplicationWorld;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
        $this->h = $this->authHeaders($this->userWith(['create_contract', 'cancel_contract', 'pay_contract_amount', 'view_contracts']));
    }

    private function contractPayload(array $over = []): array
    {
        return array_merge([
            'type'               => 'individual',
            'name'               => 'Test',
            'surname'            => 'Cancel',
            'phone'              => '+37411000000',
            'gender'             => 'MALE',
            'document_type'      => 'ID_CARD',
            'passport_series'    => 'AN' . random_int(1000000, 9999999),
            'passport_validity'  => '2030-01-01',
            'passport_issued'    => '2020-01-01 police',
            'date_of_birth'      => '1990-01-01',
            'residency_status'   => 'resident',
            'estimated_amount'   => 520000,
            'provided_amount'    => 400000,
            'deadline'           => 12,
            'interest_rate'      => 24,
            'penalty'            => 0.1,
            'lump_rate'          => 0,
            'payment_type'       => 'classic',
            'category_id'        => $this->category->id,
            'items'              => [[
                'category_id' => $this->category->id, 'subcategory' => 'Ring',
                'weight' => 10, 'clear_weight' => 9, 'hallmark' => '585',
            ]],
        ], $over);
    }

    /** A contract as POST /contracts leaves it: status initial, provided_at null, schedule generated. */
    private function createUndisbursed(array $over = []): Contract
    {
        $res = $this->postJson('/api/contracts', $this->contractPayload($over), $this->h);
        $res->assertCreated();

        return Contract::findOrFail($res->json('contract_id'));
    }

    private function cancel(Contract $contract, array $body = ['reason_code' => 'client_declined', 'reason' => 'Client changed their mind'], ?array $headers = null)
    {
        return $this->postJson("/api/contracts/{$contract->id}/cancel", $body, $headers ?? $this->h);
    }

    public function test_cancelling_an_undisbursed_contract(): void
    {
        $contract = $this->createUndisbursed();
        $itemIds = $contract->items()->pluck('items.id')->all();
        $this->assertNotEmpty($itemIds);
        $this->assertTrue(Payment::where('contract_id', $contract->id)->exists());

        $this->cancel($contract)->assertOk()->assertJsonPath('contract.status', 'cancelled');

        $contract->refresh();
        $this->assertSame(Contract::STATUS_CANCELLED, $contract->status);
        $this->assertNotNull($contract->cancelled_at);
        $this->assertNotNull($contract->cancelled_by);
        $this->assertSame('client_declined', $contract->cancel_reason_code);
        $this->assertSame('Client changed their mind', $contract->cancel_reason);

        // Not hard-deleted; schedule voided (soft), collateral freed.
        $this->assertNull($contract->deleted_at);
        $this->assertFalse(Payment::where('contract_id', $contract->id)->exists());
        $this->assertTrue(Payment::withTrashed()->where('contract_id', $contract->id)->exists());
        $this->assertSame(0, $contract->items()->count());
        $this->assertTrue(ActivityLog::where('action', 'cancel_contract')->where('model_id', $contract->id)->exists());

        // The freed item can be attached to a new contract.
        $other = $this->createUndisbursed();
        $other->items()->attach($itemIds);
        $this->assertSame(count($itemIds), $other->items()->count());
    }

    public function test_reason_is_required(): void
    {
        $contract = $this->createUndisbursed();

        $this->cancel($contract, ['reason_code' => 'other'])->assertStatus(422);
        $this->cancel($contract, ['reason_code' => 'bogus', 'reason' => 'x'])->assertStatus(422);
        $this->assertSame(Contract::STATUS_INITIAL, $contract->fresh()->status);
    }

    public function test_permission_is_required(): void
    {
        $contract = $this->createUndisbursed();
        $noPermission = $this->authHeaders($this->userWith(['view_contracts']));

        $this->cancel($contract, headers: $noPermission)->assertForbidden();
        $this->assertSame(Contract::STATUS_INITIAL, $contract->fresh()->status);
    }

    public function test_loan_application_is_reopened_and_can_be_converted_again(): void
    {
        $application = $this->makeApplication(LoanApplication::STATUS_APPROVED, $this->makeClient());
        $contract = $this->createUndisbursed(['loan_application_id' => $application->id]);
        $this->assertSame(LoanApplication::STATUS_CONVERTED, $application->fresh()->status);

        $this->cancel($contract)->assertOk();

        $application->refresh();
        $this->assertSame(LoanApplication::STATUS_APPROVED, $application->status);
        $this->assertNull($application->contract_id);

        $again = $this->createUndisbursed(['loan_application_id' => $application->id]);
        $this->assertSame($again->id, $application->fresh()->contract_id);
    }

    /** @dataProvider disbursedStates */
    public function test_disbursed_contracts_are_rejected(callable $disburse): void
    {
        $contract = $this->createUndisbursed();
        $disburse($contract);
        $paymentsBefore = Payment::where('contract_id', $contract->id)->count();
        $itemsBefore = $contract->items()->count();

        $this->cancel($contract)->assertStatus(409);

        $contract->refresh();
        $this->assertSame(Contract::STATUS_INITIAL, $contract->status);
        $this->assertNull($contract->cancelled_at);
        $this->assertSame($paymentsBefore, Payment::where('contract_id', $contract->id)->count());
        $this->assertSame($itemsBefore, $contract->items()->count());
    }

    public static function disbursedStates(): array
    {
        return [
            'provided_at set' => [function (Contract $c) {
                $c->provided_at = now()->toDateString();
                $c->save();
            }],
            'has an order' => [function (Contract $c) {
                Order::create([
                    'num' => 'T-1', 'contract_id' => $c->id, 'type' => 'out', 'title' => 'Օրդեր',
                    'pawnshop_id' => $c->pawnshop_id, 'order' => 1, 'amount' => 1, 'rep_id' => '2211',
                    'date' => now()->toDateString(), 'client_name' => 'x', 'purpose' => 'x', 'cash' => true,
                ]);
            }],
            'has a journal entry' => [function (Contract $c) {
                DocumentJournal::create([
                    'date' => now()->toDateString(), 'document_number' => 1,
                    'document_type' => DocumentJournal::PROVIDE_CONTRACT_AMOUNT, 'amount_amd' => 1,
                    'journalable_type' => Contract::class, 'journalable_id' => $c->id, 'contract_id' => $c->id,
                ]);
            }],
            'has provided amount history' => [function (Contract $c) {
                ContractAmountHistory::create([
                    'contract_id' => $c->id, 'amount' => 1, 'amount_type' => 'provided_amount', 'type' => 'in',
                    'date' => now()->toDateString(), 'category_id' => $c->category_id, 'pawnshop_id' => $c->pawnshop_id,
                ]);
            }],
        ];
    }

    public function test_finished_and_cancelled_contracts_are_rejected(): void
    {
        foreach ([Contract::STATUS_COMPLETED, Contract::STATUS_EXECUTED] as $status) {
            $contract = $this->createUndisbursed();
            $contract->update(['status' => $status]);
            $this->cancel($contract)->assertStatus(409);
            $this->assertSame($status, $contract->fresh()->status);
        }

        $contract = $this->createUndisbursed();
        $this->cancel($contract)->assertOk();
        $this->cancel($contract)->assertStatus(409);
    }

    public function test_pay_amount_rejects_cancelled_and_already_disbursed_contracts(): void
    {
        $contract = $this->createUndisbursed();
        $this->cancel($contract)->assertOk();
        $this->postJson('/api/contracts/pay-amount', ['contract_id' => $contract->id], $this->h)->assertStatus(409);
        $this->assertNull($contract->fresh()->provided_at);

        $disbursed = $this->createUndisbursed();
        $disbursed->provided_at = '2026-01-15';
        $disbursed->save();
        $paymentsBefore = Payment::where('contract_id', $disbursed->id)->count();

        $this->postJson('/api/contracts/pay-amount', ['contract_id' => $disbursed->id], $this->h)->assertStatus(409);
        $this->assertSame('2026-01-15', (string) $disbursed->fresh()->provided_at);
        $this->assertSame($paymentsBefore, Payment::where('contract_id', $disbursed->id)->count());
    }

    public function test_contract_numbers_are_never_reused(): void
    {
        $first = $this->createUndisbursed();
        $this->cancel($first)->assertOk();
        $second = $this->createUndisbursed();
        $this->assertNotSame(substr($first->num, -5), substr($second->num, -5));

        // Soft-deleting the newest contract must not hand its number out again.
        $second->delete();
        $third = $this->createUndisbursed();
        $this->assertNotSame(substr($second->num, -5), substr($third->num, -5));
        $this->assertGreaterThan((int) substr($second->num, -5), (int) substr($third->num, -5));
    }

    public function test_undisbursed_and_cancelled_contracts_are_excluded_from_active_and_overdue(): void
    {
        $awaiting = $this->createUndisbursed();
        $cancelled = $this->createUndisbursed();
        $this->cancel($cancelled)->assertOk();

        // Make the awaiting contract's schedule look overdue and due today.
        Payment::where('contract_id', $awaiting->id)->orderBy('id')->limit(1)->update(['date' => now()->subDays(40)->toDateString()]);
        Payment::where('contract_id', $awaiting->id)->orderByDesc('id')->limit(1)->update(['date' => now()->toDateString()]);

        $ids = [$awaiting->id, $cancelled->id];
        foreach (['initial', 'overdue', 'todays'] as $status) {
            $this->assertSame(0, Contract::whereIn('id', $ids)->filterStatus($status)->count(), $status);
        }

        $this->assertSame([$awaiting->id], Contract::whereIn('id', $ids)->filterStatus('awaiting_disbursement')->pluck('id')->all());
        $this->assertSame([$cancelled->id], Contract::whereIn('id', $ids)->filterStatus('cancelled')->pluck('id')->all());

        $awaiting->refresh();
        $this->assertFalse($awaiting->is_overdue);
        $this->assertEquals(0, $awaiting->not_payed_amount);
        $this->assertTrue($awaiting->is_awaiting_disbursement);
        $this->assertSame(0, $awaiting->client->activeContracts()->whereIn('contracts.id', $ids)->count());

        // Once disbursed it counts as an active contract again.
        $awaiting->provided_at = now()->toDateString();
        $awaiting->save();
        $this->assertSame(1, Contract::whereKey($awaiting->id)->filterStatus('initial')->count());
        $this->assertFalse($awaiting->fresh()->is_awaiting_disbursement);
    }

    public function test_contracts_list_returns_cancellation_fields(): void
    {
        $contract = $this->createUndisbursed();
        $this->cancel($contract)->assertOk();

        $res = $this->getJson('/api/contracts?status=cancelled&num=' . $contract->num, $this->h)->assertOk();

        $row = collect($res->json('contracts.data'))->firstWhere('id', $contract->id);
        $this->assertNotNull($row);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame('Client changed their mind', $row['cancel_reason']);
        $this->assertNotNull($row['cancelled_at']);
        $this->assertSame(0, $row['days_since_created']);
        $this->assertArrayHasKey('cancelled', $res->json());
        $this->assertArrayHasKey('awaiting_disbursement', $res->json());
    }
}
