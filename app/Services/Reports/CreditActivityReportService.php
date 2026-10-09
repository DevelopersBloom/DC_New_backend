<?php

namespace App\Services\Reports;

use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Management summary for the /reports page (Tranche 1): POSITION (stock, "as of" a date) versus
 * ACTIVITY (flow, over a period).
 *
 * Sources
 *  - contract_amount_histories: estimated collateral, outstanding principal, first disbursement, top-ups.
 *  - deals: physical cash / bank balances and the disbursement method (operational source, by decision).
 *
 * Nothing here uses manual constants; every number is derived from stored records.
 * Fixed number of queries regardless of the period length or the number of categories.
 */
class CreditActivityReportService
{
    /** Deal types that move the operational cash/bank balance: 'in' adds, the rest subtract. */
    public const DEAL_IN = ['in'];
    public const DEAL_OUT = ['out', 'expense', 'cost_out'];

    /** A contract-disbursement deal (see ContractTrait::createOrderHistoryEntry: type out, filter_type contract). */
    private const DISBURSEMENT_DEAL_TYPE = 'out';
    private const DISBURSEMENT_DEAL_FILTER = 'contract';

    public const VALID_DEAL_DATE = "d.date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'";

    public function __construct(private ?Carbon $today = null)
    {
        $this->today = ($today ?? Carbon::now('Asia/Yerevan'))->copy()->startOfDay();
    }

    public function today(): Carbon
    {
        return $this->today->copy();
    }

    // ------------------------------------------------------------------ periods

    public function resolvePeriods(int $month, int $year): array
    {
        $start = Carbon::create($year, $month, 1, 0, 0, 0, 'Asia/Yerevan')->startOfDay();
        $monthEnd = $start->copy()->endOfMonth()->startOfDay();
        $isMtd = $this->today->between($start, $monthEnd);
        $end = $isMtd ? $this->today->copy() : $monthEnd;

        $prevStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
        $prevMonthEnd = $prevStart->copy()->endOfMonth()->startOfDay();
        if ($isMtd) {
            // Same number of elapsed calendar days (clamped for 31 -> 30/28 day months).
            $prevEnd = $prevStart->copy()->addDays($end->day - 1);
            if ($prevEnd->gt($prevMonthEnd)) {
                $prevEnd = $prevMonthEnd;
            }
        } else {
            $prevEnd = $prevMonthEnd;
        }

        return ['start' => $start, 'end' => $end, 'prev_start' => $prevStart, 'prev_end' => $prevEnd, 'is_mtd' => $isMtd];
    }

    // ------------------------------------------------------------------ main entry

