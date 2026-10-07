<?php

namespace Tests\Feature;

use App\Services\Reports\CreditActivityReportService;
use App\Services\Reports\PaymentHistoryResolver;
use App\Services\Reports\PortfolioManagementService;
use App\Services\Reports\PortfolioQualityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

/**
 * Tranche 4.1: historical repayments from deals (deal_actions allocations) before payment_entries existed.
 * Source rule per (deal, row): zeroing action -> action; else entries if present; else action amount. Never both.
 * "Today" is 2026-10-07. Month N reports as of the last day of month N.
 */
class PaymentHistoryReconstructionTest extends TestCase
{
    use DatabaseTransactions, LoanApplicationWorld;

    private int $ps;
    private int $cat;
    private int $userId;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
        $this->ps = DB::table('pawnshops')->insertGetId([]);
        $this->cat = DB::table('categories')->insertGetId(['name' => 't41', 'title' => 'T41']);
        $this->userId = $this->userWith([])->id;
    }

    // ---------------------------------------------------------------- helpers

    private function q(): PortfolioQualityService
    {
        $base = new CreditActivityReportService(Carbon::parse('2026-10-07', 'Asia/Yerevan'));
        return new PortfolioQualityService($base, new PortfolioManagementService($base));
    }

    private function report(int $m, ?int $ps = null): array
    {
        return $this->q()->build($ps ?? $this->ps, $m, 2026);
    }

    private function contract(float $out = 1000000, array $o = []): int
    {
        $o += ['ps' => $this->ps, 'status' => 'initial', 'closed_at' => null];
        $id = DB::table('contracts')->insertGetId([
            'client_id' => DB::table('clients')->insertGetId([]), 'category_id' => $this->cat, 'pawnshop_id' => $o['ps'], 'num' => 'T41-' . ++$this->seq . '-' . $o['ps'],
            'estimated_amount' => 0, 'provided_amount' => $out, 'deadline' => '2031-01-01', 'status' => $o['status'], 'closed_at' => $o['closed_at'],
            'created_at' => '2026-02-01 10:00:00', 'updated_at' => now(),
        ]);
        foreach (['estimated_amount' => $out * 2, 'provided_amount' => $out] as $t => $amount) {
            DB::table('contract_amount_histories')->insert(['contract_id' => $id, 'amount_type' => $t, 'type' => 'in', 'amount' => $amount, 'date' => '2026-02-01',
                'category_id' => $this->cat, 'pawnshop_id' => $o['ps'], 'created_at' => now(), 'updated_at' => now()]);
        }
        return $id;
    }

    /** A schedule row in its CURRENT state (p/i). */
    private function row(int $c, string $due, float $p, float $i, string $status = 'initial'): int
    {
        return DB::table('payments')->insertGetId(['contract_id' => $c, 'date' => $due, 'to_date' => $due, 'amount' => $p + $i, 'principal_payment' => $p, 'interest_payment' => $i,
            'type' => 'regular', 'status' => $status, 'created_at' => '2026-02-01 10:00:00', 'updated_at' => now()]);
    }

    private function deal(int $contract, string $date, float $amount, array $o = []): int
    {
        $o += ['type' => 'in', 'filter' => 'payment', 'deleted' => false, 'ps' => $this->ps];
        return DB::table('deals')->insertGetId(['type' => $o['type'], 'filter_type' => $o['filter'], 'amount' => $amount, 'date' => $date, 'cash' => true, 'contract_id' => $contract,
            'pawnshop_id' => $o['ps'], 'deleted_at' => $o['deleted'] ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** deal_actions row for a row payment. $after !== null => the row was zeroed in place (history has new_amount). */
    private function action(int $deal, int $row, string $date, float $before, ?float $after, float $oldP, float $oldI, ?float $amount = null): void
    {
        $change = ['payment_id' => $row, 'old_amount' => $before, 'old_principal' => $oldP, 'old_interest' => $oldI];
        if ($after !== null) {
            $change['new_amount'] = $after;
        }
        DB::table('deal_actions')->insert(['deal_id' => $deal, 'actionable_type' => 'App\\Models\\Payment', 'actionable_id' => $row, 'amount' => $amount ?? ($before - ($after ?? 0)),
            'type' => 'regular', 'description' => 'Regular payment', 'date' => $date, 'created_by' => 1, 'updated_by' => 1, 'history' => json_encode(['payment_changes' => [$change]]), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function entry(int $deal, int $row, int $contract, string $date, float $p, float $i, string $type = 'regular_payment'): void
    {
        DB::table('payment_entries')->insert(['payment_id' => $row, 'contract_id' => $contract, 'deal_id' => $deal, 'pawnshop_id' => $this->ps, 'user_id' => $this->userId,
            'reference' => 'T41-' . uniqid('', true), 'amount' => $p + $i, 'principal_amount' => $p, 'interest_amount' => $i, 'penalty_amount' => 0, 'balance_before' => 0, 'balance_after' => 0,
            'document_type' => $type, 'date' => $date, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------------------------------------------------------------- deal classification

    public function test_only_valid_repayment_deals_enter_the_payment_layer(): void
    {
        $c = $this->contract();
        $ok = $this->deal($c, '2026-05-10', 1000);
        $full = $this->deal($c, '2026-05-11', 2000, ['filter' => 'full_payment']);
        $this->deal($c, '2026-05-12', 3000, ['type' => 'cost_out', 'filter' => 'expense']);   // expense
        $this->deal($c, '2026-05-13', 4000, ['filter' => 'ndm']);                               // lender funds
        $this->deal($c, '2026-05-14', 5000, ['filter' => null]);                                // unrelated cash inflow
        $this->deal($c, '2026-05-15', 6000, ['filter' => 'contract']);                          // one-off fee, not a loan repayment
        $this->deal($c, '2026-05-16', 7000, ['deleted' => true]);                               // soft-deleted
        $this->deal($c, '2026-05-17', 8000, ['type' => 'out', 'filter' => 'payment']);          // wrong direction
        $other = DB::table('pawnshops')->insertGetId([]);
        $oc = $this->contract(1000000, ['ps' => $other]);
        $this->deal($oc, '2026-05-18', 9000, ['ps' => $other]);                                 // other pawnshop

        $loaded = (new PaymentHistoryResolver())->load($this->ps);
        $this->assertEqualsCanonicalizing([$ok, $full], array_keys($loaded['deals']));
    }

    // ---------------------------------------------------------------- reconstruction

    public function test_pre_entry_repayment_is_reconstructed_from_the_deal_not_zero(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-05-10', 16000, 24000);                    // current state after the payment: 40,000 left
        $deal = $this->deal($c, '2026-05-12', 60000);
        $this->action($deal, $row, '2026-05-12', 100000, 40000, 40000, 60000);  // scheduled 100,000, 60,000 applied, row reduced in place

        $r = $this->report(5);
        $this->assertSame(100000.0, $r['collections']['cohort']['due'], 'the original schedule, not the zeroed row');
        $this->assertSame(60000.0, $r['collections']['cohort']['collected']);
        $this->assertSame(40000.0, $r['collections']['cohort']['unpaid']);
        $this->assertSame(60.0, $r['collections']['cohort']['collection_rate_percent']);
        $this->assertSame('deals', $r['collections']['cohort']['payment_source']);
        $this->assertNull($r['collections']['cohort']['principal_collected'], 'partial settlement: exact total, split not invented');
        $this->assertSame('partial', $r['collections']['cohort']['reliability']['collection_components']);
        // no deal has both sources in this pawnshop, so the deal reconstruction cannot be validated here: the total is not claimed RELIABLE
        $this->assertSame('partial', $r['collections']['cohort']['reliability']['collection_total']);
        $this->assertSame(40000.0, $r['portfolio_quality']['overdue_amount']);
    }

    public function test_historical_as_of_works_without_payment_entries(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-04-20', 0, 0, 'completed');               // fully paid on 20.05, row zeroed afterwards
        $deal = $this->deal($c, '2026-05-20', 100000);
        $this->action($deal, $row, '2026-05-20', 100000, 0, 40000, 60000);

        $apr = $this->report(4)['portfolio_quality'];                         // as of 30.04: due 20.04, not yet paid
        $this->assertSame(1, $apr['overdue_contracts']);
        $this->assertSame(100000.0, $apr['overdue_amount']);
        $this->assertEquals(10, $apr['median_dpd']);
        $may = $this->report(5)['portfolio_quality'];                         // as of 31.05: paid
        $this->assertSame(0, $may['overdue_contracts']);
        $this->assertSame(1, $this->report(5)['collections']['transitions']['cured']['contracts']);
        $this->assertSame(100000.0, $this->report(5)['collections']['overdue_recovery']['collected']);
        $this->assertSame(100.0, $this->report(5)['collections']['overdue_recovery']['recovery_rate_percent']);
        $this->assertSame(['p' => 40000.0, 'i' => 60000.0], ['p' => 40000.0, 'i' => 60000.0]);
    }

    public function test_fully_settled_row_gives_exact_components(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-05-10', 0, 0, 'completed');
        $this->action($this->deal($c, '2026-05-10', 100000), $row, '2026-05-10', 100000, 0, 40000, 60000);
        $coh = $this->report(5)['collections']['cohort'];
        $this->assertSame(40000.0, $coh['principal_collected']);
        $this->assertSame(60000.0, $coh['interest_collected']);
        $this->assertSame(100.0, $coh['collection_rate_percent']);
    }

    public function test_early_repayment_from_deals_never_appears_as_unpaid(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-09-20', 0, 0, 'completed');               // paid on 01.09, due 20.09
        $this->action($this->deal($c, '2026-09-01', 10000), $row, '2026-09-01', 10000, 0, 6000, 4000);
        $this->assertSame(0, $this->report(9)['portfolio_quality']['overdue_contracts']);
        $coh = $this->report(9)['collections']['cohort'];
        $this->assertSame([10000.0, 10000.0], [$coh['due'], $coh['collected']]);
    }

    // ---------------------------------------------------------------- source switch / double counting

    public function test_post_boundary_deal_and_entry_are_counted_once(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-08-10', 40000, 60000);                    // entries era: the row keeps its schedule
        $deal = $this->deal($c, '2026-08-10', 100000);
        $this->entry($deal, $row, $c, '2026-08-10', 40000, 60000);
        $this->action($deal, $row, '2026-08-10', 100000, null, 40000, 60000);  // same payment recorded as a deal action too

        $coh = $this->report(8)['collections']['cohort'];
        $this->assertSame(100000.0, $coh['collected'], 'not 200,000');
        $this->assertSame('payment_entries', $coh['payment_source']);
        $this->assertSame(0, $this->report(8)['portfolio_quality']['overdue_contracts']);
    }

    public function test_zeroing_deal_wins_over_incomplete_entries_for_the_same_row_and_is_not_doubled(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-07-27', 0, 0, 'completed');
        $deal = $this->deal($c, '2026-07-27', 100000);
        $this->action($deal, $row, '2026-07-27', 100000, 0, 40000, 60000);
        $this->entry($deal, $row, $c, '2026-07-27', 16928.10, 0, 'partial_payment');   // the incomplete first-day entry
        $coh = $this->report(7)['collections']['cohort'];
        $this->assertSame(100000.0, $coh['collected'], 'the zeroing deal is the allocation record; its entries are not added');
        $this->assertSame(100000.0, $coh['due']);
    }

    public function test_deal_without_entries_after_the_boundary_uses_its_action_and_july_is_mixed(): void
    {
        $c1 = $this->contract();
        $r1 = $this->row($c1, '2026-07-10', 0, 0, 'completed');                                   // paid 10.07 by a zeroing deal
        $this->action($this->deal($c1, '2026-07-10', 10000), $r1, '2026-07-10', 10000, 0, 6000, 4000);
        $c2 = $this->contract();
        $r2 = $this->row($c2, '2026-07-30', 6000, 4000);                                          // paid 30.07 with an entry
        $this->entry($this->deal($c2, '2026-07-30', 10000), $r2, $c2, '2026-07-30', 6000, 4000);
        $c3 = $this->contract();
        $r3 = $this->row($c3, '2026-07-28', 6000, 4000);                                          // deal on 28.07 with NO entries (entries-shape action)
        $this->action($this->deal($c3, '2026-07-28', 10000), $r3, '2026-07-28', 10000, null, 6000, 4000);

        $coh = $this->report(7)['collections']['cohort'];
        $this->assertSame('mixed', $coh['payment_source']);
        $this->assertSame(30000.0, $coh['due']);
        $this->assertSame(30000.0, $coh['collected']);
        $loaded = (new PaymentHistoryResolver())->load($this->ps);
        $this->assertSame('2026-07-30', $loaded['entry_source_from'], 'derived from the data, not hard-coded');
    }

    public function test_entry_only_months_stay_payment_entries_and_months_without_payments_have_no_source(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-09-10', 6000, 4000);
        $this->entry($this->deal($c, '2026-09-10', 10000), $row, $c, '2026-09-10', 6000, 4000);
        $this->assertSame('payment_entries', $this->report(9)['collections']['cohort']['payment_source']);
        $this->assertSame('none', $this->report(6)['collections']['cohort']['payment_source']);
    }

    // ---------------------------------------------------------------- full repayment, tenancy, control

    public function test_full_repayment_leaves_no_false_overdue(): void
    {
        $c = $this->contract(0, ['status' => 'completed', 'closed_at' => '2026-06-15']);
        $this->row($c, '2026-06-10', 6000, 4000);                              // schedule rows left untouched by the closure
        $this->row($c, '2026-07-10', 6000, 4000);
        $this->deal($c, '2026-06-15', 500000, ['filter' => 'full_payment']);
        $this->assertSame(0, $this->report(6)['portfolio_quality']['overdue_contracts']);
        $this->assertSame(0, $this->report(7)['portfolio_quality']['overdue_contracts']);
        $cohort = $this->report(7)['collections']['cohort'];
        $this->assertSame(0.0, $cohort['due'], 'rows due after the closure are extinguished');
    }

    public function test_other_pawnshops_and_deleted_deals_never_contribute(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-05-10', 0, 0, 'completed');
        $deleted = $this->deal($c, '2026-05-10', 100000, ['deleted' => true]);
        $this->action($deleted, $row, '2026-05-10', 100000, 0, 40000, 60000);
        $r = $this->report(5);
        $this->assertSame('none', $r['collections']['cohort']['payment_source']);   // a reversed repayment is not evidence of payment
        $other = DB::table('pawnshops')->insertGetId([]);
        $this->assertSame(0.0, $this->report(5, $other)['collections']['cohort']['due']);
    }

    public function test_control_reproduces_entries_on_deals_that_have_both_sources(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-08-10', 40000, 60000);
        $deal = $this->deal($c, '2026-08-10', 100000);
        $this->entry($deal, $row, $c, '2026-08-10', 40000, 60000);
        $this->action($deal, $row, '2026-08-10', 100000, null, 40000, 60000);
        $loaded = (new PaymentHistoryResolver())->load($this->ps);
        $ctl = PaymentHistoryResolver::control($loaded, '2026-08-01', '2026-08-31');
        $this->assertSame([1, 0.0, 0], [$ctl['deals'], $ctl['allocation_diff'], $ctl['deals_allocation_mismatch']]);
        $this->assertSame(['entries' => 1, 'deals' => 0, 'without' => 0], [
            'entries' => PaymentHistoryResolver::classification($loaded, '2026-08-01', '2026-08-31')['entries']['deals'],
            'deals' => PaymentHistoryResolver::classification($loaded, '2026-08-01', '2026-08-31')['deals']['deals'],
            'without' => PaymentHistoryResolver::classification($loaded, '2026-08-01', '2026-08-31')['without_schedule_allocation']['deals'],
        ]);
    }

    public function test_deal_months_are_reliable_when_deal_allocations_reproduce_the_entries(): void
    {
        // control sample: a deal with both sources that agree
        $c0 = $this->contract();
        $r0 = $this->row($c0, '2026-08-10', 40000, 60000);
        $d0 = $this->deal($c0, '2026-08-10', 100000);
        $this->entry($d0, $r0, $c0, '2026-08-10', 40000, 60000);
        $this->action($d0, $r0, '2026-08-10', 100000, null, 40000, 60000);
        // an earlier, deal-only month
        $c = $this->contract();
        $row = $this->row($c, '2026-05-10', 0, 0, 'completed');
        $this->action($this->deal($c, '2026-05-10', 100000), $row, '2026-05-10', 100000, 0, 40000, 60000);
        $rel = $this->report(5)['collections']['cohort']['reliability'];
        $this->assertSame(['reliable', 'reliable', 'reliable', 'reliable'], array_values($rel));
        $this->assertTrue($this->report(5)['collections']['source_reconciliation']['deal_reconstruction_trusted']);
    }

    public function test_deal_only_rebuild_matches_the_entries_result(): void
    {
        $c = $this->contract();
        $row = $this->row($c, '2026-08-10', 40000, 60000);
        $deal = $this->deal($c, '2026-08-10', 60000);
        $this->entry($deal, $row, $c, '2026-08-10', 24000, 36000);
        $this->action($deal, $row, '2026-08-10', 100000, null, 40000, 60000, 60000);
        $normal = $this->report(8);
        $q = $this->q();
        $q->forceDeals = true;
        $forced = $q->build($this->ps, 8, 2026);
        $this->assertSame($normal['collections']['cohort']['collected'], $forced['collections']['cohort']['collected']);
        $this->assertSame($normal['portfolio_quality']['overdue_amount'], $forced['portfolio_quality']['overdue_amount']);
        $this->assertSame('deals', $forced['collections']['cohort']['payment_source']);
    }
}
