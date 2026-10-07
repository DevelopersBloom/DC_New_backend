<?php

namespace Tests\Feature;

use App\Services\Reports\CreditActivityReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tranche 2: monthly trend, collateral performance, borrower mix and origination LTV.
 * Same isolation approach as CreditActivityReportTest (own pawnshop per test, rolled back).
 */
class CreditActivityTrendLtvTest extends TestCase
{
    use DatabaseTransactions;

    private int $ps;
    private int $cat1;
    private int $cat2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ps = DB::table('pawnshops')->insertGetId([]);
        $this->cat1 = DB::table('categories')->insertGetId(['name' => 't2-a', 'title' => 'T2 A']);
        $this->cat2 = DB::table('categories')->insertGetId(['name' => 't2-b', 'title' => 'T2 B']);
    }

    private function report(int $m = 9, int $y = 2026, string $today = '2026-10-07', ?int $ps = null, int $months = 6): array
    {
        return (new CreditActivityReportService(Carbon::parse($today, 'Asia/Yerevan')))->build($ps ?? $this->ps, $m, $y, $months);
    }

    private function contract(?int $client = null, ?int $cat = null, ?int $ps = null): int
    {
        return DB::table('contracts')->insertGetId([
            'client_id' => $client ?? DB::table('clients')->insertGetId([]), 'category_id' => $cat ?? $this->cat1,
            'pawnshop_id' => $ps ?? $this->ps, 'estimated_amount' => 0, 'provided_amount' => 0, 'deadline' => '2027-01-01',
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
        ]);
    }

    private function hist(int $c, string $amountType, string $type, float $amount, string $date, ?int $cat = null, ?int $deal = null, ?int $ps = null, bool $deleted = false): void
    {
        DB::table('contract_amount_histories')->insert([
            'contract_id' => $c, 'amount_type' => $amountType, 'type' => $type, 'amount' => $amount, 'date' => $date,
            'category_id' => $cat, 'deal_id' => $deal, 'pawnshop_id' => $ps ?? $this->ps,
            'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A loan: estimate row and first disbursement on the same day. Returns the contract id. */
    private function loan(float $estimate, float $amount, string $date, ?int $cat = null, ?int $client = null): int
    {
        $cat ??= $this->cat1;
        $c = $this->contract($client, $cat);
        $this->hist($c, 'estimated_amount', 'in', $estimate, $date, $cat);
        $this->hist($c, 'provided_amount', 'in', $amount, $date, $cat);
        return $c;
    }

    private function ltv(array $r): array
    {
        return $r['origination_ltv'];
    }

    // ------------------------------------------------------------------ trend

    public function test_six_month_trend_matches_tranche1_headlines_for_selected_month(): void
    {
        $this->loan(1000000, 400000, '2026-04-10');
        $c = $this->loan(2000000, 500000, '2026-09-05', $this->cat2);
        $this->hist($c, 'provided_amount', 'in', 100000, '2026-09-20', $this->cat2);   // top-up
        $this->hist($c, 'provided_amount', 'out', 50000, '2026-09-25', $this->cat2);    // repayment

        $r = $this->report();
        $this->assertCount(6, $r['trend']['months']);
        $this->assertSame('2026-04', $r['trend']['months'][0]['month']);
        $sel = end($r['trend']['months']);
        $a = $r['activity'];
        $this->assertSame($a['total_disbursement']['current'], $sel['total_disbursement']);
        $this->assertSame($a['new_loan_disbursement']['current'], $sel['new_disbursement']);
        $this->assertSame($a['top_up_disbursement']['current'], $sel['top_up_disbursement']);
        $this->assertSame((float) $a['new_loan_count']['current'], (float) $sel['new_loan_count']);
        $this->assertSame($a['unique_borrowers']['current'], (float) $sel['unique_borrowers']);
        $this->assertSame($r['position']['estimated_collateral']['current'], $sel['estimated_collateral']);
        $this->assertSame($r['position']['outstanding_principal']['current'], $sel['outstanding_principal']);
        $this->assertSame(3000000.0, $sel['estimated_collateral']);
        $this->assertSame(550000.0 + 400000.0, $sel['outstanding_principal']);
        $this->assertSame(100000.0, $sel['top_up_disbursement']);
    }

    public function test_fewer_months_than_requested_returns_only_months_with_history_and_zero_months_inside(): void
    {
        $this->loan(1000000, 400000, '2026-07-10');   // history starts in July; August has no activity
        $r = $this->report(9);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($r['trend']['months'], 'month'));
        $aug = $r['trend']['months'][1];
        $this->assertSame(0.0, $aug['total_disbursement']);
        $this->assertSame(0, $aug['new_loan_count']);
        $this->assertSame(400000.0, $aug['outstanding_principal'], 'stock carried through an empty month');
        $this->assertNull($aug['median_origination_ltv']);
        $this->assertSame(0, $aug['ltv_eligible_loans']);
    }

    public function test_no_history_gives_an_empty_trend_and_twelve_month_window_is_supported(): void
    {
        $this->assertSame([], $this->report()['trend']['months']);
        $this->loan(1000000, 400000, '2026-01-10');
        $this->assertCount(9, $this->report(9, 2026, '2026-10-07', null, 12)['trend']['months']);   // Jan..Sep
        $this->assertCount(6, $this->report()['trend']['months']);
    }

    public function test_current_month_is_partial_and_equals_mtd_headline(): void
    {
        $this->loan(1000000, 400000, '2026-09-10');
        $this->loan(1000000, 300000, '2026-10-03');
        $this->loan(1000000, 900000, '2026-10-20');   // future relative to "today"
        $r = $this->report(10);
        $oct = end($r['trend']['months']);
        $this->assertTrue($oct['is_partial']);
        $this->assertSame('2026-10-07', $oct['end']);
        $this->assertSame($r['activity']['total_disbursement']['current'], $oct['total_disbursement']);
        $this->assertSame(300000.0, $oct['total_disbursement']);
        $this->assertFalse($this->report(9)['trend']['months'][count($this->report(9)['trend']['months']) - 1]['is_partial']);
    }

    public function test_borrower_trend_new_vs_repeat_and_loan_sizes(): void
    {
        $client = DB::table('clients')->insertGetId([]);
        $this->loan(1000000, 200000, '2026-08-05', null, $client);
        $this->loan(1000000, 600000, '2026-09-05', null, $client);          // repeat
        $this->loan(1000000, 100000, '2026-09-06');                           // new
        $r = $this->report();
        $aug = $r['trend']['months'][count($r['trend']['months']) - 2];
        $sep = end($r['trend']['months']);
        $this->assertSame([1, 0], [$aug['new_borrowers'], $aug['repeat_borrowers']]);
        $this->assertSame([1, 1], [$sep['new_borrowers'], $sep['repeat_borrowers']]);
        $this->assertSame(50.0, $sep['new_borrower_share_percent']);
        $mix = $r['borrower_mix']['current'];
        $this->assertSame(600000.0, $mix['loan_sizes']['repeat']['median']);
        $this->assertSame(100000.0, $mix['loan_sizes']['new']['average']);
        $this->assertSame(1, $r['borrower_mix']['previous']['new']);
    }

    public function test_category_trend_missing_category_soft_delete_and_isolation(): void
    {
        $this->loan(1000000, 100000, '2026-09-05', $this->cat1);
        $this->loan(1000000, 200000, '2026-09-06', $this->cat2);
        $c = $this->contract();
        $this->hist($c, 'provided_amount', 'in', 50000, '2026-09-07', null);          // missing category
        $this->hist($c, 'provided_amount', 'in', 999999, '2026-09-08', $this->cat1, null, null, true);   // soft-deleted
        $other = DB::table('pawnshops')->insertGetId([]);
        $oc = $this->contract(null, $this->cat1, $other);
        $this->hist($oc, 'provided_amount', 'in', 777000, '2026-09-05', $this->cat1, null, $other);

        $r = $this->report();
        $sep = end($r['trend']['months']);
        $this->assertSame((float) 100000, $sep['categories'][(string) $this->cat1]);
        $this->assertSame(200000.0, $sep['categories'][(string) $this->cat2]);
        $this->assertSame(50000.0, $sep['categories']['null']);
        $this->assertSame(350000.0, $sep['total_disbursement']);
        $keys = array_column($r['trend']['category_legend'], 'key');
        $this->assertContains('null', $keys);
        $this->assertSame(350000.0, array_sum($sep['categories']));
        $this->assertSame(777000.0, end($this->report(9, 2026, '2026-10-07', $other)['trend']['months'])['total_disbursement']);
    }

    // ------------------------------------------------------------------ collateral performance + drivers

    public function test_collateral_performance_share_and_pp_change_reconcile_to_total(): void
    {
        $this->loan(1000000, 300000, '2026-08-05', $this->cat1);
        $this->loan(1000000, 700000, '2026-08-06', $this->cat2);        // Aug: 30% / 70%
        $this->loan(1000000, 600000, '2026-09-05', $this->cat1);
        $this->loan(1000000, 200000, '2026-09-06', $this->cat1);
        $this->loan(1000000, 200000, '2026-09-07', $this->cat2);       // Sep: 80% / 20%
        $r = $this->report();
        $perf = collect($r['collateral_performance']);
        $a = $perf->firstWhere('category_id', $this->cat1);
        $this->assertSame(800000.0, $a['period_disbursement']);
        $this->assertSame(80.0, $a['share_percent']);
        $this->assertSame(30.0, $a['previous_share_percent']);
        $this->assertSame(50.0, $a['share_change_pp']);
        $this->assertSame(2, $a['new_loan_count']);
        $this->assertSame(400000.0, $a['average_new_loan']);
        $this->assertSame(1, $a['previous_new_loan_count']);
        $this->assertSame(166.7, $a['change_percent']);
        $this->assertEqualsWithDelta($r['activity']['total_disbursement']['current'], $perf->sum('period_disbursement'), 0.001);
    }

    public function test_drivers_and_highlights_are_factual(): void
    {
        $this->loan(1000000, 500000, '2026-08-05');
        $this->loan(1000000, 500000, '2026-08-06');
        $this->loan(1000000, 900000, '2026-09-05');
        $r = $this->report();
        $this->assertSame(-50.0, $r['drivers']['new_loan_count']['change_percent']);
        $this->assertSame(80.0, $r['drivers']['average_new_loan']['change_percent']);
        $types = array_column($r['highlights'], 'type');
        $this->assertContains('new_loan_count_change', $types);
        $this->assertContains('category_decline', $types);
    }

    // ------------------------------------------------------------------ origination LTV

    public function test_single_loan_ltv_and_null_state_without_loans(): void
    {
        $this->assertNull($this->ltv($this->report())['median'], 'no loans -> null, not 0%');
        $this->loan(1000000, 600000, '2026-09-05');
        $l = $this->ltv($this->report());
        $this->assertSame(60.0, $l['median']);
        $this->assertSame(1, $l['eligible_loans']);
        $this->assertSame(0, $l['excluded_loans']);
    }

    public function test_average_median_and_weighted_ratio_are_distinct(): void
    {
        $this->loan(1000000, 300000, '2026-09-05');     // 30%
        $this->loan(1000000, 500000, '2026-09-06');     // 50%
        $this->loan(10000000, 9000000, '2026-09-07');   // 90%
        $l = $this->ltv($this->report());
        $this->assertSame(50.0, $l['median']);
        $this->assertSame(56.7, $l['average']);          // (30+50+90)/3
        $this->assertSame(round(9800000 / 12000000 * 100, 1), $l['weighted_ratio']);   // sum/sum = 81.7
        $this->assertSame(30.0, $l['min']);
        $this->assertSame(90.0, $l['max']);
    }

    public function test_zero_and_missing_estimates_are_excluded_and_counted(): void
    {
        $this->loan(0, 100000, '2026-09-05');                                    // zero estimate
        $c = $this->contract();
        $this->hist($c, 'provided_amount', 'in', 200000, '2026-09-06', $this->cat1);   // no estimate rows
        $c2 = $this->contract();
        $this->hist($c2, 'estimated_amount', 'in', 500000, '2026-09-10', $this->cat1);  // estimate AFTER disbursement
        $this->hist($c2, 'provided_amount', 'in', 100000, '2026-09-08', $this->cat1);
        $this->loan(1000000, 500000, '2026-09-07');
        $l = $this->ltv($this->report());
        $this->assertSame(4, $l['total_new_loans']);
        $this->assertSame(1, $l['eligible_loans']);
        $this->assertSame(3, $l['excluded_loans']);
        $this->assertSame(2, $l['exclusions']['no_estimate']);
        $this->assertSame(1, $l['exclusions']['zero_estimate']);
    }

    public function test_revaluation_top_up_and_estimate_removal_after_origination_do_not_change_ltv(): void
    {
        $c = $this->loan(1000000, 500000, '2026-09-05');
        $before = $this->ltv($this->report())['median'];
        $this->hist($c, 'estimated_amount', 'out', 600000, '2026-09-20', $this->cat1);   // revaluation down
        $this->hist($c, 'estimated_amount', 'in', 100000, '2026-09-21', $this->cat1);
        $this->hist($c, 'provided_amount', 'in', 400000, '2026-09-25', $this->cat1);     // top-up
        $this->assertSame(50.0, $before);
        $this->assertSame(50.0, $this->ltv($this->report())['median']);
    }

    public function test_estimate_before_disbursement_is_used_and_same_day_changes_are_netted(): void
    {
        $c = $this->contract();
        $this->hist($c, 'estimated_amount', 'in', 800000, '2026-09-01', $this->cat1);
        $this->hist($c, 'estimated_amount', 'out', 300000, '2026-09-03', $this->cat1);   // revaluation before: net 500k
        $this->hist($c, 'estimated_amount', 'in', 500000, '2026-09-05', $this->cat1);    // same day as disbursement: included -> 1,000,000
        $this->hist($c, 'provided_amount', 'in', 250000, '2026-09-05', $this->cat1);
        $this->assertSame(25.0, $this->ltv($this->report())['median']);
    }

    public function test_split_first_disbursement_rows_sharing_one_deal_form_one_loan(): void
    {
        $c = $this->contract();
        $deal = DB::table('deals')->insertGetId(['type' => 'out', 'amount' => 2500000, 'date' => '2026-09-05', 'filter_type' => 'contract', 'pawnshop_id' => $this->ps, 'cash' => false]);
        $this->hist($c, 'estimated_amount', 'in', 5000000, '2026-09-05', $this->cat1);
        $this->hist($c, 'provided_amount', 'in', 472500, '2026-09-05', $this->cat1, $deal);
        $this->hist($c, 'provided_amount', 'in', 2027500, '2026-09-06', $this->cat1, $deal);
        $r = $this->report();
        $this->assertSame(1.0, (float) $r['activity']['new_loan_count']['current']);
        $this->assertSame(0.0, $r['activity']['top_up_disbursement']['current'], 'no false top-up');
        $this->assertSame(2500000.0, $r['activity']['new_loan_disbursement']['current']);
        $this->assertSame(1, $this->ltv($r)['eligible_loans']);
        $this->assertSame(50.0, $this->ltv($r)['median']);
    }

    public function test_bucket_boundaries_and_above_100(): void
    {
        foreach ([400000, 600000, 700000, 800000, 1000000, 1000001, 1500000, 300000] as $i => $amount) {
            $this->loan(1000000, $amount, '2026-09-0' . ($i + 1));
        }
        $l = $this->ltv($this->report());
        $counts = array_column($l['distribution'], 'loan_count', 'bucket');
        $this->assertSame(['0-40' => 2, '40-60' => 1, '60-70' => 1, '70-80' => 1, '80-100' => 1, '100+' => 2], $counts);
        $this->assertSame(37.5, $l['above_80_share_percent']);   // 100%, 100.0001% and 150% are above 80 (3 of 8)
    }

    public function test_ltv_by_category_and_null_for_empty_category(): void
    {
        $this->loan(1000000, 300000, '2026-09-05', $this->cat1);
        $this->loan(1000000, 500000, '2026-09-06', $this->cat1);
        $this->loan(1000000, 900000, '2026-09-07', $this->cat2);
        $perf = collect($this->report()['collateral_performance']);
        $this->assertSame(40.0, $perf->firstWhere('category_id', $this->cat1)['median_origination_ltv']);
        $this->assertSame(90.0, $perf->firstWhere('category_id', $this->cat2)['median_origination_ltv']);
        $empty = $perf->first(fn ($c) => $c['ltv_eligible_loans'] === 0);
        $this->assertNull($empty['median_origination_ltv']);
        $this->assertNull($empty['average_origination_ltv']);
    }

    public function test_ltv_ignores_soft_deleted_estimates_and_other_pawnshops(): void
    {
        $c = $this->contract();
        $this->hist($c, 'estimated_amount', 'in', 1000000, '2026-09-01', $this->cat1);
        $this->hist($c, 'estimated_amount', 'in', 9000000, '2026-09-02', $this->cat1, null, null, true);    // soft-deleted
        $other = DB::table('pawnshops')->insertGetId([]);
        $this->hist($c, 'estimated_amount', 'in', 5000000, '2026-09-03', $this->cat1, null, $other);        // foreign pawnshop row
        $this->hist($c, 'provided_amount', 'in', 500000, '2026-09-05', $this->cat1);
        $this->assertSame(50.0, $this->ltv($this->report())['median']);
        $this->assertSame(0, $this->ltv($this->report(9, 2026, '2026-10-07', $other))['total_new_loans']);
    }
}