    public function build(int $pawnshopId, int $month, int $year, int $trendMonths = 6): array
    {
        $trendMonths = $trendMonths === 12 ? 12 : 6;
        $p = $this->resolvePeriods($month, $year);
        $cur = $p['end']->toDateString();
        $prev = $p['prev_end']->toDateString();
        $start = $p['start']->toDateString();
        $prevStart = $p['prev_start']->toDateString();

        $stock = $this->stockByCategory($pawnshopId, $cur, $prev);
        $balances = $this->dealBalances($pawnshopId, $cur, $prev);
        $monthlyStock = $this->monthlyStock($pawnshopId, $cur);
        $trendStart = $p['start']->copy()->subMonthsNoOverflow($trendMonths - 1);
        // Whole history up to the selected date: every consumer filters by its own dates; the lifetime view
        // (repeated top-ups, customer frequency, active contracts) needs the early rows too.
        $events = $this->disbursementEvents($pawnshopId, '1900-01-01', $cur);
        $titles = Category::query()->pluck('title', 'id')->all();
        $deals = $this->disbursementDeals($pawnshopId, $prevStart, $prev, $start, $cur);

        $flowCur = $this->flow($events, $start, $cur);
        $flowPrev = $this->flow($events, $prevStart, $prev);

        $estCur = array_sum(array_column($stock, 'est_cur'));
        $estPrev = array_sum(array_column($stock, 'est_prev'));
        $prinCur = array_sum(array_column($stock, 'prov_cur'));
        $prinPrev = array_sum(array_column($stock, 'prov_prev'));

        $ratioCur = $this->ratio($prinCur, $estCur);
        $ratioPrev = $this->ratio($prinPrev, $estPrev);

        $cash = $balances['cash'];
        $bank = $balances['bank'];

        $historyTotal = $flowCur['total'];
        $dealTotal = $deals['cur']['cash']['amount'] + $deals['cur']['non_cash']['amount'];

        $categories = $this->categoryRows($stock, $flowCur, $flowPrev, $estCur, $titles);
        $ltvCur = $this->ltv($events, $start, $cur);
        $ltvPrev = $this->ltv($events, $prevStart, $prev);

        $result = [
            'period' => [
                'start' => $start,
                'end' => $cur,
                'as_of' => $cur,
                'is_mtd' => $p['is_mtd'],
                'comparison' => ['start' => $prevStart, 'end' => $prev, 'as_of' => $prev],
            ],
            'position' => [
                'estimated_collateral' => $this->change($estCur, $estPrev),
                'outstanding_principal' => $this->change($prinCur, $prinPrev),
                'loan_collateral_ratio' => $this->changePp($ratioCur, $ratioPrev),
                'cash_balance' => $this->change($cash['cur'], $cash['prev']),
                'bank_balance' => $this->change($bank['cur'], $bank['prev']),
                // Optional headline: not shown by the UI. Bank can be negative when loans are paid
                // from the bank before the lender funds are booked, so the sum needs that context.
                'total_liquid_funds' => $this->change($cash['cur'] + $bank['cur'], $cash['prev'] + $bank['prev']),
            ],
            'activity' => [
                'total_disbursement' => $this->change($flowCur['total'], $flowPrev['total']),
                'new_loan_disbursement' => $this->change($flowCur['new_amount'], $flowPrev['new_amount']),
                'top_up_disbursement' => $this->change($flowCur['topup_amount'], $flowPrev['topup_amount']),
                'new_loan_count' => $this->change($flowCur['new_count'], $flowPrev['new_count']),
                'unique_borrowers' => $this->change($flowCur['borrowers'], $flowPrev['borrowers']),
                'average_new_loan' => $this->change($flowCur['average'], $flowPrev['average']),
                'median_new_loan' => $this->change($flowCur['median'], $flowPrev['median']),
                'top_up_events' => $this->change($flowCur['topup_events'], $flowPrev['topup_events']),
                'top_up_contracts' => $this->change($flowCur['topup_contracts'], $flowPrev['topup_contracts']),
                'new_disbursement_share_percent' => $this->pct($flowCur['new_amount'], $flowCur['total']),
                'top_up_share_percent' => $this->pct($flowCur['topup_amount'], $flowCur['total']),
                'new_borrowers' => $flowCur['new_borrowers'],
                'repeat_borrowers' => $flowCur['repeat_borrowers'],
                'new_borrower_share_percent' => $this->pct($flowCur['new_borrowers'], $flowCur['new_borrowers'] + $flowCur['repeat_borrowers']),
                'repeat_borrower_share_percent' => $this->pct($flowCur['repeat_borrowers'], $flowCur['new_borrowers'] + $flowCur['repeat_borrowers']),
            ],
            'disbursement_method' => [
                'cash' => $deals['cur']['cash'] + ['share_percent' => $this->pct($deals['cur']['cash']['amount'], $dealTotal)],
                'non_cash' => $deals['cur']['non_cash'] + ['share_percent' => $this->pct($deals['cur']['non_cash']['amount'], $dealTotal)],
                'total' => round($dealTotal, 2),
            ],
            'collateral_categories' => $categories,
            'trend' => $this->trend($events, $monthlyStock, $p, $trendMonths, $titles),
            'drivers' => $this->drivers($flowCur, $flowPrev),
            'collateral_performance' => $this->collateralPerformance($stock, $flowCur, $flowPrev, $ltvCur, $estCur, $titles),
            'origination_ltv' => $this->ltvBlock($ltvCur, $ltvPrev),
            'borrower_mix' => $this->borrowerMix($flowCur, $flowPrev),
            'reconciliation' => [
                'estimated_total' => round($estCur, 2),
                'estimated_category_sum' => round(array_sum(array_column($categories, 'estimated_collateral')), 2),
                'estimated_difference' => round($estCur - array_sum(array_column($categories, 'estimated_collateral')), 2),
                'category_disbursement_sum' => round(array_sum(array_column($categories, 'period_disbursement')), 2),
                'category_disbursement_difference' => round($historyTotal - array_sum(array_column($categories, 'period_disbursement')), 2),
                'history_disbursement' => round($historyTotal, 2),
                'history_disbursement_events' => $flowCur['new_count'] + $flowCur['topup_events'],
                'deal_disbursement' => round($dealTotal, 2),
                'deal_disbursement_count' => $deals['cur']['cash']['count'] + $deals['cur']['non_cash']['count'],
                'disbursement_difference' => round($historyTotal - $dealTotal, 2),
                'disbursement_count_difference' => ($flowCur['new_count'] + $flowCur['topup_events'])
                    - ($deals['cur']['cash']['count'] + $deals['cur']['non_cash']['count']),
                'principal' => $this->principalReconciliation($pawnshopId, $cur),
                'malformed_deal_dates' => $balances['malformed'],
            ],
        ];
        $result['highlights'] = $this->highlights($result);

        // Tranche 3: active portfolio management, as of the selected end date.
        $pm = (new PortfolioManagementService($this))->build(
            $pawnshopId,
            ['start' => $start, 'end' => $cur, 'as_of' => $cur],
            $events,
            array_map(fn ($m) => ['month' => $m['month'], 'start' => $m['start'], 'end' => $m['end']], $result['trend']['months']),
            $titles
        );
        $result['highlights'] = array_merge($result['highlights'], $pm['portfolio_highlights']);
        unset($pm['portfolio_highlights']);

        return $result + $pm;
    }

    /**
     * Drill-down behind a Tranche 3 headline number (see PortfolioManagementService::DETAIL_TYPES).
     * Same period/as-of resolution as build(); pawnshop comes from the caller, never from the request.
     */
    public function details(int $pawnshopId, int $month, int $year, string $type, array $opts = []): array
    {
        $p = $this->resolvePeriods($month, $year);
        $cur = $p['end']->toDateString();
        $events = $this->disbursementEvents($pawnshopId, '1900-01-01', $cur);
        $titles = Category::query()->pluck('title', 'id')->all();

        return (new PortfolioManagementService($this))->details(
            $pawnshopId,
            ['start' => $p['start']->toDateString(), 'end' => $cur, 'as_of' => $cur],
            $events, $titles, $type, $opts
        );
    }

    // ------------------------------------------------------------------ queries

