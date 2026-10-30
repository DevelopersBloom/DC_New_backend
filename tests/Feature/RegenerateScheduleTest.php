<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\Pawnshop;
use App\Models\ScheduleRegeneration;
use App\Services\Schedule\ScheduleRegenerationException;
use App\Services\Schedule\ScheduleRegenerator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Small copy of A-26-00115 after the 540,000 payment: a completed stub, the CURRENT row
 * (interest + its own principal already paid), and four later rows whose principal was
 * prepaid by principal-only partial_payment entries on the first two of them.
 *
 * Uses the configured MySQL inside a transaction, like RecalculateScheduleTest.
 */
class RegenerateScheduleTest extends TestCase
{
    use DatabaseTransactions;

    private Contract $contract;
    private Payment $current;
    /** @var Payment[] */
    private array $later = [];

    protected function setUp(): void
    {
        parent::setUp();

        $pawnshop = Pawnshop::find(1) ?? tap(new Pawnshop(), function ($p) {
            $p->id = 1;
            $p->save();
        });
        $client = Client::create(['type' => 'individual']);

        $this->contract = Contract::create([
            'client_id'        => $client->id,
            'estimated_amount' => 200000,
            'provided_amount'  => 60000,
            'interest_rate'    => 0.1,
            'penalty'          => 0.13,
            'payment_type'     => 'amortized',
            'payment_day'      => 6,
            'deadline'         => '2027-03-09',
            'pawnshop_id'      => $pawnshop->id,
            'left'             => 60000,
            'collected'        => 0,
            'mother'           => 100000,
            'date'             => '2026-09-09',
            'status'           => 'initial',
            'payment_schedule' => [['date' => '2026-10-06', 'payment' => 1]],
        ]);
        $this->contract->forceFill(['provided_at' => '2026-09-09'])->save();

        $row = fn (array $attrs) => Payment::create(array_merge([
            'contract_id' => $this->contract->id,
            'pawnshop_id' => $pawnshop->id,
            'type'        => 'regular',
            'status'      => 'initial',
        ], $attrs));

        $row([
            'status' => 'completed', 'from_date' => '2026-09-09', 'date' => '2026-10-06', 'to_date' => '2026-10-06',
            'calendar_due_date' => '2026-10-06', 'days' => 27, 'principal_payment' => 0,
            'interest_payment' => 2700, 'amount' => 2700, 'remaining' => 100000,
        ]);

        $this->current = $row([
            'from_date' => '2026-10-06', 'date' => '2026-11-06', 'to_date' => '2026-11-06',
            'calendar_due_date' => '2026-11-06', 'days' => 31, 'principal_payment' => 10000,
            'original_principal_payment' => 10000, 'interest_payment' => 3000,
            'amount' => 13000, 'remaining' => 90000,
        ]);

        foreach ([['2026-12-07', '2026-12-06', 31, 'a'], ['2027-01-07', '2027-01-06', 31, 'b'],
                  ['2027-02-08', '2027-02-06', 32, 'c'], ['2027-03-09', '2027-03-06', 29, 'd']] as $i => [$date, $cal, $days]) {
            $from = $i === 0 ? '2026-11-06' : $this->later[$i - 1]->to_date;
            $this->later[] = $row([
                'from_date' => $from, 'date' => $date, 'to_date' => $date, 'calendar_due_date' => $cal,
                'days' => $days, 'principal_payment' => 22500, 'original_principal_payment' => 22500,
                'interest_payment' => 2000, 'amount' => 24500, 'remaining' => 90000 - 22500 * ($i + 1),
            ]);
        }

        $entry = fn (Payment $p, string $type, float $amount, float $principal, float $interest) => PaymentEntry::create([
            'payment_id' => $p->id, 'contract_id' => $this->contract->id, 'pawnshop_id' => 1, 'user_id' => 1,
            'reference' => (string) \Illuminate\Support\Str::uuid(), 'amount' => $amount,
            'principal_amount' => $principal, 'interest_amount' => $interest, 'penalty_amount' => 0,
            'balance_before' => 100000, 'balance_after' => 60000, 'document_type' => $type, 'date' => '2026-10-30',
        ]);

        $entry($this->current, 'regular_payment', 2400, 0, 2400);
        $entry($this->current, 'partial_payment', 10000, 10000, 0);
        $entry($this->later[0], 'partial_payment', 22500, 22500, 0);
        $entry($this->later[1], 'partial_payment', 7500, 7500, 0);
    }

