<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Reports\CreditActivityReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\LoanApplicationWorld;
use Tests\TestCase;

/** /reports access (view_cashbox_summary) and the gold LTV quality flag. */
class CreditActivityAccessTest extends TestCase
{
    use DatabaseTransactions, LoanApplicationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLoanApplicationWorld();
    }

    public function test_summary_and_legacy_endpoints_require_view_cashbox_summary(): void
    {
        $without = $this->userWith(['view_contracts']);
        $with = $this->userWith(['view_cashbox_summary']);

        foreach (['/api/reports/credit-activity/9/2026', '/api/reports/financial-indicators/9/2026'] as $url) {
            $this->getJson($url)->assertStatus(401);
            $this->getJson($url, $this->authHeaders($without))->assertStatus(403);
            $this->getJson($url, $this->authHeaders($with))->assertOk();
        }
    }

    public function test_only_the_gold_category_carries_the_ltv_quality_warning(): void
    {
        $ps = DB::table('pawnshops')->insertGetId([]);
        $gold = Category::firstOrCreate(['name' => 'gold'], ['title' => 'Gold'])->id;
        $car = DB::table('categories')->insertGetId(['name' => 'qa-car', 'title' => 'QA car']);
        foreach ([$gold, $car] as $cat) {
            $c = DB::table('contracts')->insertGetId(['client_id' => DB::table('clients')->insertGetId([]), 'category_id' => $cat,
                'pawnshop_id' => $ps, 'estimated_amount' => 0, 'provided_amount' => 0, 'deadline' => '2027-01-01']);
            foreach (['estimated_amount', 'provided_amount'] as $t) {
                DB::table('contract_amount_histories')->insert(['contract_id' => $c, 'amount_type' => $t, 'type' => 'in', 'amount' => 100000,
                    'date' => '2026-09-05', 'category_id' => $cat, 'pawnshop_id' => $ps, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $perf = collect((new CreditActivityReportService(Carbon::parse('2026-10-07')))->build($ps, 9, 2026)['collateral_performance']);
        $g = $perf->firstWhere('category_id', $gold);
        $this->assertTrue($g['ltv_quality_warning']);
        $this->assertSame(100.0, $g['median_origination_ltv'], 'raw gold LTV is kept, not hidden');
        $this->assertFalse($perf->firstWhere('category_id', $car)['ltv_quality_warning']);
    }
}
