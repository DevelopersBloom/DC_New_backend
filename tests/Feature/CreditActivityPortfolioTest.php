<?php

namespace Tests\Feature;

use App\Services\Reports\CreditActivityReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

/**
 * Tranche 3: active portfolio, maturity, closures, top-ups, management exceptions and the drill-down endpoint.
 * "Today" is 2026-10-07; month 9 => as of 2026-09-30, month 10 => as of 2026-10-07.
 * contracts.deadline is NOT NULL in the schema, so the "missing deadline" bucket cannot occur in stored data.
 */
class CreditActivityPortfolioTest extends TestCase
{
    use DatabaseTransactions, LoanApplicationWorld;

    private int $ps;
    private int $cat1;
    private int $cat2;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
        $this->ps = DB::table('pawnshops')->insertGetId([]);
        $this->cat1 = DB::table('categories')->insertGetId(['name' => 't3-a', 'title' => 'T3 A']);
        $this->cat2 = DB::table('categories')->insertGetId(['name' => 't3-b', 'title' => 'T3 B']);
    }

    // ---------------------------------------------------------------- helpers

    private function report(int $m = 9, ?int $ps = null): array
    {
        return (new CreditActivityReportService(Carbon::parse('2026-10-07', 'Asia/Yerevan')))->build($ps ?? $this->ps, $m, 2026);
    }

    private function details(string $type, int $m = 9, array $opts = []): array
    {
        return (new CreditActivityReportService(Carbon::parse('2026-10-07', 'Asia/Yerevan')))->details($this->ps, $m, 2026, $type, $opts);
    }

    /** Creates a contract with an estimate and a first disbursement; returns its id. */
    private function contract(array $o = []): int
    {
        $o += ['client' => null, 'cat' => $this->cat1, 'ps' => $this->ps, 'status' => 'initial', 'deadline' => '2031-01-01',
            'closed_at' => null, 'amount' => 100000.0, 'estimate' => 200000.0, 'date' => '2026-08-01', 'contract_amount' => null, 'num' => null];
        $id = DB::table('contracts')->insertGetId([
            'client_id' => $o['client'] ?? DB::table('clients')->insertGetId([]), 'category_id' => $o['cat'], 'pawnshop_id' => $o['ps'],
            'num' => $o['num'] ?? ('T3-' . ++$this->seq . '-' . $o['ps']), 'estimated_amount' => 0, 'provided_amount' => $o['contract_amount'] ?? $o['amount'] ?? 0,
            'deadline' => $o['deadline'], 'status' => $o['status'], 'closed_at' => $o['closed_at'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($o['estimate'] !== null) {
            $this->hist($id, 'estimated_amount', 'in', $o['estimate'], $o['date'], $o['cat'], null, $o['ps']);
        }
        if ($o['amount'] !== null) {
            $this->hist($id, 'provided_amount', 'in', $o['amount'], $o['date'], $o['cat'], null, $o['ps']);
        }
        return $id;
    }

    private function hist(int $c, string $amountType, string $type, float $amount, string $date, ?int $cat = null, ?int $deal = null, ?int $ps = null, bool $deleted = false): void
    {
        DB::table('contract_amount_histories')->insert([
            'contract_id' => $c, 'amount_type' => $amountType, 'type' => $type, 'amount' => $amount, 'date' => $date, 'category_id' => $cat,
            'deal_id' => $deal, 'pawnshop_id' => $ps ?? $this->ps, 'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function repay(int $c, float $amount, string $date): void
    {
        $this->hist($c, 'provided_amount', 'out', $amount, $date, $this->cat1);
    }

    private function exc(array $r, string $type): int
    {
        return collect(array_merge($r['management_exceptions']['business_attention'], $r['management_exceptions']['data_quality']))->firstWhere('type', $type)['count'];
    }

    // ---------------------------------------------------------------- active portfolio

    public function test_active_definition_combines_status_closure_date_and_history_balance(): void
    {
        $client = DB::table('clients')->insertGetId([]);
        $a = $this->contract(['client' => $client]);                                  // active, 60k after repayment
        $this->repay($a, 40000, '2026-09-05');
        $c = $this->contract(['client' => $client, 'status' => 'completed', 'closed_at' => '2026-10-05']);   // closes AFTER 30.09
        $this->repay($c, 100000, '2026-10-05');
        $b = $this->contract(['status' => 'completed', 'closed_at' => '2026-09-10']); // closed in September
        $this->repay($b, 100000, '2026-09-10');
        $z = $this->contract([]);                                                     // status initial, repaid to zero
        $this->repay($z, 100000, '2026-09-12');
        $e = $this->contract(['status' => 'completed', 'closed_at' => '2026-09-20', 'amount' => 500000]);   // completed but principal remains
        $x = $this->contract(['status' => 'executed', 'closed_at' => '2026-09-22']);  // executed (principal left, closed)

        $sep = $this->report(9)['portfolio_management'];
        $this->assertSame(2, $sep['active_contracts'], 'A and C (C closes only on 05.10)');
        $this->assertSame(1, $sep['active_borrowers'], 'one borrower holds both');
        $this->assertSame(160000.0, $sep['active_outstanding']);
        $this->assertSame(80000.0, $sep['average_active_balance']);
        $this->assertSame(80000.0, $sep['median_active_balance']);
        $this->assertSame(2, $sep['reconciliation']['active']);

        $oct = $this->report(10)['portfolio_management'];
        $this->assertSame(1, $oct['active_contracts'], 'as of 07.10 C is closed');
        $this->assertSame(60000.0, $oct['active_outstanding']);

        $this->assertSame(1, $this->exc($this->report(9), 'active_zero_balance'));
        $this->assertSame(2, $this->exc($this->report(9), 'completed_with_balance'), 'completed + executed with principal left');
        $closures = $sep['closures'];
        $this->assertSame([2, 1, 3], [$closures['completed'], $closures['executed'], $closures['total']]);   // b, e completed; x executed
    }

    public function test_closure_age_is_closed_at_minus_first_disbursement_and_undated_closures_are_reported(): void
    {
        $this->contract(['status' => 'completed', 'closed_at' => '2026-09-11', 'date' => '2026-08-01']);   // 41 days
        $this->contract(['status' => 'completed', 'closed_at' => '2026-09-21', 'date' => '2026-08-01']);   // 51 days
        $this->contract(['status' => 'completed', 'closed_at' => null]);                                     // no closed_at
        $r = $this->report(9);
        $this->assertSame(46.0, $r['portfolio_management']['closures']['median_age_days']);
        $this->assertSame(46.0, $r['portfolio_management']['closures']['average_age_days']);
        $this->assertSame(1, $r['portfolio_management']['closures']['missing_closed_at']);
        $this->assertSame(1, $this->exc($r, 'closed_without_date'));
        $this->assertSame('closed_without_date', $this->details('closed_without_date')['items'][0]['exception_type']);
    }

    public function test_soft_deleted_histories_and_other_pawnshops_never_count(): void
    {
        $a = $this->contract([]);
        $this->hist($a, 'provided_amount', 'in', 900000, '2026-09-01', $this->cat1, null, null, true);   // soft-deleted top-up-like row
        $other = DB::table('pawnshops')->insertGetId([]);
        $this->contract(['ps' => $other, 'amount' => 777000]);
        $r = $this->report(9);
        $this->assertSame(1, $r['portfolio_management']['active_contracts']);
        $this->assertSame(100000.0, $r['portfolio_management']['active_outstanding']);
        $this->assertSame(777000.0, $this->report(9, $other)['portfolio_management']['active_outstanding']);
    }

    public function test_age_distribution_concentration_and_category_split(): void
    {
        $this->contract(['amount' => 600000, 'date' => '2026-09-20']);                       // 10 days
        $this->contract(['amount' => 300000, 'date' => '2026-08-01', 'cat' => $this->cat2]);   // 60 days
        $this->contract(['amount' => 100000, 'date' => '2025-08-01']);                       // 425 days (365+)
        $r = $this->report(9);
        $age = collect($r['portfolio_management']['age_distribution'])->keyBy('bucket');
        $this->assertSame([1, 1, 0, 0, 1], [$age['0-30']['count'], $age['31-90']['count'], $age['91-180']['count'], $age['181-365']['count'], $age['365+']['count']]);
        $this->assertSame(60.0, $age['0-30']['share_outstanding_percent']);
        $c = $r['portfolio_management']['concentration'];
        $this->assertSame(600000.0, $c['largest_contract']['amount']);
        $this->assertSame(100.0, $c['top_5_share']);
        $by = collect($r['active_by_category'])->keyBy('category_id');
        $this->assertSame(700000.0, $by[$this->cat1]['outstanding']);
        $this->assertSame(1, $by[$this->cat2]['active_contracts']);
    }

    // ---------------------------------------------------------------- maturity

    public function test_maturity_buckets_are_relative_to_the_selected_as_of_date(): void
    {
        // as of 2026-09-30
        foreach (['2026-09-29', '2026-09-30', '2026-10-03', '2026-10-10', '2026-11-14', '2026-12-29'] as $d) {   // -1, 0, 3, 10, 45, 90 days
            $this->contract(['deadline' => $d]);
        }
        $this->contract(['deadline' => '2026-10-02', 'status' => 'completed', 'closed_at' => '2026-09-15', 'amount' => 100000]);   // completed before maturity
        $m = $this->report(9)['portfolio_management']['maturity'];
        $this->assertSame(1, $m['matured_active']['count'], 'deadline yesterday');
        $this->assertSame(2, $m['within_7_days']['count'], 'deadline today (0) and in 3 days');
        $this->assertSame(3, $m['within_30_days']['count'], 'cumulative: 0, 3, 10');
        $this->assertSame(4, $m['within_60_days']['count'], 'cumulative: + 45');
        $buckets = collect($m['buckets'])->pluck('count', 'bucket');
        $this->assertSame([1, 2, 1, 1, 1], [$buckets['matured'], $buckets['0-7'], $buckets['8-30'], $buckets['31-60'], $buckets['61+']]);

        // the same contracts seen as of 07.10: the one due 10.10 is now within 7 days, three more have matured
        $oct = $this->report(10)['portfolio_management']['maturity'];
        $this->assertSame(3, $oct['matured_active']['count']);
        $this->assertSame(1, $oct['within_7_days']['count']);
        $this->assertSame(1, $this->exc($this->report(9), 'matured_active'));
    }

    // ---------------------------------------------------------------- top-ups

    public function test_topups_period_lifetime_repeats_and_split_disbursement(): void
    {
        $a = $this->contract(['date' => '2026-07-01']);
        $this->hist($a, 'provided_amount', 'in', 50000, '2026-08-15', $this->cat1);   // before September
        $this->hist($a, 'provided_amount', 'in', 20000, '2026-09-10', $this->cat1);   // inside
        $this->hist($a, 'provided_amount', 'in', 30000, '2026-09-20', $this->cat1);   // inside
        $this->hist($a, 'provided_amount', 'in', 99000, '2026-10-03', $this->cat1);   // after the period
        $b = $this->contract(['date' => '2026-07-01']);
        $deal = DB::table('deals')->insertGetId(['type' => 'out', 'amount' => 200000, 'date' => '2026-09-05', 'filter_type' => 'contract', 'pawnshop_id' => $this->ps, 'cash' => false]);
        $s = $this->contract(['amount' => null, 'estimate' => 400000, 'date' => '2026-09-05']);
        $this->hist($s, 'provided_amount', 'in', 80000, '2026-09-05', $this->cat1, $deal);
        $this->hist($s, 'provided_amount', 'in', 120000, '2026-09-06', $this->cat1, $deal);   // same deal: still the first disbursement

        $r = $this->report(9);
        $t = $r['portfolio_management']['topups'];
        $this->assertSame(1, $t['contracts']);
        $this->assertSame(2, $t['events']);
        $this->assertSame(50000.0, $t['amount']);
        $this->assertSame(25000.0, $t['average']);
        $this->assertSame(25000.0, $t['median']);
        $this->assertSame(1, $t['repeated']['contracts'], 'a has 3 top-ups up to the as-of date (the 03.10 one is after it)');
        $this->assertSame(1.0, (float) $r['activity']['new_loan_count']['current'], 'split disbursement = one new loan, no false top-up');

        $items = $this->details('topups')['items'];
        $this->assertCount(2, $items, 'one row per top-up event in the period');
        $this->assertEqualsCanonicalizing([20000.0, 30000.0], array_column($items, 'topup_amount'));
        $this->assertSame(100000.0, $items[0]['first_disbursement_amount']);
        $rep = $this->details('repeated_topups')['items'];
        $this->assertCount(1, $rep);
        $this->assertSame(100000.0, $rep[0]['lifetime_topup_total']);
        $this->assertSame(1, $this->report(8)['portfolio_management']['topups']['contracts'], 'August: only the 15.08 top-up');
    }

    // ---------------------------------------------------------------- exceptions

    public function test_every_management_exception_rule(): void
    {
        $this->contract(['contract_amount' => 100000 + 100]);                  // diff exactly 100 -> tolerated
        $this->contract(['contract_amount' => 100000 + 101]);                  // diff 101 -> mismatch
        $this->contract(['amount' => null, 'estimate' => 100, 'contract_amount' => 5000]);   // provided amount but no disbursement history
        $c = $this->contract([]);
        $this->hist($c, 'provided_amount', 'in', 1, '2026-08-02', $this->cat2);   // history category differs from the contract's
        $this->contract(['amount' => 300000, 'estimate' => 200000, 'date' => '2026-09-10']);      // LTV 150% (new loan in September)
        $this->contract(['amount' => 100000, 'estimate' => 0, 'date' => '2026-09-11']);            // zero estimate
        $this->contract(['amount' => 100000, 'estimate' => null, 'date' => '2026-09-12']);         // no estimate row
        $n = $this->contract(['amount' => 100000]);
        $this->repay($n, 100500, '2026-09-01');                                                    // -500 outstanding
        $this->contract(['status' => 'completed', 'closed_at' => '2026-09-02', 'amount' => 100000]);   // completed with 100,000 left
        $z = $this->contract([]);
        $this->repay($z, 100000, '2026-09-03');                                                    // initial with zero principal

        $r = $this->report(9);
        $expect = ['missing_disbursement' => 1, 'category_conflict' => 1, 'ltv_over_100' => 1, 'missing_estimate' => 2,
            'negative_outstanding' => 1, 'completed_with_balance' => 1, 'active_zero_balance' => 1];
        foreach ($expect as $type => $n) {
            $this->assertSame($n, $this->exc($r, $type), $type);
            $this->assertCount($n, $this->details($type)['items'], "$type drill-down matches the count");
        }
        $mm = $this->details('principal_mismatch');
        $nums = array_column($mm['items'], 'num');
        $this->assertSame($this->exc($r, 'principal_mismatch'), $mm['total']);
        $diffs = array_column(array_column($mm['items'], 'details'), 'difference');
        $this->assertNotContains(-100.0, $diffs, 'a 100 AMD difference is within tolerance');
        $this->assertNotContains(100.0, $diffs);
        $this->assertContains(-101.0, $diffs, 'a 101 AMD difference is reported');
        $this->assertSame(150.0, $this->details('ltv_over_100')['items'][0]['details']['origination_ltv']);
        $this->assertSame(100.0, $r['management_exceptions']['tolerance_amd']);
    }

    // ---------------------------------------------------------------- drill-down endpoint

    public function test_details_endpoint_security_validation_pagination_sorting_and_isolation(): void
    {
        $user = $this->userWith(['view_cashbox_summary', 'view_contracts']);
        $noReport = $this->userWith(['view_contracts']);
        $mine = $this->contract(['ps' => 1, 'num' => 'T3-MINE-1', 'amount' => 123456789]);
        $other = DB::table('pawnshops')->insertGetId([]);
        $this->contract(['ps' => $other, 'num' => 'T3-FOREIGN-1', 'amount' => 987654321]);
        $url = '/api/reports/credit-activity/details';
        $q = fn (array $x = []) => $url . '?' . http_build_query(array_merge(['type' => 'active', 'month' => 9, 'year' => 2026], $x));

        $this->getJson($q())->assertStatus(401);
        $this->getJson($q(), $this->authHeaders($noReport))->assertStatus(403);
        $this->getJson($q(['type' => 'drop table']), $this->authHeaders($user))->assertStatus(422);
        $this->getJson($q(['sort' => 'id; DROP']), $this->authHeaders($user))->assertStatus(422);
        $this->getJson($q(['per_page' => 500]), $this->authHeaders($user))->assertStatus(422);

        $res = $this->getJson($q(['per_page' => 3, 'sort' => 'outstanding', 'dir' => 'desc']), $this->authHeaders($user))->assertOk()->json('data');
        $this->assertSame(3, count($res['items']));
        $this->assertSame('T3-MINE-1', $res['items'][0]['num'], 'largest outstanding first');
        $this->assertSame(1, $res['page']);
        $this->assertSame(3, $res['per_page']);
        $this->assertGreaterThan(3, $res['total']);
        $outs = array_column($res['items'], 'outstanding');
        $this->assertSame($outs, collect($outs)->sortDesc()->values()->all());

        $all = $this->getJson($q(['per_page' => 100, 'search' => 'T3-']), $this->authHeaders($user))->assertOk()->json('data');
        $nums = array_column($all['items'], 'num');
        $this->assertContains('T3-MINE-1', $nums);
        $this->assertNotContains('T3-FOREIGN-1', $nums, 'another pawnshop never leaks');
        $this->assertSame(count($nums), count(array_unique($nums)), 'no duplicate contracts');

        // a user without view_contracts gets no customer names
        $noNames = $this->userWith(['view_cashbox_summary']);
        $row = $this->getJson($q(['search' => 'T3-MINE']), $this->authHeaders($noNames))->assertOk()->json('data.items.0');
        $this->assertNull($row['customer']);
    }
}