    private function regenerator(): ScheduleRegenerator
    {
        return app(ScheduleRegenerator::class);
    }

    private function open()
    {
        return Payment::where('contract_id', $this->contract->id)->where('type', 'regular')
            ->where('status', 'initial')->orderBy('to_date')->get();
    }

    public function test_preview_matches_the_plan_and_writes_nothing(): void
    {
        $preview = $this->regenerator()->preview($this->contract, '2026-10-30');

        $this->assertSame(60000.0, $preview['balance']);
        $this->assertSame($this->current->id, $preview['current_row']['id']);
        $this->assertEqualsWithDelta(600.0, $preview['current_row']['due_left'], 0.01);
        $this->assertSame(4, $preview['removed_rows']);
        $this->assertSame(4, $preview['created_rows']);
        $this->assertSame(2, $preview['moved_entries']);
        $this->assertSame('2027-03-09', $preview['new']['last_date']);
        $this->assertCount(5, $this->open());
        $this->assertSame(0, ScheduleRegeneration::count());
    }

    public function test_apply_replaces_later_rows_and_keeps_everything_else(): void
    {
        $entriesBefore = [PaymentEntry::where('contract_id', $this->contract->id)->count(),
            (float) PaymentEntry::where('contract_id', $this->contract->id)->sum('amount')];
        $preview = $this->regenerator()->preview($this->contract, '2026-10-30');

        $result = $this->regenerator()->apply($this->contract->id, '2026-10-30', null, 'test', $preview['state_hash'], null);

        $this->assertTrue($result['changed']);

        $open = $this->open();
        $this->assertCount(5, $open); // current + 4 new
        $this->assertSame($this->current->id, $open[0]->id);
        $this->assertSame('2027-03-09', (string) $open[4]->to_date);
        $this->assertEquals(0.0, (float) $open[4]->remaining);

        // Principal-prepayment entries were re-pointed, not changed.
        $this->assertSame(
            $entriesBefore,
            [PaymentEntry::where('contract_id', $this->contract->id)->count(),
                (float) PaymentEntry::where('contract_id', $this->contract->id)->sum('amount')]
        );
        $current = $this->current->fresh();
        $this->assertEqualsWithDelta(40000.0, (float) $current->principal_payment, 0.01);
        $this->assertEqualsWithDelta(43000.0, (float) $current->amount, 0.01);
        $this->assertEqualsWithDelta(
            600.0,
            (float) $current->amount - (float) $current->entries()->sum('amount'),
            0.01
        );

        // Net principal of the open rows equals the contract balance.
        $net = $open->sum(fn ($p) => (float) $p->principal_payment - (float) $p->entries()->sum('principal_amount'));
        $this->assertEqualsWithDelta(60000.0, $net, 0.05);

        // Replaced rows are soft deleted, never hard deleted.
        $this->assertSame(4, Payment::onlyTrashed()->where('contract_id', $this->contract->id)->count());

        // Header fields untouched, JSON rebuilt from the rows.
        $contract = $this->contract->fresh();
        $this->assertSame('2026-09-09', substr((string) $contract->date, 0, 10));
        $this->assertSame('2027-03-09', substr((string) $contract->deadline, 0, 10));
        $this->assertCount(6, $contract->payment_schedule); // stub + current + 4 new
    }

    public function test_applying_twice_reports_no_change(): void
    {
        $preview = $this->regenerator()->preview($this->contract, '2026-10-30');
        $this->regenerator()->apply($this->contract->id, '2026-10-30', null, 'test', $preview['state_hash'], null);

        $second = $this->regenerator()->apply($this->contract->id, '2026-10-30', null, 'again', null, null);

        $this->assertFalse($second['changed']);
        $this->assertSame(1, ScheduleRegeneration::count());
    }