    /**
     * STOCK, one query: signed (in - out) sums of estimated collateral and outstanding principal as of two
     * dates, grouped by category. Returns [category_id|'null' => [est_cur, est_prev, prov_cur, prov_prev]].
     */
    public function stockByCategory(int $pawnshopId, string $cur, string $prev): array
    {
        $signed = "CASE h.type WHEN 'in' THEN h.amount WHEN 'out' THEN -h.amount ELSE 0 END";
        $rows = DB::select("
            SELECT h.category_id,
              SUM(CASE WHEN h.amount_type='estimated_amount' THEN $signed ELSE 0 END) AS est_cur,
              SUM(CASE WHEN h.amount_type='estimated_amount' AND h.date <= :p1 THEN $signed ELSE 0 END) AS est_prev,
              SUM(CASE WHEN h.amount_type='provided_amount' THEN $signed ELSE 0 END) AS prov_cur,
              SUM(CASE WHEN h.amount_type='provided_amount' AND h.date <= :p2 THEN $signed ELSE 0 END) AS prov_prev
            FROM contract_amount_histories h
            WHERE h.pawnshop_id = :pid AND h.deleted_at IS NULL
              AND h.amount_type IN ('estimated_amount','provided_amount') AND h.date <= :cur
            GROUP BY h.category_id
        ", ['p1' => $prev, 'p2' => $prev, 'pid' => $pawnshopId, 'cur' => $cur]);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->category_id ?? 'null'] = [
                'est_cur' => (float) $r->est_cur, 'est_prev' => (float) $r->est_prev,
                'prov_cur' => (float) $r->prov_cur, 'prov_prev' => (float) $r->prov_prev,
            ];
        }
        return $out;
    }

    /** Operational cash (deals.cash=1) and bank (cash=0) balances as of two dates, one query. */
    public function dealBalances(int $pawnshopId, string $cur, string $prev): array
    {
        $valid = self::VALID_DEAL_DATE;
        $signed = "CASE WHEN d.type IN ('" . implode("','", self::DEAL_IN) . "') THEN d.amount ELSE -d.amount END";
        $rows = DB::select("
            SELECT d.cash,
              SUM(CASE WHEN $valid AND d.date <= :cur THEN $signed ELSE 0 END) AS bal_cur,
              SUM(CASE WHEN $valid AND d.date <= :prev THEN $signed ELSE 0 END) AS bal_prev,
              SUM(CASE WHEN d.date IS NULL OR NOT ($valid) THEN 1 ELSE 0 END) AS malformed
            FROM deals d
            WHERE d.pawnshop_id = :pid AND d.deleted_at IS NULL
              AND d.type IN ('" . implode("','", array_merge(self::DEAL_IN, self::DEAL_OUT)) . "')
            GROUP BY d.cash
        ", ['cur' => $cur, 'prev' => $prev, 'pid' => $pawnshopId]);

        $res = ['cash' => ['cur' => 0.0, 'prev' => 0.0], 'bank' => ['cur' => 0.0, 'prev' => 0.0], 'malformed' => 0];
        foreach ($rows as $r) {
            $key = (int) $r->cash === 1 ? 'cash' : 'bank';
            $res[$key]['cur'] += (float) $r->bal_cur;
            $res[$key]['prev'] += (float) $r->bal_prev;
            $res['malformed'] += (int) $r->malformed;
        }
        return $res;
    }

    /**
     * Disbursement events dated $from..$to, from provided_amount/'in' history rows.
     *  - first row of a contract (by date, id)          -> the first disbursement (new loan)
     *  - later rows with the SAME deal_id as that row   -> still the first disbursement: one deal stored as
     *    several history rows (e.g. contract A-26-00034: deal 70 = 472,500 + 2,027,500). Their amount is
     *    added to the first row and they are dated by it. They never create a second "loan" or a top-up.
     *  - any other later row                            -> top-up / additional disbursement
     * Ranking uses the contract's whole history, so a contract created earlier but first disbursed in the
     * period is a new loan, and a top-up never becomes one. client_first_date = date of the borrower's
     * first-ever disbursement (used for new vs repeat borrowers).
     */
    public function disbursementEvents(int $pawnshopId, string $from, string $to): array
    {
        $rows = DB::select("
            WITH ev AS (
              SELECT h.id, h.contract_id, c.client_id, h.category_id, h.amount, h.date, h.deal_id,
                     ROW_NUMBER() OVER w AS rn,
                     FIRST_VALUE(h.date) OVER w AS first_date,
                     FIRST_VALUE(h.deal_id) OVER w AS first_deal
              FROM contract_amount_histories h
              LEFT JOIN contracts c ON c.id = h.contract_id
              WHERE h.pawnshop_id = :pid AND h.deleted_at IS NULL
                AND h.amount_type = 'provided_amount' AND h.type = 'in' AND h.date <= :to
              WINDOW w AS (PARTITION BY h.contract_id ORDER BY h.date, h.id)
            ), ev2 AS (
              SELECT ev.*,
                     CASE WHEN rn = 1 THEN 'first'
                          WHEN deal_id IS NOT NULL AND deal_id = first_deal THEN 'cont'
                          ELSE 'topup' END AS kind,
                     MIN(CASE WHEN rn = 1 THEN date END) OVER (PARTITION BY client_id) AS client_first_date
              FROM ev
            )
            SELECT id, contract_id, client_id, category_id, amount, kind, client_first_date,
                   CASE WHEN kind = 'cont' THEN first_date ELSE date END AS eff_date,
                   CASE WHEN kind = 'first' THEN (
                       SELECT SUM(CASE e.type WHEN 'in' THEN e.amount WHEN 'out' THEN -e.amount ELSE 0 END)
                       FROM contract_amount_histories e
                       WHERE e.contract_id = ev2.contract_id AND e.pawnshop_id = :pid2 AND e.deleted_at IS NULL
                         AND e.amount_type = 'estimated_amount' AND e.date <= ev2.date) END AS est_at_orig,
                   CASE WHEN kind = 'first' THEN (
                       SELECT COUNT(*) FROM contract_amount_histories e
                       WHERE e.contract_id = ev2.contract_id AND e.pawnshop_id = :pid3 AND e.deleted_at IS NULL
                         AND e.amount_type = 'estimated_amount' AND e.date <= ev2.date) END AS est_rows
            FROM ev2
            WHERE (CASE WHEN kind = 'cont' THEN first_date ELSE date END) >= :from
            ORDER BY contract_id, id
        ", ['pid' => $pawnshopId, 'pid2' => $pawnshopId, 'pid3' => $pawnshopId, 'to' => $to, 'from' => $from]);

        $events = [];
        $firstIdx = [];
        foreach ($rows as $r) {
            if ($r->kind === 'cont' && isset($firstIdx[$r->contract_id])) {
                $events[$firstIdx[$r->contract_id]]['amount'] += (float) $r->amount;
                continue;
            }
            $events[] = [
                'id' => (int) $r->id, 'contract_id' => (int) $r->contract_id,
                'client_id' => $r->client_id === null ? null : (int) $r->client_id,
                'category_id' => $r->category_id === null ? null : (int) $r->category_id,
                'amount' => (float) $r->amount, 'date' => $r->eff_date, 'is_first' => $r->kind === 'first',
                'client_first_date' => $r->client_first_date,
                // Estimate in force on the logical first-disbursement date (net of in/out rows dated <= that day).
                // Same-day estimate rows are all included: the order inside one day is not recorded.
                'est_at_origination' => $r->est_at_orig === null ? null : (float) $r->est_at_orig,
                'est_rows' => $r->est_rows === null ? 0 : (int) $r->est_rows,
            ];
            if ($r->kind === 'first') {
                $firstIdx[$r->contract_id] = array_key_last($events);
            }
        }
        return $events;
    }

    /** Contract-disbursement deals split by cash flag for the current and the previous period, one query. */
    public function disbursementDeals(int $pawnshopId, string $prevStart, string $prevEnd, string $start, string $end): array
    {
        $valid = self::VALID_DEAL_DATE;
        $rows = DB::select("
            SELECT d.cash,
              SUM(CASE WHEN d.date BETWEEN :s1 AND :e1 THEN d.amount ELSE 0 END) AS cur_amount,
              SUM(CASE WHEN d.date BETWEEN :s2 AND :e2 THEN 1 ELSE 0 END) AS cur_count,
              SUM(CASE WHEN d.date BETWEEN :s3 AND :e3 THEN d.amount ELSE 0 END) AS prev_amount,
              SUM(CASE WHEN d.date BETWEEN :s4 AND :e4 THEN 1 ELSE 0 END) AS prev_count
            FROM deals d
            WHERE d.pawnshop_id = :pid AND d.deleted_at IS NULL
              AND d.type = :dt AND d.filter_type = :df AND $valid
              AND d.date BETWEEN :from AND :to
            GROUP BY d.cash
        ", [
            's1' => $start, 'e1' => $end, 's2' => $start, 'e2' => $end,
            's3' => $prevStart, 'e3' => $prevEnd, 's4' => $prevStart, 'e4' => $prevEnd,
            'pid' => $pawnshopId, 'dt' => self::DISBURSEMENT_DEAL_TYPE, 'df' => self::DISBURSEMENT_DEAL_FILTER,
            'from' => $prevStart, 'to' => $end,
        ]);

        $empty = fn () => ['amount' => 0.0, 'count' => 0];
        $res = ['cur' => ['cash' => $empty(), 'non_cash' => $empty()], 'prev' => ['cash' => $empty(), 'non_cash' => $empty()]];
        foreach ($rows as $r) {
            $k = (int) $r->cash === 1 ? 'cash' : 'non_cash';
            $res['cur'][$k] = ['amount' => (float) $r->cur_amount, 'count' => (int) $r->cur_count];
            $res['prev'][$k] = ['amount' => (float) $r->prev_amount, 'count' => (int) $r->prev_count];
        }
        return $res;
    }

    /** History-net outstanding principal vs contracts.provided_amount (diagnostic only, never altered). */
    public function principalReconciliation(int $pawnshopId, string $asOf, int $top = 5): array
    {
        $rows = DB::select("
            SELECT c.id, c.num, c.status, c.provided_amount AS contract_amount, COALESCE(x.net, 0) AS history_net
            FROM contracts c
            LEFT JOIN (
              SELECT contract_id, SUM(CASE type WHEN 'in' THEN amount WHEN 'out' THEN -amount ELSE 0 END) AS net
              FROM contract_amount_histories
              WHERE pawnshop_id = :pid1 AND deleted_at IS NULL AND amount_type = 'provided_amount' AND date <= :asof
              GROUP BY contract_id
            ) x ON x.contract_id = c.id
            WHERE c.pawnshop_id = :pid2 AND c.deleted_at IS NULL
        ", ['pid1' => $pawnshopId, 'asof' => $asOf, 'pid2' => $pawnshopId]);

        $mismatches = [];
        $histTotal = 0.0;
        $contractTotal = 0.0;
        foreach ($rows as $r) {
            $histTotal += (float) $r->history_net;
            $contractTotal += (float) $r->contract_amount;
            $diff = (float) $r->history_net - (float) $r->contract_amount;
            if (abs($diff) >= 1) {
                $mismatches[] = ['contract_id' => (int) $r->id, 'num' => $r->num, 'status' => $r->status,
                    'history_net' => round((float) $r->history_net, 2), 'contract_provided_amount' => round((float) $r->contract_amount, 2),
                    'difference' => round($diff, 2)];
            }
        }
        usort($mismatches, fn ($a, $b) => abs($b['difference']) <=> abs($a['difference']));

        return [
            'history_outstanding' => round($histTotal, 2),
            'contracts_provided_amount' => round($contractTotal, 2),
            'difference' => round($histTotal - $contractTotal, 2),
            'mismatched_contracts' => count($mismatches),
            'largest_mismatches' => array_slice($mismatches, 0, $top),
        ];
    }

    /**
     * Contract-level view of history vs deal disbursement in a period (diagnostic; used by validation,
     * not by the API response). Rows with a non-zero difference only.
     */
    public function disbursementMismatches(int $pawnshopId, string $start, string $end): array
    {
        $valid = self::VALID_DEAL_DATE;
        return DB::select("
            SELECT COALESCE(a.contract_id, b.contract_id) AS contract_id, c.num,
                   COALESCE(a.amount, 0) AS history_amount, COALESCE(a.n, 0) AS history_events,
                   COALESCE(b.amount, 0) AS deal_amount, COALESCE(b.n, 0) AS deal_events
            FROM (SELECT contract_id, SUM(amount) amount, COUNT(*) n FROM contract_amount_histories
                  WHERE pawnshop_id = :p1 AND deleted_at IS NULL AND amount_type='provided_amount' AND type='in'
                    AND date BETWEEN :s1 AND :e1 GROUP BY contract_id) a
            LEFT JOIN (SELECT d.contract_id, SUM(d.amount) amount, COUNT(*) n FROM deals d
                  WHERE d.pawnshop_id = :p2 AND d.deleted_at IS NULL AND d.type='out' AND d.filter_type='contract'
                    AND $valid AND d.date BETWEEN :s2 AND :e2 GROUP BY d.contract_id) b ON b.contract_id = a.contract_id
            LEFT JOIN contracts c ON c.id = a.contract_id
            WHERE ABS(COALESCE(a.amount,0) - COALESCE(b.amount,0)) >= 1 OR COALESCE(a.n,0) <> COALESCE(b.n,0)
            UNION
            SELECT b.contract_id, c.num, 0, 0, b.amount, b.n
            FROM (SELECT d.contract_id, SUM(d.amount) amount, COUNT(*) n FROM deals d
                  WHERE d.pawnshop_id = :p3 AND d.deleted_at IS NULL AND d.type='out' AND d.filter_type='contract'
                    AND $valid AND d.date BETWEEN :s3 AND :e3 GROUP BY d.contract_id) b
            LEFT JOIN contracts c ON c.id = b.contract_id
            WHERE b.contract_id NOT IN (SELECT contract_id FROM contract_amount_histories
                  WHERE pawnshop_id = :p4 AND deleted_at IS NULL AND amount_type='provided_amount' AND type='in'
                    AND date BETWEEN :s4 AND :e4)
        ", ['p1' => $pawnshopId, 's1' => $start, 'e1' => $end, 'p2' => $pawnshopId, 's2' => $start, 'e2' => $end,
            'p3' => $pawnshopId, 's3' => $start, 'e3' => $end, 'p4' => $pawnshopId, 's4' => $start, 'e4' => $end]);
    }

    // ------------------------------------------------------------------ flow calculations

    /**
     * Flow metrics for events dated $from..$to. Pure PHP over the (small) event list; the median is
     * computed here because MySQL/MariaDB have no portable MEDIAN and the cohort is a few dozen rows.
     */
    public function flow(array $events, string $from, string $to): array
    {
        $new = $top = [];
        foreach ($events as $e) {
            if ($e['date'] < $from || $e['date'] > $to) {
                continue;
            }
            $e['is_first'] ? $new[] = $e : $top[] = $e;
        }

        $newAmounts = array_column($new, 'amount');
        $newAmount = array_sum($newAmounts);
        $topAmount = array_sum(array_column($top, 'amount'));
        $borrowers = [];
        $newBorrowers = $repeatBorrowers = [];
        $byCat = [];
        $bySize = ['new' => [], 'repeat' => []];

        foreach ($new as $e) {
            if ($e['client_id'] !== null) {
                $borrowers[$e['client_id']] = true;
                if ($e['client_first_date'] >= $from) {
                    $newBorrowers[$e['client_id']] = true;
                } else {
                    $repeatBorrowers[$e['client_id']] = true;
                }
            }
            $c = $e['category_id'] ?? 'null';
            $isNewBorrower = $e['client_id'] !== null && $e['client_first_date'] >= $from;
            $byCat[$c]['new_amount'] = ($byCat[$c]['new_amount'] ?? 0) + $e['amount'];
            $byCat[$c]['new_count'] = ($byCat[$c]['new_count'] ?? 0) + 1;
            $byCat[$c]['new_amounts'][] = $e['amount'];
            $byCat[$c]['clients'][$e['client_id'] ?? 'x' . $e['contract_id']] = true;
            if ($e['client_id'] !== null) {
                $byCat[$c][$isNewBorrower ? 'new_clients' : 'repeat_clients'][$e['client_id']] = true;
            }
            $bySize[$isNewBorrower ? 'new' : 'repeat'][] = $e['amount'];
        }
        foreach ($top as $e) {
            $c = $e['category_id'] ?? 'null';
            $byCat[$c]['topup_amount'] = ($byCat[$c]['topup_amount'] ?? 0) + $e['amount'];
        }

        $count = count($new);
        $median = $this->median($newAmounts);

        return [
            'new_amount' => $newAmount, 'topup_amount' => $topAmount, 'total' => $newAmount + $topAmount,
            'new_count' => $count, 'borrowers' => count($borrowers),
            'average' => $count ? $newAmount / $count : 0.0, 'median' => $median,
            'topup_events' => count($top), 'topup_contracts' => count(array_unique(array_column($top, 'contract_id'))),
            'new_borrowers' => count($newBorrowers), 'repeat_borrowers' => count($repeatBorrowers),
            'by_category' => $byCat,
            'borrower_loan_sizes' => [
                'new' => ['count' => count($bySize['new']), 'total' => array_sum($bySize['new']), 'average' => $bySize['new'] ? array_sum($bySize['new']) / count($bySize['new']) : null, 'median' => $bySize['new'] ? $this->median($bySize['new']) : null],
                'repeat' => ['count' => count($bySize['repeat']), 'total' => array_sum($bySize['repeat']), 'average' => $bySize['repeat'] ? array_sum($bySize['repeat']) / count($bySize['repeat']) : null, 'median' => $bySize['repeat'] ? $this->median($bySize['repeat']) : null],
            ],
        ];
    }

    private function categoryRows(array $stock, array $flowCur, array $flowPrev, float $estTotal, array $titles): array
    {
        $ids = array_unique(array_merge(array_keys($titles), array_keys($stock), array_keys($flowCur['by_category']), array_keys($flowPrev['by_category'])));
        sort($ids);

        $rows = [];
        foreach ($ids as $id) {
            $cf = $flowCur['by_category'][$id] ?? [];
            $pf = $flowPrev['by_category'][$id] ?? [];
            $disb = ($cf['new_amount'] ?? 0) + ($cf['topup_amount'] ?? 0);
            $prevDisb = ($pf['new_amount'] ?? 0) + ($pf['topup_amount'] ?? 0);
            $est = $stock[$id]['est_cur'] ?? 0.0;
            $estPrev = $stock[$id]['est_prev'] ?? 0.0;
            $change = $this->change($disb, $prevDisb);
            $rows[] = [
                'category_id' => $id === 'null' ? null : (int) $id,
                'category' => $id === 'null' ? 'Առանց տեսակի' : ($titles[$id] ?? "#$id"),
                'estimated_collateral' => round($est, 2),
                'estimated_share_percent' => $this->pct($est, $estTotal),
                'previous_estimated_collateral' => round($estPrev, 2),
                'estimated_change_percent' => $this->change($est, $estPrev)['change_percent'],
                'outstanding_principal' => round($stock[$id]['prov_cur'] ?? 0.0, 2),
                'new_loan_disbursement' => round($cf['new_amount'] ?? 0, 2),
                'top_up_disbursement' => round($cf['topup_amount'] ?? 0, 2),
                'period_disbursement' => round($disb, 2),
                'new_loan_count' => $cf['new_count'] ?? 0,
                'unique_borrowers' => count($cf['clients'] ?? []),
                'disbursement_share_percent' => $this->pct($disb, $flowCur['total']),
                'previous_period_disbursement' => round($prevDisb, 2),
                'change_percent' => $change['change_percent'],
                'change_direction' => $change['direction'],
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------ Tranche 2: trend, drivers, LTV

    /** Net in-out by calendar month for estimated collateral and principal, all history up to $cur (1 query). */
    public function monthlyStock(int $pawnshopId, string $cur): array
    {
        $signed = "CASE h.type WHEN 'in' THEN h.amount WHEN 'out' THEN -h.amount ELSE 0 END";
        $rows = DB::select("
            SELECT DATE_FORMAT(h.date, '%Y-%m') AS ym,
              SUM(CASE WHEN h.amount_type='estimated_amount' THEN $signed ELSE 0 END) AS est,
              SUM(CASE WHEN h.amount_type='provided_amount' THEN $signed ELSE 0 END) AS prov
            FROM contract_amount_histories h
            WHERE h.pawnshop_id = :pid AND h.deleted_at IS NULL
              AND h.amount_type IN ('estimated_amount','provided_amount') AND h.date <= :cur
            GROUP BY ym ORDER BY ym
        ", ['pid' => $pawnshopId, 'cur' => $cur]);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->ym] = ['est' => (float) $r->est, 'prov' => (float) $r->prov];
        }
        return $out;
    }

    /**
     * Monthly trend over the last N months ending with the selected month (the selected month is month-to-date
     * when it is the current month). Months before the pawnshop's first history row are not invented.
     * Every number comes from the same flow()/ltv() functions as the headline KPIs.
     */
    private function trend(array $events, array $monthlyStock, array $p, int $n, array $titles): array
    {
        $firstYm = $monthlyStock ? array_key_first($monthlyStock) : null;

        // cumulative month-end stock, carried forward across months without rows
        $cum = [];
        if ($firstYm) {
            $est = $prov = 0.0;
            for ($m = Carbon::parse($firstYm . '-01'); $m->lte($p['start']); $m->addMonthNoOverflow()) {
                $ym = $m->format('Y-m');
                $est += $monthlyStock[$ym]['est'] ?? 0;
                $prov += $monthlyStock[$ym]['prov'] ?? 0;
                $cum[$ym] = [$est, $prov];
            }
        }

        $months = [];
        $legend = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $mStart = $p['start']->copy()->subMonthsNoOverflow($i);
            $ym = $mStart->format('Y-m');
            if (!$firstYm || $ym < $firstYm) {
                continue;
            }
            $mEnd = $i === 0 ? $p['end']->copy() : $mStart->copy()->endOfMonth()->startOfDay();
            $f = $this->flow($events, $mStart->toDateString(), $mEnd->toDateString());
            $l = $this->ltv($events, $mStart->toDateString(), $mEnd->toDateString());
            [$est, $prov] = $cum[$ym] ?? [0.0, 0.0];

            $cats = [];
            foreach ($f['by_category'] as $cid => $c) {
                $amt = ($c['new_amount'] ?? 0) + ($c['topup_amount'] ?? 0);
                $cats[$cid] = round($amt, 2);
                $legend[$cid] = $cid === 'null' ? 'Առանց տեսակի' : ($titles[$cid] ?? "#$cid");
            }
            $sizes = $f['borrower_loan_sizes'];
            $months[] = [
                'month' => $ym,
                'start' => $mStart->toDateString(),
                'end' => $mEnd->toDateString(),
                'is_partial' => $i === 0 && $p['is_mtd'],
                'total_disbursement' => round($f['total'], 2),
                'new_disbursement' => round($f['new_amount'], 2),
                'top_up_disbursement' => round($f['topup_amount'], 2),
                'new_loan_count' => $f['new_count'],
                'unique_borrowers' => $f['borrowers'],
                'average_new_loan' => round($f['average'], 2),
                'median_new_loan' => round($f['median'], 2),
                'new_borrowers' => $f['new_borrowers'],
                'repeat_borrowers' => $f['repeat_borrowers'],
                'new_borrower_share_percent' => $this->pct($f['new_borrowers'], $f['new_borrowers'] + $f['repeat_borrowers']),
                'repeat_borrower_share_percent' => $this->pct($f['repeat_borrowers'], $f['new_borrowers'] + $f['repeat_borrowers']),
                'estimated_collateral' => round($est, 2),
                'outstanding_principal' => round($prov, 2),
                'portfolio_ratio' => $this->ratio($prov, $est),
                'median_origination_ltv' => $l['all']['median'],
                'average_origination_ltv' => $l['all']['average'],
                'weighted_origination_ratio' => $l['all']['weighted_ratio'],
                'ltv_eligible_loans' => $l['all']['count'],
                'categories' => $cats,
            ];
        }
        ksort($legend);
        $legendList = [];
        foreach ($legend as $cid => $title) {
            $legendList[] = ['category_id' => $cid === 'null' ? null : (int) $cid, 'key' => (string) $cid, 'title' => $title];
        }

        return ['months_requested' => $n, 'months' => $months, 'category_legend' => $legendList];
    }

    /** Why lending moved: counts, ticket sizes and top-ups side by side. Metrics only, no attribution. */
    private function drivers(array $cur, array $prev): array
    {
        return [
            'total_disbursement' => $this->change($cur['total'], $prev['total']),
            'new_loan_count' => $this->change($cur['new_count'], $prev['new_count']),
            'average_new_loan' => $this->change($cur['average'], $prev['average']),
            'median_new_loan' => $this->change($cur['median'], $prev['median']),
            'top_up' => [
                'amount' => $this->change($cur['topup_amount'], $prev['topup_amount']),
                'share_percent' => $this->pct($cur['topup_amount'], $cur['total']),
                'previous_share_percent' => $this->pct($prev['topup_amount'], $prev['total']),
                'change_amount' => round($cur['topup_amount'] - $prev['topup_amount'], 2),
            ],
        ];
    }

    /**
     * LOGICAL DISBURSEMENT DATE = date of the first history row of the logical disbursement (later rows of the same
     * deal_id are folded into it); deals.date is deliberately not used. See disbursementEvents().
     *
     * Origination LTV per new loan = logical first disbursement / estimate in force on that day * 100.
     * Eligible: estimate rows exist, estimate > 0, disbursement > 0. Loans without a category stay in the
     * aggregate and form their own 'null' category. Top-ups and later revaluations are never looked at.
     */
    public function ltv(array $events, string $from, string $to): array
    {
        $rows = [];
        $excl = ['no_estimate' => 0, 'zero_estimate' => 0, 'invalid_disbursement' => 0];
        $exRows = [];
        $total = 0;
        $noCat = 0;
        foreach ($events as $e) {
            if (!$e['is_first'] || $e['date'] < $from || $e['date'] > $to) {
                continue;
            }
            $total++;
            if ($e['amount'] <= 0) {
                $excl['invalid_disbursement']++;
                $exRows[] = ['contract_id' => $e['contract_id'], 'reason' => 'invalid_disbursement'];
            } elseif ($e['est_rows'] === 0) {
                $excl['no_estimate']++;
                $exRows[] = ['contract_id' => $e['contract_id'], 'reason' => 'no_estimate'];
            } elseif ($e['est_at_origination'] === null || $e['est_at_origination'] <= 0) {
                $excl['zero_estimate']++;
                $exRows[] = ['contract_id' => $e['contract_id'], 'reason' => 'zero_estimate'];
            } else {
                $rows[] = [
                    'ltv' => round($e['amount'] * 100 / $e['est_at_origination'], 6),
                    'init' => $e['amount'], 'est' => $e['est_at_origination'],
                    'cat' => $e['category_id'] ?? 'null', 'contract_id' => $e['contract_id'], 'date' => $e['date'],
                ];
                $noCat += $e['category_id'] === null ? 1 : 0;
            }
        }

        $bounds = [['0-40', 40], ['40-60', 60], ['60-70', 70], ['70-80', 80], ['80-100', 100], ['100+', null]];
        $dist = array_map(fn ($b) => ['bucket' => $b[0], 'loan_count' => 0, 'initial_disbursement' => 0.0], $bounds);
        $byCat = [];
        foreach ($rows as $r) {
            foreach ($bounds as $i => $b) {
                if ($b[1] === null || $r['ltv'] <= $b[1]) {
                    $dist[$i]['loan_count']++;
                    $dist[$i]['initial_disbursement'] += $r['init'];
                    break;
                }
            }
            $byCat[$r['cat']][] = $r;
        }
        foreach ($dist as &$d) {
            $d['share_percent'] = $this->pct($d['loan_count'], count($rows));
            $d['initial_disbursement'] = round($d['initial_disbursement'], 2);
        }
        unset($d);

        return [
            'total_new_loans' => $total,
            'eligible_loans' => count($rows),
            'excluded_loans' => $total - count($rows),
            'exclusions' => $excl,
            'eligible_without_category' => $noCat,
            'all' => $this->ltvStats($rows),
            'distribution' => $dist,
            'above_80_share_percent' => $this->pct(count(array_filter($rows, fn ($r) => $r['ltv'] > 80)), count($rows)),
            'by_category' => array_map(fn ($c) => $this->ltvStats($c), $byCat),
            'rows' => $rows,
            'excluded_rows' => $exRows,
        ];
    }

    /** Average, median and weighted ratio are different things and are returned separately; null when no loans. */
    private function ltvStats(array $rows): array
    {
        if (!$rows) {
            return ['count' => 0, 'median' => null, 'average' => null, 'weighted_ratio' => null, 'min' => null, 'max' => null];
        }
        $ltvs = array_column($rows, 'ltv');
        return [
            'count' => count($rows),
            'median' => round($this->median($ltvs), 1),
            'average' => round(array_sum($ltvs) / count($ltvs), 1),
            'weighted_ratio' => round(array_sum(array_column($rows, 'init')) * 100 / array_sum(array_column($rows, 'est')), 1),
            'min' => round(min($ltvs), 1),
            'max' => round(max($ltvs), 1),
        ];
    }

    private function ltvBlock(array $cur, array $prev): array
    {
        $a = $cur['all'];
        $b = $prev['all'];
        return [
            'total_new_loans' => $cur['total_new_loans'],
            'eligible_loans' => $cur['eligible_loans'],
            'excluded_loans' => $cur['excluded_loans'],
            'exclusions' => $cur['exclusions'],
            'eligible_without_category' => $cur['eligible_without_category'],
            'median' => $a['median'], 'average' => $a['average'], 'weighted_ratio' => $a['weighted_ratio'],
            'min' => $a['min'], 'max' => $a['max'],
            'previous' => ['median' => $b['median'], 'average' => $b['average'], 'weighted_ratio' => $b['weighted_ratio'], 'eligible_loans' => $b['count']],
            'median_change' => $this->changePp($a['median'], $b['median']),
            'above_80_share_percent' => $cur['above_80_share_percent'],
            'previous_above_80_share_percent' => $prev['above_80_share_percent'],
            'distribution' => $cur['distribution'],
        ];
    }

    /** Categories whose stored estimate is known to be unreliable for LTV (resolved by canonical categories.name). */
    public const LTV_WARNING_CATEGORY_NAMES = ['gold'];

    private function collateralPerformance(array $stock, array $cur, array $prev, array $ltv, float $estTotal, array $titles): array
    {
        $warnIds = Category::query()->whereIn('name', self::LTV_WARNING_CATEGORY_NAMES)->pluck('id')->all();
        $ids = array_unique(array_merge(array_keys($titles), array_keys($stock), array_keys($cur['by_category']), array_keys($prev['by_category'])));
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            $c = $cur['by_category'][$id] ?? [];
            $pr = $prev['by_category'][$id] ?? [];
            $disb = ($c['new_amount'] ?? 0) + ($c['topup_amount'] ?? 0);
            $prevDisb = ($pr['new_amount'] ?? 0) + ($pr['topup_amount'] ?? 0);
            $count = $c['new_count'] ?? 0;
            $share = $this->pct($disb, $cur['total']);
            $prevShare = $this->pct($prevDisb, $prev['total']);
            $dch = $this->change($disb, $prevDisb);
            $cch = $this->change($count, $pr['new_count'] ?? 0);
            $l = $ltv['by_category'][$id] ?? $this->ltvStats([]);
            $est = $stock[$id]['est_cur'] ?? 0.0;
            $rows[] = [
                'category_id' => $id === 'null' ? null : (int) $id,
                'category' => $id === 'null' ? 'Առանց տեսակի' : ($titles[$id] ?? "#$id"),
                'new_loan_disbursement' => round($c['new_amount'] ?? 0, 2),
                'top_up_disbursement' => round($c['topup_amount'] ?? 0, 2),
                'period_disbursement' => round($disb, 2),
                'share_percent' => $share,
                'previous_share_percent' => $prevShare,
                'share_change_pp' => ($share !== null && $prevShare !== null) ? round($share - $prevShare, 1) : null,
                'previous_disbursement' => round($prevDisb, 2),
                'change_percent' => $dch['change_percent'],
                'change_direction' => $dch['direction'],
                'change_amount' => round($disb - $prevDisb, 2),
                'new_loan_count' => $count,
                'previous_new_loan_count' => $pr['new_count'] ?? 0,
                'count_change_percent' => $cch['change_percent'],
                'unique_borrowers' => count($c['clients'] ?? []),
                'new_borrowers' => count($c['new_clients'] ?? []),
                'repeat_borrowers' => count($c['repeat_clients'] ?? []),
                'average_new_loan' => $count ? round($c['new_amount'] / $count, 2) : null,
                'median_new_loan' => $count ? round($this->median($c['new_amounts']), 2) : null,
                'estimated_collateral' => round($est, 2),
                'estimated_share_percent' => $this->pct($est, $estTotal),
                'median_origination_ltv' => $l['median'],
                'average_origination_ltv' => $l['average'],
                'weighted_origination_ratio' => $l['weighted_ratio'],
                'max_origination_ltv' => $l['max'],
                'ltv_eligible_loans' => $l['count'],
                // Gold estimates were seen equal to the loan amount (not an independent appraisal): interpret cautiously.
                'ltv_quality_warning' => $id !== 'null' && in_array((int) $id, $warnIds, true),
            ];
        }
        return $rows;
    }

    private function borrowerMix(array $cur, array $prev): array
    {
        $mix = fn (array $f) => [
            'new' => $f['new_borrowers'], 'repeat' => $f['repeat_borrowers'],
            'new_share_percent' => $this->pct($f['new_borrowers'], $f['new_borrowers'] + $f['repeat_borrowers']),
            'repeat_share_percent' => $this->pct($f['repeat_borrowers'], $f['new_borrowers'] + $f['repeat_borrowers']),
            'loan_sizes' => $f['borrower_loan_sizes'],
            'new_loans' => $f['borrower_loan_sizes']['new']['count'], 'repeat_loans' => $f['borrower_loan_sizes']['repeat']['count'],
            'new_disbursement' => round($f['borrower_loan_sizes']['new']['total'], 2), 'repeat_disbursement' => round($f['borrower_loan_sizes']['repeat']['total'], 2),
            'new_disbursement_share_percent' => $this->pct($f['borrower_loan_sizes']['new']['total'], $f['new_amount']),
            'repeat_disbursement_share_percent' => $this->pct($f['borrower_loan_sizes']['repeat']['total'], $f['new_amount']),
        ];
        return ['current' => $mix($cur), 'previous' => $mix($prev)];
    }

    /**
     * Deterministic facts for the "Key changes" block. Structured (type + numbers), the UI words them.
     * Only emitted when the comparison is defined; no explanations, no causality.
     */
    private function highlights(array $r): array
    {
        $out = [];
        $perf = array_filter($r['collateral_performance'], fn ($c) => $c['change_amount'] != 0);
        usort($perf, fn ($a, $b) => $b['change_amount'] <=> $a['change_amount']);
        $up = $perf[0] ?? null;
        $down = $perf ? end($perf) : null;
        foreach ([['category_growth', $up, 1], ['category_decline', $down, -1]] as [$type, $c, $sign]) {
            if ($c && $c['change_amount'] * $sign > 0) {
                $out[] = ['type' => $type, 'category' => $c['category'], 'change_percent' => $c['change_percent'],
                    'change_amount' => $c['change_amount'], 'current' => $c['period_disbursement'], 'previous' => $c['previous_disbursement']];
            }
        }
        $d = $r['drivers'];
        if ($d['total_disbursement']['direction'] !== 'unavailable' && $d['total_disbursement']['direction'] !== 'unchanged') {
            $out[] = ['type' => 'total_change', 'change_percent' => $d['total_disbursement']['change_percent']];
        }
        if ($d['new_loan_count']['direction'] === 'up' || $d['new_loan_count']['direction'] === 'down') {
            $out[] = ['type' => 'new_loan_count_change', 'change_percent' => $d['new_loan_count']['change_percent']];
        }
        if ($d['top_up']['share_percent'] !== null) {
            $out[] = ['type' => 'top_up_share', 'share_percent' => $d['top_up']['share_percent']];
        }
        $bm = $r['borrower_mix']['current'];
        if ($bm['new_share_percent'] !== null) {
            $out[] = ['type' => 'borrower_mix', 'new_share_percent' => $bm['new_share_percent'], 'repeat_share_percent' => $bm['repeat_share_percent']];
        }
        $mc = $r['origination_ltv']['median_change'];
        if ($mc['change_pp'] !== null && $mc['change_pp'] != 0.0) {
            $out[] = ['type' => 'median_ltv_change', 'current' => $mc['current'], 'previous' => $mc['previous'], 'change_pp' => $mc['change_pp']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ small helpers

    /** Money/count KPI with comparison. direction: up | down | unchanged | unavailable (previous = 0). */
    public function change(float|int $current, float|int $previous): array
    {
        $current = round((float) $current, 2);
        $previous = round((float) $previous, 2);
        if ($previous == 0.0) {
            [$percent, $dir] = $current == 0.0 ? [0.0, 'unchanged'] : [null, 'unavailable'];
        } else {
            $percent = round(($current - $previous) / abs($previous) * 100, 1);
            $dir = $current == $previous ? 'unchanged' : ($current > $previous ? 'up' : 'down');
        }
        return ['current' => $current, 'previous' => $previous, 'change_percent' => $percent, 'direction' => $dir];
    }

    /** Rate KPI: movement in percentage points. */
    public function changePp(?float $current, ?float $previous): array
    {
        if ($current === null || $previous === null) {
            return ['current' => $current, 'previous' => $previous, 'change_pp' => null, 'direction' => 'unavailable'];
        }
        $pp = round($current - $previous, 1);
        return ['current' => $current, 'previous' => $previous, 'change_pp' => $pp,
            'direction' => $pp == 0.0 ? 'unchanged' : ($pp > 0 ? 'up' : 'down')];
    }

    public function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    public function ratio(float $numerator, float $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 1) : null;
    }

    public function pct(float|int $part, float|int $whole): ?float
    {
        return $whole != 0 ? round($part / $whole * 100, 1) : null;
    }
}
