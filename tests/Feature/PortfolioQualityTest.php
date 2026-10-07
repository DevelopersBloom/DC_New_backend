<?php

namespace Tests\Feature;

use App\Services\Reports\CreditActivityReportService;
use App\Services\Reports\OverdueScheduleService;
use App\Services\Reports\PortfolioManagementService;
use App\Services\Reports\PortfolioQualityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

/**
 * Tranche 4: payment as-of engine, DPD, PAR, collections, transitions, drill-down.
 * "Today" is 2026-10-07; month 9 => as of 2026-09-30 (opening 2026-08-31), month 10 => as of 2026-10-07 (opening 2026-09-30).
 * Every test works in its own pawnshop. Overdue = unpaid principal+interest of instalments due BEFORE the as-of date, >= 1,000 per contract.
 */
class PortfolioQualityTest extends TestCase
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
        $this->cat = DB::table('categories')->insertGetId(['name' => 't4-a', 'title' => 'T4 A']);
        $this->userId = $this->userWith([])->id;
    }

    // ---------------------------------------------------------------- helpers

    private function q(): PortfolioQualityService
    {
        $base = new CreditActivityReportService(Carbon::parse('2026-10-07', 'Asia/Yerevan'));
        return new PortfolioQualityService($base, new PortfolioManagementService($base));
    }

    private function report(int $m = 9, ?int $ps = null): array
    {
        return $this->q()->build($ps ?? $this->ps, $m, 2026);
    }

    /** Active contract: estimate + disbursement on 2026-03-01, outstanding = $out. */
    private function contract(float $out = 1000000, array $o = []): int
    {
        $o += ['ps' => $this->ps, 'status' => 'initial', 'closed_at' => null, 'client' => null, 'num' => null];
        $id = DB::table('contracts')->insertGetId([
            'client_id' => $o['client'] ?? DB::table('clients')->insertGetId([]), 'category_id' => $this->cat, 'pawnshop_id' => $o['ps'],
            'num' => $o['num'] ?? ('T4-' . ++$this->seq . '-' . $o['ps']), 'estimated_amount' => 0, 'provided_amount' => $out, 'deadline' => '2031-01-01',
            'status' => $o['status'], 'closed_at' => $o['closed_at'], 'created_at' => '2026-03-01 10:00:00', 'updated_at' => now(),
        ]);
        foreach (['estimated_amount' => $out * 2, 'provided_amount' => $out] as $t => $amount) {
            DB::table('contract_amount_histories')->insert(['contract_id' => $id, 'amount_type' => $t, 'type' => 'in', 'amount' => $amount, 'date' => '2026-03-01',
                'category_id' => $this->cat, 'pawnshop_id' => $o['ps'], 'created_at' => now(), 'updated_at' => now()]);
        }
        return $id;
    }

    /** A schedule row; returns payment id. */
    private function inst(int $contract, string $due, float $principal, float $interest, array $o = []): int
    {
        $o += ['created' => '2026-03-01 10:00:00', 'deleted' => null, 'status' => 'initial', 'type' => 'regular'];
        return DB::table('payments')->insertGetId([
            'contract_id' => $contract, 'date' => $due, 'to_date' => $due, 'amount' => $principal + $interest, 'principal_payment' => $principal,
            'interest_payment' => $interest, 'type' => $o['type'], 'status' => $o['status'], 'created_at' => $o['created'], 'deleted_at' => $o['deleted'], 'updated_at' => now(),
        ]);
    }

    private function pay(int $payment, int $contract, string $date, float $principal, float $interest, string $docType = 'regular_payment', ?int $ps = null): void
    {
        DB::table('payment_entries')->insert([
            'payment_id' => $payment, 'contract_id' => $contract, 'pawnshop_id' => $ps ?? $this->ps, 'user_id' => $this->userId, 'reference' => 'T4-' . uniqid('', true),
            'amount' => $principal + $interest, 'principal_amount' => $principal, 'interest_amount' => $interest, 'penalty_amount' => 0,
            'balance_before' => 0, 'balance_after' => 0, 'document_type' => $docType, 'date' => $date, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bucketCount(array $r, string $b): int
    {
        return collect($r['portfolio_quality']['buckets'])->firstWhere('bucket', $b)['contracts'];
    }

    // ---------------------------------------------------------------- as-of engine

    public function test_future_and_due_today_installments_are_not_overdue_and_one_day_is(): void
    {
        $future = $this->contract();
        $this->inst($future, '2026-10-15', 5000, 5000);
        $today = $this->contract();
        $this->inst($today, '2026-09-30', 5000, 5000);            // due on the as-of date
        $late = $this->contract();
        $this->inst($late, '2026-09-29', 5000, 5000);             // 1 day overdue
        $r = $this->report(9);
        $this->assertSame(1, $r['portfolio_quality']['overdue_contracts']);
        $this->assertSame(1, $this->bucketCount($r, '1-7'));
        $this->assertSame(1.0, $r['portfolio_quality']['median_dpd']);
        $this->assertSame(10000.0, $r['portfolio_quality']['overdue_amount']);
        $this->assertSame(5000.0, $r['portfolio_quality']['overdue_principal']);
        $this->assertSame(5000.0, $r['portfolio_quality']['overdue_interest']);
    }

    public function test_partial_full_multiple_payments_prepayment_and_threshold(): void
    {
        $partial = $this->contract();
        $i = $this->inst($partial, '2026-09-10', 8000, 2000);
        $this->pay($i, $partial, '2026-09-12', 3000, 0);                           // 7,000 still unpaid
        $full = $this->contract();
        $i = $this->inst($full, '2026-09-10', 8000, 2000);
        $this->pay($i, $full, '2026-09-12', 8000, 0);
        $this->pay($i, $full, '2026-09-20', 0, 2000);                              // two payments settle one instalment
        $pre = $this->contract();
        $i = $this->inst($pre, '2026-09-20', 8000, 2000);
        $this->pay($i, $pre, '2026-09-01', 8000, 2000, 'prepayment_payment');      // paid early
        $small = $this->contract();
        $this->inst($small, '2026-09-10', 500, 400);                               // 900 < 1,000 materiality
        $r = $this->report(9);
        $this->assertSame(1, $r['portfolio_quality']['overdue_contracts']);
        $this->assertSame(7000.0, $r['portfolio_quality']['overdue_amount']);
        $this->assertSame(1, $r['data_quality']['overdue_below_threshold']);
        $this->assertSame(1000.0, (float) $r['definitions']['overdue_min_amount_amd']);
    }

    public function test_payment_after_as_of_is_ignored_and_historical_report_keeps_the_old_state(): void
    {
        $c = $this->contract();
        $i = $this->inst($c, '2026-09-20', 8000, 2000);
        $this->pay($i, $c, '2026-10-03', 8000, 2000);                              // paid AFTER 30.09
        $sep = $this->report(9);
        $this->assertSame(1, $sep['portfolio_quality']['overdue_contracts'], 'still overdue on 30.09');
        $this->assertSame(10000.0, $sep['portfolio_quality']['overdue_amount']);
        $this->assertSame(1, $sep['data_quality']['entries_after_as_of_ignored']);
        $this->assertSame(10, $this->q()->details($this->ps, 9, 2026, 'overdue_all')['items'][0]['dpd']);

        $oct = $this->report(10);
        $this->assertSame(0, $oct['portfolio_quality']['overdue_contracts'], 'cured by 07.10');
        $this->assertSame(1, $oct['collections']['transitions']['cured']['contracts']);
        $this->assertSame(10000.0, $oct['collections']['overdue_recovery']['collected']);
        $this->assertSame(100.0, $oct['collections']['overdue_recovery']['recovery_rate_percent']);
    }

    public function test_soft_deleted_schedule_rows_and_other_pawnshops_are_ignored(): void
    {
        $c = $this->contract();
        $this->inst($c, '2026-09-10', 8000, 2000, ['deleted' => '2026-09-15 12:00:00']);   // schedule rebuilt on 15.09
        $this->inst($c, '2026-09-10', 4000, 1000, ['created' => '2026-09-15 12:00:01']);   // replacement row: 5,000
        $other = DB::table('pawnshops')->insertGetId([]);
        $oc = $this->contract(1000000, ['ps' => $other]);
        $this->inst($oc, '2026-09-10', 9000, 9000);

        $this->assertSame(5000.0, $this->report(9)['portfolio_quality']['overdue_amount']);
        $this->assertSame(18000.0, $this->report(9, $other)['portfolio_quality']['overdue_amount']);
    }

    // ---------------------------------------------------------------- DPD / PAR

    public function test_dpd_bucket_boundaries(): void
    {
        $asOf = Carbon::parse('2026-09-30');
        foreach ([1, 7, 8, 30, 31, 60, 61, 90, 91] as $dpd) {
            $c = $this->contract();
            $this->inst($c, $asOf->copy()->subDays($dpd)->toDateString(), 5000, 5000);
        }
        $this->contract();   // current, no schedule rows due
        $r = $this->report(9);
        $this->assertEquals(['1-7' => 2, '8-30' => 2, '31-60' => 2, '61-90' => 2, '91+' => 1, 'current' => 1],
            collect($r['portfolio_quality']['buckets'])->pluck('contracts', 'bucket')->all());
        $this->assertSame(91, $r['portfolio_quality']['max_dpd']);
        $this->assertSame(31.0, $r['portfolio_quality']['median_dpd']);
    }

    public function test_par_uses_whole_contract_outstanding_once_and_matches_the_active_denominator(): void
    {
        $a = $this->contract(1000000);
        $this->inst($a, '2026-09-25', 5000, 5000);       // DPD 5
        $this->inst($a, '2026-08-21', 5000, 5000);       // DPD 40  => earliest = 40, one contract
        $b = $this->contract(500000);
        $this->inst($b, '2026-09-20', 5000, 5000);       // DPD 10
        $this->contract(2500000);                         // current
        $closed = $this->contract(900000, ['status' => 'completed', 'closed_at' => '2026-09-01']);
        $this->inst($closed, '2026-08-20', 5000, 5000);   // closed contract is not in the portfolio
        $zero = $this->contract(1000000);
        DB::table('contract_amount_histories')->insert(['contract_id' => $zero, 'amount_type' => 'provided_amount', 'type' => 'out', 'amount' => 1000000,
            'date' => '2026-09-01', 'category_id' => $this->cat, 'pawnshop_id' => $this->ps, 'created_at' => now(), 'updated_at' => now()]);
        $this->inst($zero, '2026-08-20', 5000, 5000);     // zero outstanding is not active

        $r = $this->report(9);
        $pq = $r['portfolio_quality'];
        $this->assertSame(4000000.0, $pq['active_outstanding']);
        $this->assertSame(2, $pq['overdue_contracts']);
        $this->assertSame(1500000.0, $pq['par_amount']['1'], 'whole outstanding of a and b');
        $this->assertSame(37.5, $pq['par']['1']);
        $this->assertSame(1000000.0, $pq['par_amount']['30'], 'only a is beyond 30 days');
        $this->assertSame(25.0, $pq['par']['30']);
        $this->assertSame(0.0, $pq['par_amount']['90']);
        $this->assertSame(30000.0, $pq['overdue_amount'], 'a: 20,000, b: 10,000 (components, not exposure)');
    }

    public function test_active_denominator_equals_tranche3_and_overdue_matches_the_canonical_engine(): void
    {
        foreach ([3, 20, 45] as $d) {
            $c = $this->contract();
            $this->inst($c, Carbon::parse('2026-09-30')->subDays($d)->toDateString(), 6000, 4000);
        }
        $base = new CreditActivityReportService(Carbon::parse('2026-10-07', 'Asia/Yerevan'));
        $t3 = $base->build($this->ps, 9, 2026)['portfolio_management'];
        $r = $this->report(9);
        $this->assertSame($t3['active_outstanding'], $r['portfolio_quality']['active_outstanding']);
        $this->assertSame($t3['active_contracts'], $r['portfolio_quality']['active_contracts']);
        $ids = DB::table('contracts')->where('pawnshop_id', $this->ps)->pluck('id')->all();
        $canon = (new OverdueScheduleService())->overdueAmountsAtDate($ids, '2026-09-30', PortfolioQualityService::OVERDUE_MIN_AMOUNT);
        $this->assertEqualsWithDelta(array_sum($canon), $r['portfolio_quality']['overdue_amount'], 0.01);
        $this->assertSame(count($canon), $r['portfolio_quality']['overdue_contracts']);
        // every active contract is classified exactly once
        $this->assertSame($r['portfolio_quality']['active_contracts'], array_sum(array_column($r['portfolio_quality']['buckets'], 'contracts')));
        $this->assertEqualsWithDelta($r['portfolio_quality']['active_outstanding'], array_sum(array_column($r['portfolio_quality']['buckets'], 'outstanding')), 0.01);
    }

    public function test_maturity_and_delinquency_are_independent(): void
    {
        $a = $this->contract();
        $this->inst($a, '2026-09-20', 5000, 5000);                                 // overdue, deadline far away
        DB::table('contracts')->where('id', $this->contract())->update(['deadline' => '2026-09-01']);   // matured, no schedule
        $x = $this->report(9)['portfolio_quality']['maturity_vs_delinquency'];
        $this->assertSame([0, 1, 1], [$x['matured_and_overdue'], $x['matured_not_overdue'], $x['overdue_not_matured']]);
    }

    // ---------------------------------------------------------------- collections

    public function test_collection_rate_numerator_and_denominator(): void
    {
        $a = $this->contract();
        $i1 = $this->inst($a, '2026-09-10', 8000, 2000);
        $this->pay($i1, $a, '2026-09-10', 8000, 2000);                             // fully collected: 10,000
        $i2 = $this->inst($a, '2026-09-20', 8000, 2000);
        $this->pay($i2, $a, '2026-09-25', 4000, 0);                                // partial: 4,000
        $this->inst($a, '2026-09-25', 4000, 2000);                                 // nothing collected: 6,000
        $i4 = $this->inst($a, '2026-09-28', 3000, 1000);
        $this->pay($i4, $a, '2026-10-02', 3000, 1000);                             // paid after the period end: not collected
        $i5 = $this->inst($a, '2026-10-15', 9000, 1000);
        $this->pay($i5, $a, '2026-09-05', 9000, 1000, 'prepayment_payment');       // next month's instalment prepaid: not in the cohort
        $old = $this->inst($a, '2026-08-15', 5000, 1000);
        $this->pay($old, $a, '2026-09-12', 5000, 1000);                            // earlier overdue paid in September: not in the cohort

        $c = $this->report(9)['collections'];
        $this->assertSame(4, $c['cohort']['instalments']);
        $this->assertSame(30000.0, $c['cohort']['due']);
        $this->assertSame(14000.0, $c['cohort']['collected']);
        $this->assertSame(16000.0, $c['cohort']['unpaid']);
        $this->assertSame(46.7, $c['cohort']['collection_rate_percent']);
        $this->assertSame(30000.0, $c['cohort']['collected'] + $c['cohort']['unpaid']);
        $this->assertSame(6000.0, $c['overdue_recovery']['collected'], 'the 15.08 instalment settled in September');
    }

    public function test_zero_collection_and_full_collection(): void
    {
        $a = $this->contract();
        $this->inst($a, '2026-09-10', 5000, 5000);
        $this->assertSame(0.0, $this->report(9)['collections']['cohort']['collection_rate_percent']);
        $b = $this->contract();
        $i = $this->inst($b, '2026-09-11', 5000, 5000);
        $this->pay($i, $b, '2026-09-11', 5000, 5000);
        $this->assertSame(50.0, $this->report(9)['collections']['cohort']['collection_rate_percent']);
        $empty = DB::table('pawnshops')->insertGetId([]);
        $this->assertNull($this->report(9, $empty)['collections']['cohort']['collection_rate_percent'], 'nothing due -> no rate');
    }

    public function test_rollforward_reconciles(): void
    {
        $a = $this->contract();                                       // overdue at 31.08, paid in September
        $i = $this->inst($a, '2026-08-15', 6000, 2000);
        $this->pay($i, $a, '2026-09-12', 6000, 2000);
        $b = $this->contract();                                       // overdue at 31.08, still overdue
        $this->inst($b, '2026-08-20', 3000, 2000);
        $c = $this->contract();                                       // becomes overdue in September
        $this->inst($c, '2026-09-10', 4000, 2000);
        $r = $this->report(9)['collections']['rollforward'];
        $this->assertSame(13000.0, $r['opening_overdue']);
        $this->assertSame(8000.0, $r['collected']);
        $this->assertSame(6000.0, $r['new_overdue']);
        $this->assertSame(11000.0, $r['closing_overdue']);
        $this->assertSame(0.0, $r['other_adjustments']);
        $this->assertSame(61.5, $r['recovery_rate_percent']);
    }

    // ---------------------------------------------------------------- transitions

    public function test_newly_overdue_cured_worsened_improved_and_closed_after_overdue(): void
    {
        $n = $this->contract();                                       // current -> overdue
        $this->inst($n, '2026-09-10', 4000, 2000);
        $w = $this->contract();                                       // 1-7 at 31.08 -> 31-60 at 30.09
        $this->inst($w, '2026-08-25', 4000, 2000);
        $im = $this->contract();                                      // 61-90 at 31.08, older instalment paid -> 31-60
        $old = $this->inst($im, '2026-06-30', 4000, 2000);
        $this->inst($im, '2026-08-25', 4000, 2000);
        $this->pay($old, $im, '2026-09-05', 4000, 2000);
        $cu = $this->contract();                                      // overdue -> paid in full
        $i = $this->inst($cu, '2026-08-20', 4000, 2000);
        $this->pay($i, $cu, '2026-09-08', 4000, 2000);
        $cl = $this->contract(1000000, ['status' => 'completed', 'closed_at' => '2026-09-15']);   // overdue at 31.08, then closed by full payment
        $this->inst($cl, '2026-08-20', 4000, 2000);
        DB::table('contract_amount_histories')->insert(['contract_id' => $cl, 'amount_type' => 'provided_amount', 'type' => 'out', 'amount' => 1000000,
            'date' => '2026-09-15', 'category_id' => $this->cat, 'pawnshop_id' => $this->ps, 'created_at' => now(), 'updated_at' => now()]);

        $t = $this->report(9)['collections']['transitions'];
        $this->assertSame(1, $t['newly_overdue']['contracts']);
        $this->assertSame(2, $t['cured']['contracts'], 'paid in full + closed by full payment');
        $this->assertSame(1, $t['cured']['of_which_closed']);
        $this->assertSame(2, $t['worsened']['contracts'], 'w: 1-7 -> 31-60');
        $this->assertSame(1, $t['improved']['contracts'], 'older instalment paid: 61-90 -> 31-60 (still overdue, so improved not cured)');
        $this->assertSame(0, $t['overdue_dropped_without_explanation']);
    }

    // ---------------------------------------------------------------- details endpoint

    public function test_details_endpoint_counts_security_pagination_and_isolation(): void
    {
        $user = $this->userWith(['view_cashbox_summary', 'view_contracts']);
        $plain = $this->userWith(['view_contracts']);
        $mineIds = [];
        foreach ([3, 20, 45, 100] as $d) {
            $c = $this->contract(1000000, ['ps' => 1, 'num' => 'T4Q-MINE-' . $d]);
            DB::table('payments')->insert(['contract_id' => $c, 'date' => Carbon::parse('2026-09-30')->subDays($d)->toDateString(), 'amount' => 10000, 'principal_payment' => 6000,
                'interest_payment' => 4000, 'type' => 'regular', 'status' => 'initial', 'created_at' => '2026-03-01', 'updated_at' => now()]);
            $mineIds[] = $c;
        }
        $other = DB::table('pawnshops')->insertGetId([]);
        $oc = $this->contract(1000000, ['ps' => $other, 'num' => 'T4Q-FOREIGN']);
        $this->inst($oc, '2026-09-20', 6000, 4000);
        $url = '/api/reports/portfolio-quality/details';
        $q = fn (array $x = []) => $url . '?' . http_build_query(array_merge(['type' => 'overdue_all', 'month' => 9, 'year' => 2026, 'per_page' => 100, 'search' => 'T4Q-'], $x));

        $this->getJson($q())->assertStatus(401);
        $this->getJson($q(), $this->authHeaders($plain))->assertStatus(403);
        $this->getJson($q(['type' => 'x; drop']), $this->authHeaders($user))->assertStatus(422);
        $this->getJson($q(['sort' => 'id']), $this->authHeaders($user))->assertStatus(422);

        $all = $this->getJson($q(), $this->authHeaders($user))->assertOk()->json('data');
        $nums = array_column($all['items'], 'num');
        $this->assertEqualsCanonicalizing(['T4Q-MINE-3', 'T4Q-MINE-20', 'T4Q-MINE-45', 'T4Q-MINE-100'], $nums);
        $this->assertNotContains('T4Q-FOREIGN', $nums);
        $this->assertSame(count($nums), count(array_unique($nums)));

        $b = $this->getJson($q(['type' => 'dpd_31_60']), $this->authHeaders($user))->json('data');
        $this->assertSame(['T4Q-MINE-45'], array_column($b['items'], 'num'));
        $this->assertSame(45, $b['items'][0]['dpd']);
        $page = $this->getJson($q(['per_page' => 2, 'sort' => 'dpd', 'dir' => 'desc']), $this->authHeaders($user))->json('data');
        $this->assertSame([100, 45], array_column($page['items'], 'dpd'));
        $this->assertSame(4, $page['total']);

        // headline bucket count == drill-down count for the same pawnshop
        $head = $this->q()->build(1, 9, 2026)['portfolio_quality']['buckets'];
        $this->assertSame(collect($head)->firstWhere('bucket', '91+')['contracts'], $this->q()->details(1, 9, 2026, 'dpd_91_plus', ['per_page' => 100])['total']);

        $noNames = $this->userWith(['view_cashbox_summary']);
        $row = $this->getJson($q(['type' => 'overdue_all', 'search' => '']), $this->authHeaders($noNames))->assertOk()->json('data.items.0');
        $this->assertNull($row['customer']);
    }
}