    public function test_stale_state_hash_is_rejected(): void
    {
        $preview = $this->regenerator()->preview($this->contract, '2026-10-30');
        $this->current->update(['interest_payment' => 3100]);

        try {
            $this->regenerator()->apply($this->contract->id, '2026-10-30', null, 'test', $preview['state_hash'], null);
            $this->fail('Expected STATE_CHANGED');
        } catch (ScheduleRegenerationException $e) {
            $this->assertSame('STATE_CHANGED', $e->errorCode);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_revert_restores_rows_entries_and_current_row(): void
    {
        $entryRows = PaymentEntry::where('contract_id', $this->contract->id)->orderBy('id')->pluck('payment_id', 'id')->all();
        $preview   = $this->regenerator()->preview($this->contract, '2026-10-30');
        $result    = $this->regenerator()->apply($this->contract->id, '2026-10-30', null, 'test', $preview['state_hash'], null);

        $this->regenerator()->revert($result['regeneration_id'], null);

        $this->assertSame($entryRows, PaymentEntry::where('contract_id', $this->contract->id)->orderBy('id')->pluck('payment_id', 'id')->all());
        $this->assertCount(5, $this->open());
        $this->assertSame(0, Payment::onlyTrashed()->where('contract_id', $this->contract->id)->count());
        $current = $this->current->fresh();
        $this->assertEqualsWithDelta(10000.0, (float) $current->principal_payment, 0.01);
        $this->assertEqualsWithDelta(13000.0, (float) $current->amount, 0.01);
        $this->assertNotNull(ScheduleRegeneration::first()->reverted_at);
    }

    public function test_revert_is_blocked_after_a_payment_on_the_new_rows(): void
    {
        $preview = $this->regenerator()->preview($this->contract, '2026-10-30');
        $result  = $this->regenerator()->apply($this->contract->id, '2026-10-30', null, 'test', $preview['state_hash'], null);

        $newRow = $this->open()[1];
        PaymentEntry::create([
            'payment_id' => $newRow->id, 'contract_id' => $this->contract->id, 'pawnshop_id' => 1, 'user_id' => 1,
            'reference' => (string) \Illuminate\Support\Str::uuid(), 'amount' => 100, 'principal_amount' => 0,
            'interest_amount' => 100, 'penalty_amount' => 0, 'balance_before' => 60000, 'balance_after' => 60000,
            'document_type' => 'regular_payment', 'date' => '2026-11-06',
        ]);

        $this->expectException(ScheduleRegenerationException::class);
        $this->regenerator()->revert($result['regeneration_id'], null);
    }

    public function test_refuses_a_replaced_row_with_collected_interest(): void
    {
        PaymentEntry::create([
            'payment_id' => $this->later[2]->id, 'contract_id' => $this->contract->id, 'pawnshop_id' => 1, 'user_id' => 1,
            'reference' => (string) \Illuminate\Support\Str::uuid(), 'amount' => 500, 'principal_amount' => 0,
            'interest_amount' => 500, 'penalty_amount' => 0, 'balance_before' => 60000, 'balance_after' => 60000,
            'document_type' => 'regular_payment', 'date' => '2026-10-30',
        ]);

        $this->assertRefused('ROW_HAS_COLLECTIONS', fn () => $this->regenerator()->preview($this->contract, '2026-10-30'));
    }

    public function test_refuses_overdue_rows_and_dates_before_the_last_payment(): void
    {
        $this->assertRefused('OVERDUE_ROWS', fn () => $this->regenerator()->preview($this->contract, '2026-11-20'));
        $this->assertRefused('DATE_BEFORE_LAST_PAYMENT', fn () => $this->regenerator()->preview($this->contract, '2026-10-20'));
    }

    public function test_refuses_executed_contracts(): void
    {
        $this->contract->update(['status' => 'executed']);

        $this->assertRefused('CONTRACT_NOT_ACTIVE', fn () => $this->regenerator()->preview($this->contract->fresh(), '2026-10-30'));
    }

    public function test_refuses_when_rows_do_not_add_up_to_the_balance(): void
    {
        $this->contract->update(['provided_amount' => 59000]);

        $this->assertRefused('INCONSISTENT_SCHEDULE', fn () => $this->regenerator()->preview($this->contract->fresh(), '2026-10-30'));
    }

    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected {$code}");
        } catch (ScheduleRegenerationException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
