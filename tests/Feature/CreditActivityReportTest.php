<?php

namespace Tests\Feature;

use App\Services\Reports\CreditActivityReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CreditActivityReportService (Tranche 1 of /reports): position (stock) vs activity (flow).
 * Every test builds its own pawnshop, so real data never interferes; DatabaseTransactions rolls it back.
 * "Today" is fixed to 2026-10-07 unless a test says otherwise. Needs the project's dev MySQL.
 */
class CreditActivityReportTest extends TestCase
{
    use DatabaseTransactions;

    private int $ps;
    private int $cat1;
    private int $cat2;
    private int $cat3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ps = DB::table('pawnshops')->insertGetId([]);
        $this->cat1 = DB::table('categories')->insertGetId(['name' => 't-gold', 'title' => 'T Gold']);
        $this->cat2 = DB::table('categories')->insertGetId(['name' => 't-estate', 'title' => 'T Estate']);
        $this->cat3 = DB::table('categories')->insertGetId(['name' => 't-unused', 'title' => 'T Unused']);
    }

    // ---------------------------------------------------------------- helpers

    private function svc(string $today = '2026-10-07'): CreditActivityReportService
    {
        return new CreditActivityReportService(Carbon::parse($today, 'Asia/Yerevan'));
    }

    private function report(int $m = 10, int $y = 2026, string $today = '2026-10-07', ?int $ps = null): array
    {
        return $this->svc($today)->build($ps ?? $this->ps, $m, $y);
    }

    private function client(): int
    {
        return DB::table('clients')->insertGetId([]);
    }

    private function contract(int $client, int $cat, ?int $ps = null, string $created = '2026-01-01 10:00:00', string $status = 'initial'): int
    {
        return DB::table('contracts')->insertGetId([
            'client_id' => $client, 'category_id' => $cat, 'pawnshop_id' => $ps ?? $this->ps,
            'estimated_amount' => 0, 'provided_amount' => 0, 'deadline' => '2027-01-01',
            'status' => $status, 'created_at' => $created, 'updated_at' => $created,
        ]);
    }

    private function hist(int $contract, string $amountType, string $type, float $amount, string $date, ?int $cat = null, ?int $deal = null, ?int $ps = null, bool $deleted = false): int
    {
        return DB::table('contract_amount_histories')->insertGetId([
            'contract_id' => $contract, 'amount_type' => $amountType, 'type' => $type, 'amount' => $amount, 'date' => $date,
            'category_id' => $cat, 'deal_id' => $deal, 'pawnshop_id' => $ps ?? $this->ps,
            'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function disburse(int $contract, float $amount, string $date, int $cat, ?int $deal = null): int
    {
        return $this->hist($contract, 'provided_amount', 'in', $amount, $date, $cat, $deal);
    }

    private function deal(string $type, float $amount, string $date, bool $cash, ?string $filter = null, ?int $contract = null, ?int $ps = null, bool $deleted = false): int
    {
        return DB::table('deals')->insertGetId([
            'type' => $type, 'amount' => $amount, 'date' => $date, 'cash' => $cash, 'filter_type' => $filter,
            'contract_id' => $contract, 'pawnshop_id' => $ps ?? $this->ps,
            'deleted_at' => $deleted ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function catRow(array $r, int $id): array
    {
        return collect($r['collateral_categories'])->firstWhere('category_id', $id);
    }

    // ---------------------------------------------------------------- activity (flow)

    public function test_new_loans_unique_borrowers_average_median_and_categories(): void
    {
        $a = $this->client();
        $b = $this->client();
        $c1 = $this->contract($a, $this->cat1);
        $c2 = $this->contract($a, $this->cat1);   // same borrower, second new loan
        $c3 = $this->contract($b, $this->cat2);
        $this->disburse($c1, 100000, '2026-10-02', $this->cat1);
        $this->disburse($c2, 200000, '2026-10-03', $this->cat1);
        $this->disburse($c3, 300000, '2026-10-04', $this->cat2);

        $r = $this->report();
        $act = $r['activity'];
        $this->assertSame(600000.0, $act['total_disbursement']['current']);
        $this->assertSame(600000.0, $act['new_loan_disbursement']['current']);
        $this->assertSame(0.0, $act['top_up_disbursement']['current']);
        $this->assertSame(3.0, $act['new_loan_count']['current']);
        $this->assertSame(2.0, $act['unique_borrowers']['current'], 'borrower with two loans counts once');
        $this->assertSame(200000.0, $act['average_new_loan']['current']);
        $this->assertSame(200000.0, $act['median_new_loan']['current']);

        $this->assertSame(300000.0, $this->catRow($r, $this->cat1)['period_disbursement']);
        $this->assertSame(2, $this->catRow($r, $this->cat1)['new_loan_count']);
        $this->assertSame(1, $this->catRow($r, $this->cat1)['unique_borrowers']);
        $this->assertSame(50.0, $this->catRow($r, $this->cat2)['disbursement_share_percent']);
        // H: category totals reconcile; category without activity is present with zeros
        $this->assertSame(0.0, $r['reconciliation']['category_disbursement_difference']);
        $this->assertSame(0.0, $this->catRow($r, $this->cat3)['period_disbursement']);
    }

    public function test_median_of_even_cohort_is_mean_of_middle_pair_not_average(): void
    {
        foreach ([100000, 200000, 300000, 1000000] as $i => $amount) {
            $this->disburse($this->contract($this->client(), $this->cat1), $amount, '2026-10-0' . ($i + 1), $this->cat1);
        }
        $act = $this->report()['activity'];
        $this->assertSame(250000.0, $act['median_new_loan']['current']);
        $this->assertSame(400000.0, $act['average_new_loan']['current']);
    }

    public function test_top_ups_add_to_disbursement_but_never_to_new_loan_count(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->disburse($c, 500000, '2026-09-15', $this->cat1);   // first disbursement before the period
        $this->disburse($c, 50000, '2026-10-05', $this->cat1);    // several top-ups inside it
        $this->disburse($c, 25000, '2026-10-06', $this->cat1);

        $act = $this->report()['activity'];
        $this->assertSame(0.0, $act['new_loan_count']['current']);
        $this->assertSame(0.0, $act['new_loan_disbursement']['current']);
        $this->assertSame(75000.0, $act['top_up_disbursement']['current']);
        $this->assertSame(75000.0, $act['total_disbursement']['current']);
        $this->assertSame(2.0, $act['top_up_events']['current']);
        $this->assertSame(1.0, $act['top_up_contracts']['current']);
        $this->assertSame(0.0, $act['unique_borrowers']['current']);
    }

    public function test_contract_created_earlier_but_first_disbursed_now_is_a_new_loan(): void
    {
        $c = $this->contract($this->client(), $this->cat1, null, '2026-01-01 10:00:00');
        $this->disburse($c, 400000, '2026-10-03', $this->cat1);
        $this->assertSame(1.0, $this->report()['activity']['new_loan_count']['current']);
        $this->assertSame(0.0, $this->report(9)['activity']['new_loan_count']['current']);
    }

    public function test_one_deal_stored_as_two_history_rows_is_one_new_loan(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $deal = $this->deal('out', 2500000, '2026-10-02', false, 'contract', $c);
        $this->disburse($c, 472500, '2026-10-02', $this->cat1, $deal);
        $this->disburse($c, 2027500, '2026-10-03', $this->cat1, $deal);
        $act = $this->report()['activity'];
        $this->assertSame(1.0, $act['new_loan_count']['current']);
        $this->assertSame(2500000.0, $act['new_loan_disbursement']['current']);
        $this->assertSame(0.0, $act['top_up_disbursement']['current']);
    }

    public function test_repayment_reduces_outstanding_principal_but_not_period_disbursement(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->disburse($c, 100000, '2026-10-02', $this->cat1);
        $this->hist($c, 'provided_amount', 'out', 40000, '2026-10-05', $this->cat1);   // principal repayment in the same period
        $r = $this->report();
        $this->assertSame(60000.0, $r['position']['outstanding_principal']['current']);
        $this->assertSame(100000.0, $r['activity']['total_disbursement']['current']);
        $this->assertSame(100000.0, $r['activity']['new_loan_disbursement']['current']);
    }

    public function test_completed_contract_has_zero_principal_but_keeps_its_historical_disbursement(): void
    {
        $c = $this->contract($this->client(), $this->cat1, null, '2026-01-01 10:00:00', 'completed');
        $this->disburse($c, 100000, '2026-09-10', $this->cat1);
        $this->hist($c, 'provided_amount', 'out', 100000, '2026-09-20', $this->cat1);
        $sep = $this->report(9);
        $this->assertSame(0.0, $sep['position']['outstanding_principal']['current']);
        $this->assertSame(100000.0, $sep['activity']['total_disbursement']['current']);
        $this->assertSame(1.0, $sep['activity']['new_loan_count']['current']);
    }

    public function test_new_vs_repeat_borrowers(): void
    {
        $repeat = $this->client();
        $new = $this->client();
        $this->disburse($this->contract($repeat, $this->cat1), 100000, '2026-08-10', $this->cat1);
        $this->disburse($this->contract($repeat, $this->cat1), 100000, '2026-10-02', $this->cat1);
        $this->disburse($this->contract($new, $this->cat1), 100000, '2026-10-03', $this->cat1);
        $act = $this->report()['activity'];
        $this->assertSame(1, $act['new_borrowers']);
        $this->assertSame(1, $act['repeat_borrowers']);
        $this->assertSame(50.0, $act['new_borrower_share_percent']);
    }

    public function test_soft_deleted_history_rows_are_ignored(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->disburse($c, 100000, '2026-10-02', $this->cat1);
        $this->hist($c, 'provided_amount', 'in', 900000, '2026-10-03', $this->cat1, null, null, true);
        $this->hist($c, 'estimated_amount', 'in', 777000, '2026-10-02', $this->cat1, null, null, true);
        $r = $this->report();
        $this->assertSame(100000.0, $r['activity']['total_disbursement']['current']);
        $this->assertSame(0.0, $r['position']['estimated_collateral']['current']);
    }

    // ---------------------------------------------------------------- position (stock)

    public function test_estimated_collateral_added_removed_and_reconciled_by_category(): void
    {
        $c1 = $this->contract($this->client(), $this->cat1);
        $c2 = $this->contract($this->client(), $this->cat2);
        $this->hist($c1, 'estimated_amount', 'in', 1000000, '2026-08-01', $this->cat1);
        $this->hist($c2, 'estimated_amount', 'in', 3000000, '2026-10-02', $this->cat2);
        $this->hist($c1, 'estimated_amount', 'out', 400000, '2026-10-04', $this->cat1);
        $this->hist($c1, 'estimated_amount', 'in', 9000000, '2026-10-20', $this->cat1);    // after the as-of date

        $r = $this->report();
        $est = $r['position']['estimated_collateral'];
        $this->assertSame(3600000.0, $est['current']);
        $this->assertSame(1000000.0, $est['previous'], 'as of 07.09');
        $this->assertSame(260.0, $est['change_percent']);
        $this->assertSame(600000.0, $this->catRow($r, $this->cat1)['estimated_collateral']);
        $this->assertSame(3000000.0, $this->catRow($r, $this->cat2)['estimated_collateral']);
        $this->assertSame(0.0, $r['reconciliation']['estimated_difference']);
    }

    public function test_loan_collateral_ratio_and_pp_change_and_zero_denominator(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->assertNull($this->report()['position']['loan_collateral_ratio']['current'], 'no collateral at all');

        $this->hist($c, 'estimated_amount', 'in', 1000000, '2026-08-01', $this->cat1);
        $this->disburse($c, 400000, '2026-08-02', $this->cat1);
        $this->disburse($c, 150000, '2026-10-02', $this->cat1);
        $ratio = $this->report()['position']['loan_collateral_ratio'];
        $this->assertSame(55.0, $ratio['current']);
        $this->assertSame(40.0, $ratio['previous']);
        $this->assertSame(15.0, $ratio['change_pp']);
        $this->assertSame('up', $ratio['direction']);
    }

    public function test_change_with_zero_previous_is_unavailable_not_infinite(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->disburse($c, 100000, '2026-10-02', $this->cat1);
        $total = $this->report()['activity']['total_disbursement'];
        $this->assertNull($total['change_percent']);
        $this->assertSame('unavailable', $total['direction']);
        $this->assertSame('unchanged', $this->svc()->change(0, 0)['direction']);
        $this->assertSame(-50.0, $this->svc()->change(50, 100)['change_percent']);
        $this->assertSame(50.0, $this->svc()->change(-100, -200)['change_percent']);
    }

    public function test_cash_balance_counts_only_cash_deals_and_bank_only_non_cash(): void
    {
        $this->deal('in', 1000, '2026-10-01', true);
        $this->deal('out', 300, '2026-10-02', true, 'contract');
        $this->deal('expense', 100, '2026-10-02', true);
        $this->deal('cost_out', 50, '2026-10-03', true);
        $this->deal('in', 5000, '2026-10-01', false);
        $this->deal('out', 2000, '2026-10-02', false, 'contract');
        $this->deal('in', 9999, '2026-10-08', true);                 // after the as-of date
        $this->deal('in', 7777, '2026-10-02', true, null, null, null, true);   // soft-deleted
        $other = DB::table('pawnshops')->insertGetId([]);
        $this->deal('in', 123456, '2026-10-02', true, null, null, $other);     // another pawnshop

        $pos = $this->report()['position'];
        $this->assertSame(550.0, $pos['cash_balance']['current'], 'cash=true deals only');
        $this->assertSame(3000.0, $pos['bank_balance']['current'], 'cash=false deals only');
        $this->assertSame(3550.0, $pos['total_liquid_funds']['current']);
    }

    public function test_malformed_deal_dates_are_counted_and_excluded(): void
    {
        $this->deal('in', 1000, '2026-10-01', true);
        $this->deal('in', 500, '01.10.2026', true);
        $r = $this->report();
        $this->assertSame(1000.0, $r['position']['cash_balance']['current']);
        $this->assertSame(1, $r['reconciliation']['malformed_deal_dates']);
    }

    // ---------------------------------------------------------------- disbursement method + reconciliation

    public function test_disbursement_method_split_uses_only_contract_disbursement_deals(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->deal('out', 100000, '2026-10-02', true, 'contract', $c);
        $this->deal('out', 300000, '2026-10-03', false, 'contract', $c);
        $this->deal('out', 999999, '2026-10-03', true, null);            // e.g. transfer from cashbox
        $this->deal('cost_out', 888888, '2026-10-03', false, 'expense'); // expense
        $this->deal('in', 777777, '2026-10-03', true, 'contract');       // lump-sum payment, not a disbursement

        $m = $this->report()['disbursement_method'];
        $this->assertSame(100000.0, $m['cash']['amount']);
        $this->assertSame(25.0, $m['cash']['share_percent']);
        $this->assertSame(300000.0, $m['non_cash']['amount']);
        $this->assertSame(75.0, $m['non_cash']['share_percent']);
    }

    public function test_history_vs_deals_reconciliation_reports_the_difference(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->disburse($c, 100000, '2026-10-02', $this->cat1);
        $this->deal('out', 90000, '2026-10-02', true, 'contract', $c);
        $x = $this->report()['reconciliation'];
        $this->assertSame(100000.0, $x['history_disbursement']);
        $this->assertSame(90000.0, $x['deal_disbursement']);
        $this->assertSame(10000.0, $x['disbursement_difference']);
        $this->assertSame(0, $x['disbursement_count_difference']);
        $rows = $this->svc()->disbursementMismatches($this->ps, '2026-10-01', '2026-10-07');
        $this->assertCount(1, $rows);
        $this->assertSame($c, (int) $rows[0]->contract_id);
    }

    public function test_principal_reconciliation_flags_contracts_whose_balance_differs(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        DB::table('contracts')->where('id', $c)->update(['provided_amount' => 80000]);
        $this->disburse($c, 100000, '2026-10-02', $this->cat1);
        $p = $this->report()['reconciliation']['principal'];
        $this->assertSame(1, $p['mismatched_contracts']);
        $this->assertSame(20000.0, $p['difference']);
    }

    // ---------------------------------------------------------------- categories, tenancy, periods

    public function test_missing_category_is_reported_separately_and_still_reconciles(): void
    {
        $c = $this->contract($this->client(), $this->cat1);
        $this->hist($c, 'provided_amount', 'in', 70000, '2026-10-02', null);
        $this->disburse($this->contract($this->client(), $this->cat1), 30000, '2026-10-03', $this->cat1);
        $r = $this->report();
        $none = collect($r['collateral_categories'])->first(fn ($x) => $x['category_id'] === null);
        $this->assertNotNull($none);
        $this->assertSame(70000.0, $none['period_disbursement']);
        $this->assertSame(0.0, $r['reconciliation']['category_disbursement_difference']);
    }

    public function test_contract_with_histories_in_two_categories_is_not_double_counted(): void
    {
        // Mixed-category contract: money is attributed per history row, never multiplied by a join.
        $c = $this->contract($this->client(), $this->cat1);
        $this->hist($c, 'estimated_amount', 'in', 600000, '2026-10-02', $this->cat1);
        $this->hist($c, 'estimated_amount', 'in', 400000, '2026-10-02', $this->cat2);
        $this->disburse($c, 300000, '2026-10-02', $this->cat1);
        $r = $this->report();
        $this->assertSame(1000000.0, $r['position']['estimated_collateral']['current']);
        $this->assertSame(0.0, $r['reconciliation']['estimated_difference']);
        $this->assertSame(300000.0, $r['activity']['total_disbursement']['current']);
    }

    public function test_other_pawnshops_data_is_never_included(): void
    {
        $other = DB::table('pawnshops')->insertGetId([]);
        $c = $this->contract($this->client(), $this->cat1, $other);
        $this->hist($c, 'estimated_amount', 'in', 5000000, '2026-10-02', $this->cat1, null, $other);
        $this->hist($c, 'provided_amount', 'in', 1000000, '2026-10-02', $this->cat1, null, $other);
        $this->deal('in', 4000, '2026-10-02', true, null, null, $other);
        $mine = $this->report();
        $this->assertSame(0.0, $mine['position']['estimated_collateral']['current']);
        $this->assertSame(0.0, $mine['activity']['total_disbursement']['current']);
        $this->assertSame(0.0, $mine['position']['cash_balance']['current']);
        $theirs = $this->report(10, 2026, '2026-10-07', $other);
        $this->assertSame(5000000.0, $theirs['position']['estimated_collateral']['current']);
    }

    public function test_period_resolution_mtd_complete_month_and_clamp(): void
    {
        $r = $this->report(10, 2026);
        $this->assertSame(['2026-10-01', '2026-10-07', true], [$r['period']['start'], $r['period']['end'], $r['period']['is_mtd']]);
        $this->assertSame(['2026-09-01', '2026-09-07', '2026-09-07'], array_values($r['period']['comparison']));

        $r = $this->report(9, 2026);
        $this->assertSame(['2026-09-01', '2026-09-30', false], [$r['period']['start'], $r['period']['end'], $r['period']['is_mtd']]);
        $this->assertSame(['2026-08-01', '2026-08-31', '2026-08-31'], array_values($r['period']['comparison']));

        $r = $this->report(3, 2026, '2026-03-31');   // 31 elapsed days vs a 28-day February
        $this->assertSame('2026-02-28', $r['period']['comparison']['end']);
    }

    public function test_mtd_flow_compares_the_same_elapsed_days_of_previous_month(): void
    {
        $c1 = $this->contract($this->client(), $this->cat1);
        $c2 = $this->contract($this->client(), $this->cat1);
        $this->disburse($c1, 100000, '2026-09-05', $this->cat1);   // inside Sep 1-7
        $this->disburse($c2, 900000, '2026-09-20', $this->cat1);   // outside Sep 1-7
        $this->disburse($this->contract($this->client(), $this->cat1), 200000, '2026-10-03', $this->cat1);
        $t = $this->report()['activity']['total_disbursement'];
        $this->assertSame(200000.0, $t['current']);
        $this->assertSame(100000.0, $t['previous']);
        $this->assertSame(100.0, $t['change_percent']);
    }

    public function test_legacy_hard_coded_amounts_do_not_exist_in_the_new_code(): void
    {
        $dir = base_path('app/Services/Reports/CreditActivityReportService.php');
        foreach ([$dir, base_path('app/Http/Controllers/CreditActivityReportController.php')] as $file) {
            $src = file_get_contents($file);
            foreach (['21880000', '21,880,000', '21_880_000', '8970000', '8,970,000', '8_970_000'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "$needle found in $file");
            }
        }
        $this->assertSame(0.0, $this->report()['position']['estimated_collateral']['current'], 'nothing is added to an empty pawnshop');
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/reports/credit-activity/10/2026')->assertStatus(401);
    }
}
