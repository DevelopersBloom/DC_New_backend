<?php

namespace App\Services\Reports;

use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Ֆինանսական ցուցանիշներ" tab of /reports: one row per calendar day, POSITION (stock, as of the day) separated from
 * ACTIVITY (flow, during the day). It replaces the legacy per-day cumulative query loop and its hard-coded amounts.
 *
 * ONE calculation per concept: every figure is built from the same primitives as the management summary
 * (CreditActivityReportService): stockByCategory / dealBalances for the opening position, disbursementEvents + flow()
 * for the logical disbursements. Daily rows add only the day's deltas to that opening position, so the closing row is
 * the month-end position by construction; reconciliation() proves it against the independent primitives.
 *
 * Query budget (independent of the number of days): opening stock, opening balances, history deltas, disbursement
 * events, deal deltas, history drill-down, reconciliation (stock, balances, breakdown, principal, data quality).
 * Rounding is a presentation concern: values are rounded to 2 decimals only when emitted.
 */
class FinancialIndicatorsService
{
    private const TOLERANCE = 0.005;

    public function __construct(private CreditActivityReportService $base)
    {
    }

    public function build(int $pawnshopId, int $month, int $year): array
    {
        $p = $this->base->resolvePeriods($month, $year);
        $start = $p['start']->toDateString();
        $end = $p['end']->toDateString();
        $dayBefore = $p['start']->copy()->subDay()->toDateString();
        $titles = Category::query()->pluck('title', 'id')->all();

        // ---- opening position (as of the day before the month) -- the same primitives as the management summary
        $openStock = $this->base->stockByCategory($pawnshopId, $dayBefore, $dayBefore);
        $openBal = $this->base->dealBalances($pawnshopId, $dayBefore, $dayBefore);

        $estimated = $principal = [];
        foreach ($openStock as $cid => $s) {
            $estimated[$cid] = $s['est_cur'];
            $principal[$cid] = $s['prov_cur'];
        }
        $cash = $openBal['cash']['cur'];
        $bank = $openBal['bank']['cur'];

        // ---- daily deltas
        $hist = $this->historyDeltas($pawnshopId, $start, $end);
        $dealRows = $this->dealDeltas($pawnshopId, $start, $end);
        $events = $this->base->disbursementEvents($pawnshopId, $start, $end);
        $dayEvents = [];
        foreach ($events as $e) {
            if ($e['date'] < $start || $e['date'] > $end) {
                continue;
            }
            $c = $e['category_id'] ?? 'null';
            $k = $e['is_first'] ? 'new' : 'topup';
            $dayEvents[$e['date']][$c][$k] = ($dayEvents[$e['date']][$c][$k] ?? 0) + $e['amount'];
            $dayEvents[$e['date']][$c][$k . '_count'] = ($dayEvents[$e['date']][$c][$k . '_count'] ?? 0) + 1;
        }
        $drill = $this->historyEntries($pawnshopId, $start, $end);

        $categoryIds = array_keys($titles);
        $rows = [];
        $totals = ['new' => 0.0, 'topup' => 0.0, 'principal_reduction' => 0.0, 'receipts' => 0.0, 'cash_net' => 0.0, 'bank_net' => 0.0];
        $seenCats = array_fill_keys(array_map('strval', $categoryIds), true);

        for ($d = $p['start']->copy(); $d->lte($p['end']); $d->addDay()) {
            $date = $d->toDateString();

            foreach ($hist[$date] ?? [] as $cid => $h) {
                $estimated[$cid] = ($estimated[$cid] ?? 0.0) + $h['est_net'];
                $principal[$cid] = ($principal[$cid] ?? 0.0) + $h['prov_net'];
            }
            $day = $dealRows[$date] ?? ['cash_net' => 0.0, 'bank_net' => 0.0, 'receipts' => 0.0];
            $cash += $day['cash_net'];
            $bank += $day['bank_net'];

            $disbCats = [];
            $new = $top = 0.0;
            $newCount = $topCount = 0;
            foreach ($dayEvents[$date] ?? [] as $cid => $v) {
                $n = $v['new'] ?? 0.0;
                $t = $v['topup'] ?? 0.0;
                $new += $n;
                $top += $t;
                $newCount += $v['new_count'] ?? 0;
                $topCount += $v['topup_count'] ?? 0;
                $disbCats[$cid] = ['new' => $n, 'topup' => $t, 'total' => $n + $t];
            }
            $reduction = array_sum(array_column($hist[$date] ?? [], 'prov_out'));

            $est = array_sum($estimated);
            $prin = array_sum($principal);
            $byCat = [];
            foreach (array_unique(array_merge(array_keys($estimated), array_keys($principal), array_keys($disbCats))) as $cid) {
                $seenCats[(string) $cid] = true;
                $byCat[$cid] = [
                    'estimated_collateral' => round($estimated[$cid] ?? 0.0, 2),
                    'outstanding_principal' => round($principal[$cid] ?? 0.0, 2),
                    'new_disbursement' => round($disbCats[$cid]['new'] ?? 0.0, 2),
                    'top_up_disbursement' => round($disbCats[$cid]['topup'] ?? 0.0, 2),
                    'total_disbursement' => round($disbCats[$cid]['total'] ?? 0.0, 2),
                ];
            }

            $totals['new'] += $new;
            $totals['topup'] += $top;
            $totals['principal_reduction'] += $reduction;
            $totals['receipts'] += $day['receipts'];
            $totals['cash_net'] += $day['cash_net'];
            $totals['bank_net'] += $day['bank_net'];

            $rows[] = [
                'date' => $date,
                'position' => [
                    'estimated_collateral' => round($est, 2),
                    'outstanding_principal' => round($prin, 2),
                    'loan_collateral_ratio' => $this->base->ratio($prin, $est),
                    'cash_balance' => round($cash, 2),
                    'bank_balance' => round($bank, 2),
                ],
                'activity' => [
                    'new_disbursement' => round($new, 2),
                    'top_up_disbursement' => round($top, 2),
                    'total_disbursement' => round($new + $top, 2),
                    'new_loan_count' => $newCount,
                    'top_up_events' => $topCount,
                    'principal_reduction' => round($reduction, 2),
                    'receipts' => round($day['receipts'], 2),
                    'cash_net_movement' => round($day['cash_net'], 2),
                    'bank_net_movement' => round($day['bank_net'], 2),
                ],
                'by_category' => $byCat,
                'contract_amount_histories' => $drill[$date] ?? [],
            ];
        }

        $last = $rows[count($rows) - 1];
        $legend = [];
        foreach ($titles as $id => $title) {
            $legend[] = ['key' => (string) $id, 'category_id' => (int) $id, 'title' => $title];
        }
        if (isset($seenCats['null'])) {
            $legend[] = ['key' => 'null', 'category_id' => null, 'title' => 'Առանց տեսակի'];
        }

        $opening = [
            'estimated_collateral' => round(array_sum(array_column($openStock, 'est_cur')), 2),
            'outstanding_principal' => round(array_sum(array_column($openStock, 'prov_cur')), 2),
            'cash_balance' => round($openBal['cash']['cur'], 2),
            'bank_balance' => round($openBal['bank']['cur'], 2),
        ];

        return [
            'period' => ['start' => $start, 'end' => $end, 'opening_as_of' => $dayBefore, 'is_mtd' => $p['is_mtd']],
            'categories' => $legend,
            'definitions' => ReportKpiRegistry::FINANCIAL,
            'rows' => $rows,
            'summary' => [
                'opening' => $opening,
                'closing' => $last['position'],
                'activity' => [
                    'new_disbursement' => round($totals['new'], 2),
                    'top_up_disbursement' => round($totals['topup'], 2),
                    'total_disbursement' => round($totals['new'] + $totals['topup'], 2),
                    'principal_reduction' => round($totals['principal_reduction'], 2),
                    'receipts' => round($totals['receipts'], 2),
                    'cash_net_movement' => round($totals['cash_net'], 2),
                    'bank_net_movement' => round($totals['bank_net'], 2),
                ],
            ],
            'reconciliation' => $this->reconciliation($pawnshopId, $rows, $events, $start, $end),
        ];
    }

