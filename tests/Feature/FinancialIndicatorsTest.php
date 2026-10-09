<?php

namespace Tests\Feature;

use App\Services\Reports\CreditActivityReportService;
use App\Services\Reports\FinancialIndicatorsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Financial Indicators tab (daily table): source-of-truth, daily and cross-report reconciliation tests.
 * Every test builds its own pawnshop; DatabaseTransactions rolls everything back. "Today" is 2026-10-07.
 */
class FinancialIndicatorsTest extends TestCase
{
    use DatabaseTransactions;

    private int $ps;
    private int $gold;
    private int $car;
    private int $estate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ps = DB::table('pawnshops')->insertGetId([]);
        $this->gold = DB::table('categories')->insertGetId(['name' => 'fi-gold', 'title' => 'FI Gold']);
        $this->car = DB::table('categories')->insertGetId(['name' => 'fi-car', 'title' => 'FI Car']);
        $this->estate = DB::table('categories')->insertGetId(['name' => 'fi-estate', 'title' => 'FI Estate']);
    }

    // ---------------------------------------------------------------- helpers

    private function base(string $today = '2026-10-07'): CreditActivityReportService
    {
        return new CreditActivityReportService(Carbon::parse($today, 'Asia/Yerevan'));
    }

    private function fin(int $m = 9, int $y = 2026, string $today = '2026-10-07', ?int $ps = null): array
    {
        return (new FinancialIndicatorsService($this->base($today)))->build($ps ?? $this->ps, $m, $y);
    }

    private function row(array $r, string $date): array
    {
        return collect($r['rows'])->firstWhere('date', $date);
    }

    private function contract(int $cat, ?int $ps = null, array $extra = []): int
    {
        return DB::table('contracts')->insertGetId($extra + [
            'client_id' => DB::table('clients')->insertGetId([]), 'category_id' => $cat, 'pawnshop_id' => $ps ?? $this->ps,
            'estimated_amount' => 0, 'provided_amount' => 0, 'deadline' => '2027-01-01',
            'status' => 'initial', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
        ]);
    }

    private function hist(int $contract, string $amountType, string $type, float $amount, string $date, ?int $cat, ?int $deal = null, ?int $ps = null, bool $deleted = false): int
    {
        return DB::table('contract_amount_histories')->insertGetId([
            'contract_id' => $contract, 'amount_type' => $amountType, 'type' => $type, 'amount' => $amount, 'date' => $date,
            'category_id' => $cat, 'deal_id' => $deal, 'pawnshop_id' => $ps ?? $this->ps,
            'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deal(string $type, float $amount, string $date, bool $cash, ?string $filter = null, ?int $ps = null, bool $deleted = false): int
    {
        return DB::table('deals')->insertGetId([
            'type' => $type, 'amount' => $amount, 'date' => $date, 'cash' => $cash, 'filter_type' => $filter,
            'pawnshop_id' => $ps ?? $this->ps, 'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** New loan: estimate + first disbursement + the matching out/contract deal. */
    private function loan(int $cat, float $est, float $amount, string $date, bool $cash = false): int
    {
        $c = $this->contract($cat);
        $deal = $this->deal('out', $amount, $date, $cash, 'contract');
        $this->hist($c, 'estimated_amount', 'in', $est, $date, $cat);
        $this->hist($c, 'provided_amount', 'in', $amount, $date, $cat, $deal);
        return $c;
    }

    // ---------------------------------------------------------------- A / H: history is the source, current state is not

    public function test_outstanding_comes_from_history_not_from_contracts_provided_amount(): void
    {
        $c = $this->loan($this->gold, 1000000, 400000, '2026-09-10');
        DB::table('contracts')->where('id', $c)->update(['provided_amount' => 999999, 'estimated_amount' => 5]);   // mutable state, deliberately wrong

        $r = $this->fin();
        $this->assertSame(400000.0, $this->row($r, '2026-09-30')['position']['outstanding_principal']);
        $this->assertSame(1000000.0, $this->row($r, '2026-09-30')['position']['estimated_collateral']);
        $this->assertNotSame(0, $r['reconciliation']['principal']['mismatched_contracts'], 'the discrepancy is reported, not hidden or fixed');
        $this->assertEquals(999999, DB::table('contracts')->where('id', $c)->value('provided_amount'), 'no data is altered');
    }

    public function test_later_changes_do_not_alter_an_earlier_day(): void
    {
        $c = $this->loan($this->gold, 1000000, 400000, '2026-09-10');
        $this->hist($c, 'provided_amount', 'out', 400000, '2026-09-20', $this->gold);   // fully repaid later
        $this->hist($c, 'estimated_amount', 'out', 1000000, '2026-09-20', $this->gold);

        $r = $this->fin();
        $this->assertSame(400000.0, $this->row($r, '2026-09-19')['position']['outstanding_principal'], 'a past row keeps what was true that day');
        $this->assertSame(1000000.0, $this->row($r, '2026-09-19')['position']['estimated_collateral']);
        $this->assertSame(0.0, $this->row($r, '2026-09-20')['position']['outstanding_principal']);
    }

    // ---------------------------------------------------------------- B / C: cash vs bank

    public function test_bank_deal_never_touches_cash_and_cash_deal_never_touches_bank(): void
    {
        $this->deal('in', 100000, '2026-09-05', false, 'payment');    // bank
        $this->deal('in', 40000, '2026-09-05', true, 'payment');      // cash
        $this->deal('cost_out', 15000, '2026-09-06', true, 'expense');
        $this->deal('out', 30000, '2026-09-07', false, 'contract');

        $r = $this->fin();
        $this->assertSame(25000.0, $this->row($r, '2026-09-30')['position']['cash_balance']);
        $this->assertSame(70000.0, $this->row($r, '2026-09-30')['position']['bank_balance']);
        $this->assertSame(25000.0, $this->row($r, '2026-09-06')['position']['cash_balance']);
        $this->assertSame(100000.0, $this->row($r, '2026-09-06')['position']['bank_balance'], 'a cash expense leaves the bank alone');
        $this->assertSame(70000.0, $this->row($r, '2026-09-07')['position']['bank_balance']);
    }

    public function test_internal_transfer_moves_money_between_cash_and_bank_without_changing_the_total(): void
    {
        $this->deal('in', 500000, '2026-09-02', true);                   // cashbox top-up
        $this->deal('in', 200000, '2026-09-03', false);                  // bank top-up ...
        $this->deal('out', 200000, '2026-09-03', true);                  // ... funded out of the cashbox (addCashbox)

        $p = $this->row($this->fin(), '2026-09-03')['position'];
        $this->assertSame(300000.0, $p['cash_balance']);
        $this->assertSame(200000.0, $p['bank_balance']);
    }

    public function test_deleted_deals_other_pawnshops_and_malformed_dates_are_excluded_and_counted(): void
    {
        $other = DB::table('pawnshops')->insertGetId([]);
        $this->deal('in', 100, '2026-09-05', true);
        $this->deal('in', 7777, '2026-09-05', true, null, null, true);         // soft-deleted
        $this->deal('in', 8888, '2026-09-05', true, null, $other);              // another tenant
        $this->deal('in', 9999, '05.09.2026', true);                             // malformed date

        $r = $this->fin();
        $this->assertSame(100.0, $this->row($r, '2026-09-30')['position']['cash_balance']);
        $this->assertSame(1, $r['reconciliation']['data_quality']['malformed_deal_dates']);
        $this->assertSame(8888.0, $this->row($this->fin(9, 2026, '2026-10-07', $other), '2026-09-30')['position']['cash_balance']);
    }

    // ---------------------------------------------------------------- D: no hard-coded constants

    public function test_empty_category_is_zero_after_the_old_hardcoded_cutoff_date(): void
    {
        // The legacy table added 21,880,000 / 8,970,000 to car / real-estate rows from 2025-08-01.
        $this->loan($this->gold, 100000, 50000, '2026-09-04');
        $r = $this->fin();
        $last = $this->row($r, '2026-09-30');
        $this->assertSame(0.0, $last['by_category'][$this->car]['estimated_collateral'] ?? 0.0);
        $this->assertSame(0.0, $last['by_category'][$this->estate]['estimated_collateral'] ?? 0.0);
        $this->assertSame(100000.0, $last['position']['estimated_collateral']);
    }

    // ---------------------------------------------------------------- E / F / G: flows vs stocks

    public function test_split_disbursement_is_one_new_loan_and_never_a_false_top_up(): void
    {
        $c = $this->contract($this->car);
        $deal = $this->deal('out', 2500000, '2026-09-12', false, 'contract');
        $this->hist($c, 'estimated_amount', 'in', 5000000, '2026-09-12', $this->car);
        $this->hist($c, 'provided_amount', 'in', 472500, '2026-09-12', $this->car, $deal);
        $this->hist($c, 'provided_amount', 'in', 2027500, '2026-09-12', $this->car, $deal);   // same deal, second row

        $day = $this->row($this->fin(), '2026-09-12')['activity'];
        $this->assertSame(2500000.0, $day['new_disbursement']);
        $this->assertSame(0.0, $day['top_up_disbursement']);
        $this->assertSame(1, $day['new_loan_count']);
    }

    public function test_top_up_is_separate_and_revaluation_moves_collateral_but_not_disbursement(): void
    {
        $c = $this->loan($this->gold, 1000000, 300000, '2026-09-05');
        $this->hist($c, 'provided_amount', 'in', 50000, '2026-09-15', $this->gold);                // top-up
        $this->hist($c, 'estimated_amount', 'in', 200000, '2026-09-20', $this->gold);              // revaluation up

        $r = $this->fin();
        $this->assertSame(50000.0, $this->row($r, '2026-09-15')['activity']['top_up_disbursement']);
        $this->assertSame(0.0, $this->row($r, '2026-09-15')['activity']['new_disbursement']);
        $reval = $this->row($r, '2026-09-20');
        $this->assertSame(1200000.0, $reval['position']['estimated_collateral']);
        $this->assertSame(0.0, $reval['activity']['total_disbursement'], 'revaluation is not a disbursement');
        $this->assertSame(350000.0, $reval['position']['outstanding_principal']);
    }

    public function test_repayment_reduces_outstanding_but_not_disbursement(): void
    {
        $c = $this->loan($this->gold, 1000000, 300000, '2026-09-05');
        $this->hist($c, 'provided_amount', 'out', 120000, '2026-09-18', $this->gold);
        $this->deal('in', 135000, '2026-09-18', false, 'payment');

        $r = $this->fin();
        $day = $this->row($r, '2026-09-18');
        $this->assertSame(180000.0, $day['position']['outstanding_principal']);
        $this->assertSame(0.0, $day['activity']['total_disbursement']);
        $this->assertSame(120000.0, $day['activity']['principal_reduction']);
        $this->assertSame(135000.0, $day['activity']['receipts']);
        $this->assertSame(300000.0, $r['summary']['activity']['total_disbursement'], 'repayment never reduces the historical disbursement');
    }

    // ---------------------------------------------------------------- daily table shape

    public function test_first_day_mid_month_month_end_and_empty_days_carry_the_position_forward(): void
    {
        $this->loan($this->gold, 1000000, 300000, '2026-08-20');     // opening position before the month
        $this->loan($this->car, 2000000, 900000, '2026-09-15');

        $r = $this->fin();
        $this->assertCount(30, $r['rows']);
        $this->assertSame('2026-09-01', $r['rows'][0]['date']);
        $this->assertSame(300000.0, $this->row($r, '2026-09-01')['position']['outstanding_principal'], 'first day = opening position');
        $this->assertSame(0.0, $this->row($r, '2026-09-01')['activity']['total_disbursement']);
        $this->assertSame(300000.0, $this->row($r, '2026-09-14')['position']['outstanding_principal'], 'day before the disbursement');
        $this->assertSame(1200000.0, $this->row($r, '2026-09-15')['position']['outstanding_principal']);
        $this->assertSame(900000.0, $this->row($r, '2026-09-15')['activity']['total_disbursement']);
        $this->assertSame(1200000.0, $this->row($r, '2026-09-16')['position']['outstanding_principal'], 'no-activity day: stock carried, flow zero');
        $this->assertSame(0.0, $this->row($r, '2026-09-16')['activity']['total_disbursement']);
        $this->assertSame(3000000.0, $this->row($r, '2026-09-30')['position']['estimated_collateral']);
        $this->assertSame(40.0, $this->row($r, '2026-09-30')['position']['loan_collateral_ratio']);
        $this->assertSame(300000.0, $r['summary']['opening']['outstanding_principal']);
        $this->assertSame(1200000.0, $r['summary']['closing']['outstanding_principal']);
    }

    public function test_current_month_stops_at_today(): void
    {
        $r = $this->fin(10, 2026);
        $this->assertCount(7, $r['rows']);
        $this->assertSame('2026-10-07', $r['period']['end']);
        $this->assertTrue($r['period']['is_mtd']);
    }

    public function test_history_drilldown_lists_the_rows_behind_the_day(): void
    {
        $c = $this->loan($this->gold, 1000000, 300000, '2026-09-05');
        $entries = $this->row($this->fin(), '2026-09-05')['contract_amount_histories'];
        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing(['estimated_amount', 'provided_amount'], array_column($entries, 'amount_type'));
        $this->assertSame($c, $entries[0]['contract_id']);
    }

    // ---------------------------------------------------------------- reconciliation

    public function test_month_end_row_equals_management_summary_and_daily_flows_sum_to_period_flows(): void
    {
        $a = $this->loan($this->gold, 1000000, 300000, '2026-09-03');
        $b = $this->loan($this->car, 4000000, 1800000, '2026-09-11', true);
        $this->loan($this->estate, 0, 250000, '2026-09-12');          // no estimate row: still reconciles
        $this->hist($a, 'provided_amount', 'in', 40000, '2026-09-17', $this->gold);
        $this->hist($b, 'provided_amount', 'out', 600000, '2026-09-25', $this->car);
        $this->hist($b, 'estimated_amount', 'in', 500000, '2026-09-26', $this->car);
        $this->deal('in', 5000000, '2026-09-01', false);
        $this->deal('in', 700000, '2026-09-25', true, 'payment');

        $fin = $this->fin();
        $base = $this->base();
        $mgmt = $base->build($this->ps, 9, 2026);
        $last = $this->row($fin, '2026-09-30');

        $this->assertSame($mgmt['position']['estimated_collateral']['current'], $last['position']['estimated_collateral']);
        $this->assertSame($mgmt['position']['outstanding_principal']['current'], $last['position']['outstanding_principal']);
        $this->assertSame($mgmt['position']['cash_balance']['current'], $last['position']['cash_balance']);
        $this->assertSame($mgmt['position']['bank_balance']['current'], $last['position']['bank_balance']);
        $this->assertSame($mgmt['activity']['total_disbursement']['current'], $fin['summary']['activity']['total_disbursement']);
        $this->assertSame($mgmt['activity']['new_loan_disbursement']['current'], $fin['summary']['activity']['new_disbursement']);
        $this->assertSame($mgmt['activity']['top_up_disbursement']['current'], $fin['summary']['activity']['top_up_disbursement']);
        $this->assertEqualsWithDelta(array_sum(array_column(array_column($fin['rows'], 'activity'), 'total_disbursement')),
            $mgmt['activity']['total_disbursement']['current'], 0.005);
        $this->assertTrue($fin['reconciliation']['ok'], json_encode($fin['reconciliation']['checks']));
    }

    public function test_category_values_sum_to_the_totals_and_uncategorised_rows_form_their_own_bucket(): void
    {
        $c = $this->contract($this->gold);
        $this->hist($c, 'estimated_amount', 'in', 100000, '2026-09-04', null);          // category unknown
        $this->hist($c, 'provided_amount', 'in', 60000, '2026-09-04', null);
        $this->loan($this->car, 1000000, 400000, '2026-09-05');

        $r = $this->fin();
        $last = $this->row($r, '2026-09-30');
        $this->assertArrayHasKey('null', $last['by_category'], 'never silently dropped');
        $this->assertEqualsWithDelta($last['position']['estimated_collateral'], array_sum(array_column($last['by_category'], 'estimated_collateral')), 0.005);
        $this->assertEqualsWithDelta($last['position']['outstanding_principal'], array_sum(array_column($last['by_category'], 'outstanding_principal')), 0.005);
        $this->assertContains('null', array_column($r['categories'], 'key'));
        foreach ($r['rows'] as $row) {
            $this->assertEqualsWithDelta($row['activity']['total_disbursement'], array_sum(array_column($row['by_category'], 'total_disbursement')), 0.005);
        }
    }

    public function test_cash_bank_breakdown_adds_up_to_the_balances(): void
    {
        $this->deal('in', 500, '2026-09-02', true);
        $this->deal('out', 120, '2026-09-03', true, 'contract');
        $this->deal('in', 900, '2026-09-04', false, 'ndm');
        $this->deal('cost_out', 50, '2026-09-05', false, 'expense');
        $this->deal('weird', 77, '2026-09-05', true);                                    // unknown type: excluded and listed

        $r = $this->fin();
        $b = $r['reconciliation']['cash_bank_breakdown'];
        $last = $this->row($r, '2026-09-30')['position'];
        $this->assertSame($last['cash_balance'], $b['cash_total']);
        $this->assertSame($last['bank_balance'], $b['bank_total']);
        $this->assertSame('weird', $b['excluded'][0]['type']);
        $this->assertSame(380.0, $b['cash_total']);
    }

    public function test_history_rows_on_deleted_deals_are_reported_as_data_quality(): void
    {
        $c = $this->contract($this->gold);
        $dead = $this->deal('in', 10, '2026-09-05', false, 'payment', null, true);
        $this->hist($c, 'provided_amount', 'out', 10, '2026-09-05', $this->gold, $dead);

        $this->assertSame(1, $this->fin()['reconciliation']['data_quality']['history_on_deleted_deals']);
    }

    public function test_soft_deleted_and_foreign_history_rows_are_ignored(): void
    {
        $other = DB::table('pawnshops')->insertGetId([]);
        $c = $this->contract($this->gold);
        $this->hist($c, 'provided_amount', 'in', 100000, '2026-09-05', $this->gold);
        $this->hist($c, 'provided_amount', 'in', 55555, '2026-09-06', $this->gold, null, null, true);
        $this->hist($c, 'provided_amount', 'in', 77777, '2026-09-07', $this->gold, null, $other);

        $this->assertSame(100000.0, $this->row($this->fin(), '2026-09-30')['position']['outstanding_principal']);
    }

    // ---------------------------------------------------------------- query budget

    public function test_query_count_does_not_depend_on_the_number_of_days(): void
    {
        $count = function (int $m, int $y, string $today): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->fin($m, $y, $today);
            return count(DB::getQueryLog());
        };
        $feb = $count(2, 2026, '2026-10-07');   // 28 days
        $oct = $count(8, 2026, '2026-10-07');   // 31 days
        $this->assertSame($feb, $oct);
        $this->assertLessThanOrEqual(16, $oct);
    }
}