    // ------------------------------------------------------------------ queries

    /**
     * History deltas per day and category (1 query): [date => [category|'null' => est_net, prov_net, prov_out]].
     * Soft-deleted rows and other pawnshops are excluded, exactly as in the management summary.
     */
    private function historyDeltas(int $pawnshopId, string $start, string $end): array
    {
        $signed = "CASE h.type WHEN 'in' THEN h.amount WHEN 'out' THEN -h.amount ELSE 0 END";
        $rows = DB::select("
            SELECT h.date AS d, h.category_id,
              SUM(CASE WHEN h.amount_type='estimated_amount' THEN $signed ELSE 0 END) AS est_net,
              SUM(CASE WHEN h.amount_type='provided_amount' THEN $signed ELSE 0 END) AS prov_net,
              SUM(CASE WHEN h.amount_type='provided_amount' AND h.type='out' THEN h.amount ELSE 0 END) AS prov_out
            FROM contract_amount_histories h
            WHERE h.pawnshop_id = :pid AND h.deleted_at IS NULL
              AND h.amount_type IN ('estimated_amount','provided_amount') AND h.date BETWEEN :s AND :e
            GROUP BY h.date, h.category_id
        ", ['pid' => $pawnshopId, 's' => $start, 'e' => $end]);

        $out = [];
        foreach ($rows as $r) {
            $out[substr($r->d, 0, 10)][$r->category_id ?? 'null'] = [
                'est_net' => (float) $r->est_net, 'prov_net' => (float) $r->prov_net, 'prov_out' => (float) $r->prov_out,
            ];
        }
        return $out;
    }

    /**
     * Operational cash/bank movement and customer receipts per day (1 query, grouped by type/filter_type so the
     * classification stays auditable). Same deal filter as CreditActivityReportService::dealBalances.
     */
    private function dealDeltas(int $pawnshopId, string $start, string $end): array
    {
        $valid = CreditActivityReportService::VALID_DEAL_DATE;
        $types = array_merge(CreditActivityReportService::DEAL_IN, CreditActivityReportService::DEAL_OUT);
        $rows = DB::select("
            SELECT d.date AS d, d.cash, d.type, d.filter_type, SUM(d.amount) AS amt
            FROM deals d
            WHERE d.pawnshop_id = :pid AND d.deleted_at IS NULL AND $valid
              AND d.type IN ('" . implode("','", $types) . "') AND d.date BETWEEN :s AND :e
            GROUP BY d.date, d.cash, d.type, d.filter_type
        ", ['pid' => $pawnshopId, 's' => $start, 'e' => $end]);

        $out = [];
        foreach ($rows as $r) {
            $day = &$out[$r->d];
            $day ??= ['cash_net' => 0.0, 'bank_net' => 0.0, 'receipts' => 0.0];
            $signed = in_array($r->type, CreditActivityReportService::DEAL_IN, true) ? (float) $r->amt : -(float) $r->amt;
            $day[(int) $r->cash === 1 ? 'cash_net' : 'bank_net'] += $signed;
            if ($r->type === 'in' && in_array($r->filter_type, ['payment', 'full_payment'], true)) {
                $day['receipts'] += (float) $r->amt;
            }
            unset($day);
        }
        return $out;
    }

    /** The history rows behind every day (drill-down, editable in the UI): [date => list]. 1 query. */
    private function historyEntries(int $pawnshopId, string $start, string $end): array
    {
        $rows = DB::select("
            SELECT h.id, h.amount, h.type, h.amount_type, h.contract_id, c.num AS contract_num, h.deal_id, h.date, h.category_id
            FROM contract_amount_histories h LEFT JOIN contracts c ON c.id = h.contract_id
            WHERE h.pawnshop_id = :pid AND h.deleted_at IS NULL
              AND h.amount_type IN ('estimated_amount','provided_amount') AND h.date BETWEEN :s AND :e
            ORDER BY h.id
        ", ['pid' => $pawnshopId, 's' => $start, 'e' => $end]);

        $out = [];
        foreach ($rows as $r) {
            $amount = (float) $r->amount;
            $out[substr($r->date, 0, 10)][] = [
                'id' => (int) $r->id, 'amount' => $amount, 'type' => $r->type, 'amount_type' => $r->amount_type,
                'signed_amount' => $r->type === 'in' ? $amount : -$amount,
                'contract_id' => $r->contract_id === null ? null : (int) $r->contract_id, 'contract_num' => $r->contract_num,
                'deal_id' => $r->deal_id === null ? null : (int) $r->deal_id,
                'category_id' => $r->category_id === null ? null : (int) $r->category_id,
                'date' => substr($r->date, 0, 10),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ reconciliation

    /**
     * Internal proof, recomputed through the independent primitives of the management summary:
     *  - closing row vs stockByCategory / dealBalances at the end date;
     *  - sum of daily disbursement vs CreditActivityReportService::flow over the period;
     *  - category sums vs totals (a NULL category is its own bucket, never dropped);
     *  - cash/bank transaction breakdown (included vs excluded by deals.type/filter_type);
     *  - history outstanding vs contracts.provided_amount (diagnostic only, never used as the source);
     *  - data-quality counters.
     * Nothing here changes a reported number; differences are reported, never "fixed".
     */
    private function reconciliation(int $pawnshopId, array $rows, array $events, string $start, string $end): array
    {
        $closing = $rows[count($rows) - 1];
        $stock = $this->base->stockByCategory($pawnshopId, $end, $end);
        $bal = $this->base->dealBalances($pawnshopId, $end, $end);
        $flow = $this->base->flow($events, $start, $end);

        $est = array_sum(array_column($stock, 'est_cur'));
        $prin = array_sum(array_column($stock, 'prov_cur'));
        $dayDisb = array_sum(array_column(array_column($rows, 'activity'), 'total_disbursement'));
        $dayNew = array_sum(array_column(array_column($rows, 'activity'), 'new_disbursement'));
        $dayTop = array_sum(array_column(array_column($rows, 'activity'), 'top_up_disbursement'));
        $catEst = array_sum(array_column($closing['by_category'], 'estimated_collateral'));
        $catPrin = array_sum(array_column($closing['by_category'], 'outstanding_principal'));
        $catDisb = 0.0;
        foreach ($rows as $r) {
            $catDisb += array_sum(array_column($r['by_category'], 'total_disbursement'));
        }

        $checks = [];
        $add = function (string $key, float $a, float $b) use (&$checks) {
            // Rounded rows accumulate up to 0.005 per day/category; allow a proportional tolerance.
            $diff = round($a - $b, 2);
            $checks[$key] = ['table' => round($a, 2), 'reference' => round($b, 2), 'difference' => $diff, 'ok' => abs($diff) <= 0.05];
        };
        $add('closing_estimated_collateral', $closing['position']['estimated_collateral'], $est);
        $add('closing_outstanding_principal', $closing['position']['outstanding_principal'], $prin);
        $add('closing_cash_balance', $closing['position']['cash_balance'], $bal['cash']['cur']);
        $add('closing_bank_balance', $closing['position']['bank_balance'], $bal['bank']['cur']);
        $add('period_total_disbursement', $dayDisb, $flow['total']);
        $add('period_new_disbursement', $dayNew, $flow['new_amount']);
        $add('period_top_up_disbursement', $dayTop, $flow['topup_amount']);
        $add('category_sum_estimated_collateral', $catEst, $closing['position']['estimated_collateral']);
        $add('category_sum_outstanding_principal', $catPrin, $closing['position']['outstanding_principal']);
        $add('category_sum_total_disbursement', $catDisb, $dayDisb);

        return [
            'ok' => !in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
            'cash_bank_breakdown' => $this->cashBankBreakdown($pawnshopId, $end),
            'principal' => $this->principalReconciliation($pawnshopId, $end),
            'data_quality' => $this->dataQuality($pawnshopId, $end, $bal['malformed']),
        ];
    }

    /**
     * contracts.provided_amount is TODAY's state, so it is only comparable with the history net as of today (or the
     * period end for the current month). For a past month the comparison is made as of today and says so.
     */
    private function principalReconciliation(int $pawnshopId, string $end): array
    {
        $asOf = max($end, $this->base->today()->toDateString());

        return $this->base->principalReconciliation($pawnshopId, $asOf) + [
            'compared_as_of' => $asOf,
            'note' => 'contracts.provided_amount is current state: compared with the history net as of today; never used as a source.',
        ];
    }

    /**
     * Every deals.type/filter_type combination with its signed contribution to the cash and bank balance as of $end,
     * plus the deals excluded from both (types outside in/out/expense/cost_out). Lets a reviewer re-add the balance.
     */
    public function cashBankBreakdown(int $pawnshopId, string $asOf): array
    {
        $valid = CreditActivityReportService::VALID_DEAL_DATE;
        $rows = DB::select("
            SELECT d.cash, d.type, d.filter_type, COUNT(*) AS n, SUM(d.amount) AS amt,
                   d.type IN ('" . implode("','", array_merge(CreditActivityReportService::DEAL_IN, CreditActivityReportService::DEAL_OUT)) . "') AS included
            FROM deals d
            WHERE d.pawnshop_id = :pid AND d.deleted_at IS NULL AND $valid AND d.date <= :asof
            GROUP BY d.cash, d.type, d.filter_type
            ORDER BY d.cash DESC, d.type, d.filter_type
        ", ['pid' => $pawnshopId, 'asof' => $asOf]);

        $included = $excluded = [];
        foreach ($rows as $r) {
            $isIn = in_array($r->type, CreditActivityReportService::DEAL_IN, true);
            $line = [
                'channel' => (int) $r->cash === 1 ? 'cash' : 'bank', 'type' => $r->type, 'filter_type' => $r->filter_type,
                'count' => (int) $r->n, 'amount' => round((float) $r->amt, 2),
                'signed_amount' => round($isIn ? (float) $r->amt : -(float) $r->amt, 2),
            ];
            if ((int) $r->included === 1) {
                $included[] = $line;
            } else {
                $excluded[] = $line;
            }
        }
        $sum = fn (string $ch) => round(array_sum(array_column(array_filter($included, fn ($l) => $l['channel'] === $ch), 'signed_amount')), 2);

        return ['included' => $included, 'excluded' => $excluded, 'cash_total' => $sum('cash'), 'bank_total' => $sum('bank')];
    }

    private function dataQuality(int $pawnshopId, string $asOf, int $malformedDealDates): array
    {
        $r = DB::selectOne("
            SELECT
              (SELECT COUNT(*) FROM contract_amount_histories h JOIN deals d ON d.id = h.deal_id
                WHERE h.pawnshop_id = :p1 AND h.deleted_at IS NULL AND d.deleted_at IS NOT NULL AND h.date <= :a1) AS history_on_deleted_deals,
              (SELECT COUNT(*) FROM contract_amount_histories h
                WHERE h.pawnshop_id = :p2 AND h.deleted_at IS NULL AND h.category_id IS NULL AND h.date <= :a2) AS history_without_category,
              (SELECT COUNT(*) FROM contract_amount_histories h JOIN contracts c ON c.id = h.contract_id
                WHERE h.pawnshop_id = :p3 AND h.deleted_at IS NULL AND h.category_id <> c.category_id AND h.date <= :a3) AS history_category_conflicts
        ", ['p1' => $pawnshopId, 'a1' => $asOf, 'p2' => $pawnshopId, 'a2' => $asOf, 'p3' => $pawnshopId, 'a3' => $asOf]);

        return [
            'malformed_deal_dates' => $malformedDealDates,
            'history_on_deleted_deals' => (int) $r->history_on_deleted_deals,
            'history_without_category' => (int) $r->history_without_category,
            'history_category_conflicts' => (int) $r->history_category_conflicts,
        ];
    }
}
