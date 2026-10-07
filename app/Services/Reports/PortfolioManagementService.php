<?php

namespace App\Services\Reports;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Tranche 3 of /reports: ACTIVE PORTFOLIO MANAGEMENT ("what needs attention now").
 *
 * Everything is derived from ONE contract-level dataset (rows()) built from two queries:
 *   1. a per-contract aggregate of contract_amount_histories (outstanding / estimate as of a date, as of the
 *      closure day, lifetime, category conflicts) joined to contracts and clients;
 *   2. the logical disbursement events already used by Tranche 1/2 (first disbursement + top-ups).
 * Blocks, exception counts and the drill-down lists are all filters/aggregations of those rows, so a headline
 * count and the list behind it can never disagree.
 *
 * DEFINITIONS (also repeated in the report documentation)
 *  - first logical disbursement: unchanged (CreditActivityReportService::disbursementEvents); valid when amount > 0.
 *  - outstanding principal: history-net provided_amount as of the selected end date (unchanged).
 *  - MATERIALITY: history amounts are stored to the cent and carry residues such as -0.33 / -1.18 AMD on repaid
 *    contracts. One configurable threshold (MATERIALITY_AMD) is used for every "is it zero / is it different"
 *    decision: |x| <= threshold counts as zero; a mismatch/negative/leftover must exceed it.
 *  - STATUS-OPEN as of D: the contract is not completed/executed, or it is completed/executed with closed_at > D.
 *    A completed/executed contract WITHOUT closed_at is treated as closed at every date (its closure cannot be
 *    placed in time) and is reported as a data-quality exception. No date is ever inferred from updated_at.
 *    There is no execution date in the schema: contracts.executed is an unused varchar and the same closed_at
 *    column is used for executed contracts.
 *  - ACTIVE as of D = valid first disbursement dated <= D AND status-open as of D AND outstanding(D) > threshold.
 *    The two halves are reconciled: active-by-status, active-by-balance and the disagreements are returned.
 *  - MATURITY relative to D: contracts.deadline (NOT NULL date column) versus D, never versus today.
 *    Buckets: matured (< D), 0-7, 8-30, 31-60, 61+ days (day 0 = deadline equals D). The "within N days" KPIs are
 *    cumulative (0..N). This is contract maturity, NOT payment delinquency / NPL.
 *  - Status is never used to place a closure in history; closed_at is.
 */
class PortfolioManagementService
{
    /** AMD. Differences up to this size are rounding noise, not exceptions. */
    public const MATERIALITY_AMD = 100.0;

    private const CLOSED_STATUSES = ['completed', 'executed'];

    /** [key, min days, max days|null]; age = as_of - first logical disbursement date. */
    private const AGE_BUCKETS = [['0-30', 0, 30], ['31-90', 31, 90], ['91-180', 91, 180], ['181-365', 181, 365], ['365+', 366, null]];

    /**
     * Allowlisted drill-down types: label (UI wording), group, severity when count > 0, default sort.
     * Nothing outside this list ever reaches a query or a filter.
     */
    public const DETAIL_TYPES = [
        'active' => ['label' => 'Ակտիվ պայմանագրեր', 'group' => 'portfolio', 'severity' => 'info', 'sort' => 'outstanding'],
        'maturing_7' => ['label' => 'Մոտակա 7 օրում ավարտվող', 'group' => 'business', 'severity' => 'info', 'sort' => 'deadline'],
        'maturing_30' => ['label' => 'Մոտակա 30 օրում ավարտվող', 'group' => 'portfolio', 'severity' => 'info', 'sort' => 'deadline'],
        'matured_active' => ['label' => 'Ժամկետն անցած, բայց չփակված', 'group' => 'business', 'severity' => 'warning', 'sort' => 'deadline'],
        'closed' => ['label' => 'Փակված պայմանագրեր (ժամանակահատվածում)', 'group' => 'portfolio', 'severity' => 'info', 'sort' => 'closed_at'],
        'topups' => ['label' => 'Top-up ստացած պայմանագրեր (ժամանակահատվածում)', 'group' => 'portfolio', 'severity' => 'info', 'sort' => 'topup_date'],
        'repeated_topups' => ['label' => 'Մեկից ավելի top-up ունեցող պայմանագրեր', 'group' => 'business', 'severity' => 'info', 'sort' => 'topup_total'],
        'ltv_over_100' => ['label' => 'Տրամադրման պահին LTV > 100%', 'group' => 'business', 'severity' => 'warning', 'sort' => 'origination_ltv'],
        'principal_mismatch' => ['label' => 'Մնացորդի անհամապատասխանություն', 'group' => 'data_quality', 'severity' => 'warning', 'sort' => 'difference'],
        'missing_disbursement' => ['label' => 'Տրամադրման պատմություն չկա', 'group' => 'data_quality', 'severity' => 'warning', 'sort' => 'num'],
        'category_conflict' => ['label' => 'Գրավի տեսակի անհամապատասխանություն', 'group' => 'data_quality', 'severity' => 'info', 'sort' => 'num'],
        'missing_estimate' => ['label' => 'Գնահատում չկա (տրամադրման պահին)', 'group' => 'data_quality', 'severity' => 'info', 'sort' => 'num'],
        'negative_outstanding' => ['label' => 'Բացասական մնացորդ', 'group' => 'data_quality', 'severity' => 'critical', 'sort' => 'outstanding'],
        'completed_with_balance' => ['label' => 'Փակված, բայց մնացորդով', 'group' => 'data_quality', 'severity' => 'warning', 'sort' => 'outstanding'],
        'active_zero_balance' => ['label' => 'Ակտիվ կարգավիճակ՝ զրոյական մնացորդով', 'group' => 'data_quality', 'severity' => 'warning', 'sort' => 'num'],
        'closed_without_date' => ['label' => 'Փակված՝ առանց փակման ամսաթվի', 'group' => 'data_quality', 'severity' => 'info', 'sort' => 'num'],
    ];

    /** public sort key => row field. Only these may be sorted on. */
    public const SORT_FIELDS = [
        'num' => 'num', 'outstanding' => 'outstanding', 'deadline' => 'deadline', 'first_disbursement_date' => 'first_date',
        'topup_amount' => 'topup_amount', 'topup_date' => 'topup_date', 'topup_total' => 'topup_total',
        'origination_ltv' => 'origination_ltv', 'difference' => 'difference_abs', 'closed_at' => 'closed_at',
    ];

    public function __construct(private CreditActivityReportService $base)
    {
    }

    // ------------------------------------------------------------------ dataset

    /**
     * One row per non-deleted contract of the pawnshop, with every derived flag. $events are the logical
     * disbursement events (Tranche 1/2 definition) up to $asOf.
     */
    public function rows(int $pawnshopId, string $asOf, string $start, string $end, array $events, array $titles): array
    {
        $signed = "CASE h.type WHEN 'in' THEN h.amount WHEN 'out' THEN -h.amount ELSE 0 END";
        $raw = DB::select("
            SELECT c.id, c.num, c.client_id, c.status, c.deadline, c.closed_at, c.provided_amount AS contract_provided,
                   c.category_id AS contract_category,
                   cl.type AS client_type, cl.name AS client_name, cl.surname AS client_surname, cl.company_name AS client_company,
                   a.prov_asof, a.prov_all, a.est_asof, a.prov_at_close, a.prov_before_close, a.cat_conflicts, a.hist_cat, a.prov_in_rows
            FROM contracts c
            LEFT JOIN clients cl ON cl.id = c.client_id
            LEFT JOIN (
              SELECT h.contract_id,
                SUM(CASE WHEN h.amount_type = 'provided_amount' AND h.date <= :asof1 THEN $signed ELSE 0 END) AS prov_asof,
                SUM(CASE WHEN h.amount_type = 'provided_amount' THEN $signed ELSE 0 END) AS prov_all,
                SUM(CASE WHEN h.amount_type = 'estimated_amount' AND h.date <= :asof2 THEN $signed ELSE 0 END) AS est_asof,
                SUM(CASE WHEN h.amount_type = 'provided_amount' AND k.closed_at IS NOT NULL AND h.date <= k.closed_at THEN $signed ELSE 0 END) AS prov_at_close,
                SUM(CASE WHEN h.amount_type = 'provided_amount' AND k.closed_at IS NOT NULL AND h.date < k.closed_at THEN $signed ELSE 0 END) AS prov_before_close,
                SUM(CASE WHEN h.category_id IS NOT NULL AND k.category_id IS NOT NULL AND h.category_id <> k.category_id THEN 1 ELSE 0 END) AS cat_conflicts,
                MIN(CASE WHEN h.amount_type = 'provided_amount' THEN h.category_id END) AS hist_cat,
                SUM(CASE WHEN h.amount_type = 'provided_amount' AND h.type = 'in' THEN 1 ELSE 0 END) AS prov_in_rows
              FROM contract_amount_histories h
              JOIN contracts k ON k.id = h.contract_id
              WHERE h.pawnshop_id = :pid1 AND h.deleted_at IS NULL AND h.amount_type IN ('estimated_amount', 'provided_amount')
              GROUP BY h.contract_id
            ) a ON a.contract_id = c.id
            WHERE c.pawnshop_id = :pid2 AND c.deleted_at IS NULL
            ORDER BY c.id
        ", ['asof1' => $asOf, 'asof2' => $asOf, 'pid1' => $pawnshopId, 'pid2' => $pawnshopId]);

        $first = [];
        $tops = [];
        foreach ($events as $e) {
            if ($e['date'] > $asOf) {
                continue;
            }
            if ($e['is_first']) {
                $first[$e['contract_id']] = $e;
            } else {
                $tops[$e['contract_id']][] = $e;
            }
        }

        $tol = self::MATERIALITY_AMD;
        $rows = [];
        foreach ($raw as $r) {
            $id = (int) $r->id;
            $f = $first[$id] ?? null;
            $valid = $f !== null && $f['amount'] > 0;
            $statusClosed = in_array($r->status, self::CLOSED_STATUSES, true);
            $closedAt = $this->validDate($r->closed_at);
            $statusOpen = !$statusClosed || ($closedAt !== null && $closedAt > $asOf);
            $prov = (float) ($r->prov_asof ?? 0);
            $provAll = (float) ($r->prov_all ?? 0);
            $contractProvided = (float) $r->contract_provided;
            $deadline = $this->validDate($r->deadline);
            $statusActive = $valid && $statusOpen;
            $balanceActive = $valid && $prov > $tol;
            $active = $statusActive && $balanceActive;
            $catId = $r->contract_category !== null ? (int) $r->contract_category : ($r->hist_cat !== null ? (int) $r->hist_cat : null);

            $est = $f['est_at_origination'] ?? null;
            $ltv = ($valid && ($f['est_rows'] ?? 0) > 0 && $est !== null && $est > 0) ? round($f['amount'] * 100 / $est, 6) : null;
            $inPeriod = $valid && $f['date'] >= $start && $f['date'] <= $end;

            $topList = $tops[$id] ?? [];
            $topTotal = array_sum(array_column($topList, 'amount'));
            $daysToMaturity = $deadline === null ? null : $this->daysBetween($asOf, $deadline);
            $closedInPeriod = $statusClosed && $closedAt !== null && $closedAt >= $start && $closedAt <= $end;
            $diff = $provAll - $contractProvided;

            $flags = [
                'active' => $active,
                'matured_active' => $active && $daysToMaturity !== null && $daysToMaturity < 0,
                'maturing_7' => $active && $daysToMaturity !== null && $daysToMaturity >= 0 && $daysToMaturity <= 7,
                'maturing_30' => $active && $daysToMaturity !== null && $daysToMaturity >= 0 && $daysToMaturity <= 30,
                'closed' => $closedInPeriod,
                'repeated_topups' => count($topList) > 1,
                'ltv_over_100' => $inPeriod && $ltv !== null && $ltv > 100,
                'missing_estimate' => $inPeriod && $ltv === null,
                'principal_mismatch' => abs($diff) > $tol,
                'missing_disbursement' => $contractProvided > 0 && (int) ($r->prov_in_rows ?? 0) === 0,
                'category_conflict' => (int) ($r->cat_conflicts ?? 0) > 0,
                'negative_outstanding' => $prov < -$tol,
                'completed_with_balance' => !$statusOpen && $prov > $tol,
                'active_zero_balance' => $statusActive && abs($prov) <= $tol,
                'closed_without_date' => $statusClosed && $closedAt === null,
            ];

            $rows[$id] = [
                'id' => $id, 'num' => $r->num, 'client_id' => (int) $r->client_id,
                'customer' => $this->customerName($r),
                'status' => $r->status, 'category_id' => $catId,
                'category' => $catId === null ? 'Առանց տեսակի' : ($titles[$catId] ?? "#$catId"),
                'deadline' => $deadline, 'closed_at' => $closedAt, 'days_to_maturity' => $daysToMaturity,
                'first_date' => $valid ? $f['date'] : null, 'first_amount' => $valid ? round($f['amount'], 2) : null,
                'origination_estimate' => $valid ? $est : null, 'origination_ltv' => $ltv,
                'age_days' => $valid ? max(0, $this->daysBetween($f['date'], $asOf)) : null,
                'outstanding' => round($prov, 2), 'estimated_collateral' => round((float) ($r->est_asof ?? 0), 2),
                'contract_provided' => round($contractProvided, 2), 'history_outstanding' => round($provAll, 2),
                'difference' => round($diff, 2), 'difference_abs' => abs($diff),
                'prov_at_close' => $closedAt !== null ? (float) ($r->prov_at_close ?? 0) : null,
                'prov_before_close' => $closedAt !== null ? (float) ($r->prov_before_close ?? 0) : null,
                'topup_count' => count($topList), 'topup_total' => round($topTotal, 2),
                'status_closed' => $statusClosed, 'status_active' => $statusActive, 'balance_active' => $balanceActive,
                'active' => $active, 'valid_first' => $valid, 'flags' => $flags,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ report blocks

    /**
     * @param array $period  ['start','end','as_of']
     * @param array $months  trend months: [['month' => 'Y-m', 'start', 'end'], ...]
     */
    public function build(int $pawnshopId, array $period, array $events, array $months, array $titles): array
    {
        $asOf = $period['as_of'];
        $start = $period['start'];
        $end = $period['end'];
        $rows = $this->rows($pawnshopId, $asOf, $start, $end, $events, $titles);
        $active = array_values(array_filter($rows, fn ($r) => $r['active']));

        $outstanding = array_sum(array_column($active, 'outstanding'));
        $balances = array_column($active, 'outstanding');
        $estimated = array_sum(array_column($active, 'estimated_collateral'));
        $count = count($active);
        $b = $this->base;

        $maturity = $this->maturity($active, $asOf);
        $closures = $this->closures($rows, $start, $end);
        $topups = $this->topups($events, $rows, $start, $end);

        $pm = [
            'as_of' => $asOf,
            'active_contracts' => $count,
            'active_borrowers' => count(array_unique(array_column($active, 'client_id'))),
            'active_outstanding' => round($outstanding, 2),
            'average_active_balance' => $count ? round($outstanding / $count, 2) : null,
            'median_active_balance' => $count ? round($b->median($balances), 2) : null,
            'active_estimated_collateral' => round($estimated, 2),
            'active_loan_collateral_ratio' => $b->ratio($outstanding, $estimated),
            'reconciliation' => $this->activeReconciliation($rows),
            'maturity' => $maturity,
            'closures' => $closures,
            'opened_vs_closed' => $this->openedVsClosed($events, $rows, $months),
            'age_distribution' => $this->ageDistribution($active, $outstanding),
            'topups' => $topups,
            'borrowers' => $this->borrowerManagement($events, $start, $end),
            'customer_frequency' => $this->customerFrequency($events, $asOf, $rows),
            'concentration' => $this->concentration($active, $outstanding),
            'materiality_amd' => self::MATERIALITY_AMD,
        ];

        return [
            'portfolio_management' => $pm,
            'management_exceptions' => $this->exceptions($rows, $maturity, $closures),
            'active_by_category' => $this->activeByCategory($active, $outstanding),
            'portfolio_highlights' => $this->highlights($pm),
        ];
    }

    private function activeReconciliation(array $rows): array
    {
        $byStatus = array_filter($rows, fn ($r) => $r['status_active']);
        $byBalance = array_filter($rows, fn ($r) => $r['balance_active']);
        return [
            'active_by_status' => count($byStatus),
            'active_by_balance' => count($byBalance),
            'active' => count(array_filter($rows, fn ($r) => $r['active'])),
            'status_active_without_balance' => count(array_filter($rows, fn ($r) => $r['status_active'] && !$r['balance_active'])),
            'balance_without_active_status' => count(array_filter($rows, fn ($r) => $r['balance_active'] && !$r['status_active'])),
            'contracts_without_valid_disbursement' => count(array_filter($rows, fn ($r) => !$r['valid_first'])),
            'contracts_total' => count($rows),
        ];
    }

    // ---- maturity

    /** Pure bucket function (unit-tested). */
    public function maturityBucket(?string $deadline, string $asOf): string
    {
        $deadline = $this->validDate($deadline);
        if ($deadline === null) {
            return 'missing';
        }
        $d = $this->daysBetween($asOf, $deadline);
        return match (true) {
            $d < 0 => 'matured',
            $d <= 7 => '0-7',
            $d <= 30 => '8-30',
            $d <= 60 => '31-60',
            default => '61+',
        };
    }

    private function maturity(array $active, string $asOf): array
    {
        $buckets = ['matured' => [0, 0.0], '0-7' => [0, 0.0], '8-30' => [0, 0.0], '31-60' => [0, 0.0], '61+' => [0, 0.0], 'missing' => [0, 0.0]];
        foreach ($active as $r) {
            $k = $this->maturityBucket($r['deadline'], $asOf);
            $buckets[$k][0]++;
            $buckets[$k][1] += $r['outstanding'];
        }
        $blk = fn (array $keys) => [
            'count' => array_sum(array_map(fn ($k) => $buckets[$k][0], $keys)),
            'outstanding' => round(array_sum(array_map(fn ($k) => $buckets[$k][1], $keys)), 2),
        ];
        $out = [];
        foreach ($buckets as $k => [$n, $amt]) {
            $out[] = ['bucket' => $k, 'count' => $n, 'outstanding' => round($amt, 2)];
        }
        return [
            'matured_active' => $blk(['matured']),
            'within_7_days' => $blk(['0-7']),
            'within_30_days' => $blk(['0-7', '8-30']),
            'within_60_days' => $blk(['0-7', '8-30', '31-60']),
            'missing_deadline' => $blk(['missing']),
            'buckets' => $out,
        ];
    }

    // ---- closures

    private function closures(array $rows, string $start, string $end): array
    {
        $completed = $executed = 0;
        $ages = [];
        $ageExcluded = 0;
        $extinguished = 0.0;
        $extExcluded = 0;
        foreach ($rows as $r) {
            if (!$r['flags']['closed']) {
                continue;
            }
            $r['status'] === 'executed' ? $executed++ : $completed++;
            if ($r['first_date'] !== null && $r['closed_at'] >= $r['first_date']) {
                $ages[] = $this->daysBetween($r['first_date'], $r['closed_at']);
            } else {
                $ageExcluded++;
            }
            // principal extinguished: outstanding the day before closure, only when it is fully repaid by the closure day
            if (abs($r['prov_at_close']) <= self::MATERIALITY_AMD && $r['prov_before_close'] > self::MATERIALITY_AMD) {
                $extinguished += $r['prov_before_close'];
            } else {
                $extExcluded++;
            }
        }
        return [
            'completed' => $completed, 'executed' => $executed, 'total' => $completed + $executed,
            'average_age_days' => $ages ? round(array_sum($ages) / count($ages), 1) : null,
            'median_age_days' => $ages ? round($this->base->median($ages), 1) : null,
            'age_excluded' => $ageExcluded,
            'extinguished_principal' => round($extinguished, 2),
            'extinguished_excluded' => $extExcluded,
            'missing_closed_at' => count(array_filter($rows, fn ($r) => $r['flags']['closed_without_date'])),
        ];
    }

    private function openedVsClosed(array $events, array $rows, array $months): array
    {
        $out = [];
        foreach ($months as $m) {
            $opened = 0;
            foreach ($events as $e) {
                if ($e['is_first'] && $e['date'] >= $m['start'] && $e['date'] <= $m['end']) {
                    $opened++;
                }
            }
            $closed = 0;
            foreach ($rows as $r) {
                if ($r['status_closed'] && $r['closed_at'] !== null && $r['closed_at'] >= $m['start'] && $r['closed_at'] <= $m['end']) {
                    $closed++;
                }
            }
            $out[] = ['month' => $m['month'], 'opened' => $opened, 'closed' => $closed, 'net_change' => $opened - $closed];
        }
        return $out;
    }

    // ---- age

    private function ageDistribution(array $active, float $total): array
    {
        $out = array_map(fn ($b) => ['bucket' => $b[0], 'count' => 0, 'outstanding' => 0.0], self::AGE_BUCKETS);
        foreach ($active as $r) {
            foreach (self::AGE_BUCKETS as $i => [$k, $min, $max]) {
                if ($r['age_days'] >= $min && ($max === null || $r['age_days'] <= $max)) {
                    $out[$i]['count']++;
                    $out[$i]['outstanding'] += $r['outstanding'];
                    break;
                }
            }
        }
        $n = count($active);
        foreach ($out as &$o) {
            $o['share_count_percent'] = $this->base->pct($o['count'], $n);
            $o['share_outstanding_percent'] = $this->base->pct($o['outstanding'], $total);
            $o['outstanding'] = round($o['outstanding'], 2);
        }
        return $out;
    }

    // ---- top-ups

    private function topups(array $events, array $rows, string $start, string $end): array
    {
        $period = [];
        $total = 0.0;
        $newAmount = 0.0;
        foreach ($events as $e) {
            if ($e['date'] < $start || $e['date'] > $end) {
                continue;
            }
            if ($e['is_first']) {
                $newAmount += $e['amount'];
            } else {
                $period[] = $e;
                $total += $e['amount'];
            }
        }
        $amounts = array_column($period, 'amount');
        $repeated = array_filter($rows, fn ($r) => $r['flags']['repeated_topups']);

        return [
            'contracts' => count(array_unique(array_column($period, 'contract_id'))),
            'events' => count($period),
            'amount' => round($total, 2),
            'average' => $period ? round($total / count($period), 2) : null,
            'median' => $period ? round($this->base->median($amounts), 2) : null,
            'share_of_disbursement_percent' => $this->base->pct($total, $total + $newAmount),
            'repeated' => [
                'contracts' => count($repeated),
                'current_outstanding' => round(array_sum(array_column($repeated, 'outstanding')), 2),
                'lifetime_amount' => round(array_sum(array_column($repeated, 'topup_total')), 2),
            ],
        ];
    }

    // ---- borrowers

    /** New vs repeat borrowers (client_first_date >= period start = new), with amounts. Same definition as Tranche 2. */
    private function borrowerManagement(array $events, string $start, string $end): array
    {
        $g = ['new' => ['clients' => [], 'amounts' => []], 'repeat' => ['clients' => [], 'amounts' => []]];
        foreach ($events as $e) {
            if (!$e['is_first'] || $e['date'] < $start || $e['date'] > $end || $e['client_id'] === null) {
                continue;
            }
            $k = $e['client_first_date'] >= $start ? 'new' : 'repeat';
            $g[$k]['clients'][$e['client_id']] = true;
            $g[$k]['amounts'][] = $e['amount'];
        }
        $summ = function (array $x) {
            $n = count($x['amounts']);
            $sum = array_sum($x['amounts']);
            return [
                'borrowers' => count($x['clients']), 'loans' => $n, 'disbursement' => round($sum, 2),
                'average' => $n ? round($sum / $n, 2) : null, 'median' => $n ? round($this->base->median($x['amounts']), 2) : null,
            ];
        };
        $new = $summ($g['new']);
        $repeat = $summ($g['repeat']);
        return [
            'new' => $new, 'repeat' => $repeat,
            'repeat_share_of_disbursement_percent' => $this->base->pct($repeat['disbursement'], $new['disbursement'] + $repeat['disbursement']),
            'repeat_share_of_borrowers_percent' => $this->base->pct($repeat['borrowers'], $new['borrowers'] + $repeat['borrowers']),
        ];
    }

    /** Borrowers by number of logical first disbursements in the retained system history (up to as-of). */
    private function customerFrequency(array $events, string $asOf, array $rows): array
    {
        $loans = [];
        foreach ($events as $e) {
            if ($e['is_first'] && $e['date'] <= $asOf && $e['client_id'] !== null) {
                $loans[$e['client_id']] = ($loans[$e['client_id']] ?? 0) + 1;
            }
        }
        $activeByClient = [];
        foreach ($rows as $r) {
            if ($r['active']) {
                $activeByClient[$r['client_id']] = ($activeByClient[$r['client_id']] ?? 0) + $r['outstanding'];
            }
        }
        $groups = ['1' => [0, 0, 0.0], '2' => [0, 0, 0.0], '3' => [0, 0, 0.0], '4+' => [0, 0, 0.0]];
        foreach ($loans as $client => $n) {
            $k = $n >= 4 ? '4+' : (string) $n;
            $groups[$k][0]++;
            if (isset($activeByClient[$client])) {
                $groups[$k][1]++;
                $groups[$k][2] += $activeByClient[$client];
            }
        }
        $out = [];
        foreach ($groups as $k => [$borrowers, $activeBorrowers, $amount]) {
            $out[] = ['loans' => $k, 'borrowers' => $borrowers, 'active_borrowers' => $activeBorrowers, 'outstanding' => round($amount, 2)];
        }
        return ['groups' => $out, 'total_borrowers' => count($loans), 'note' => 'history'];
    }

    // ---- concentration / category

    private function concentration(array $active, float $total): array
    {
        $sorted = $active;
        usort($sorted, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);
        $share = fn (int $n) => $this->base->pct(array_sum(array_column(array_slice($sorted, 0, $n), 'outstanding')), $total);
        return [
            'largest_contract' => $sorted ? ['amount' => $sorted[0]['outstanding'], 'num' => $sorted[0]['num']] : null,
            'median_contract' => $sorted ? round($this->base->median(array_column($sorted, 'outstanding')), 2) : null,
            'top_5_share' => $share(5),
            'top_10_share' => $share(10),
        ];
    }

    private function activeByCategory(array $active, float $total): array
    {
        $warnIds = Category::query()->whereIn('name', CreditActivityReportService::LTV_WARNING_CATEGORY_NAMES)->pluck('id')->all();
        $groups = [];
        foreach ($active as $r) {
            $groups[$r['category_id'] ?? 'null'][] = $r;
        }
        $out = [];
        foreach ($groups as $cid => $list) {
            $out_ = array_sum(array_column($list, 'outstanding'));
            $est = array_sum(array_column($list, 'estimated_collateral'));
            $ltvs = array_values(array_filter(array_column($list, 'origination_ltv'), fn ($v) => $v !== null));
            $out[] = [
                'category_id' => $cid === 'null' ? null : (int) $cid,
                'category' => $list[0]['category'],
                'active_contracts' => count($list),
                'outstanding' => round($out_, 2),
                'share_percent' => $this->base->pct($out_, $total),
                'estimated_collateral' => round($est, 2),
                'portfolio_ratio' => $this->base->ratio($out_, $est),
                'median_outstanding' => round($this->base->median(array_column($list, 'outstanding')), 2),
                'median_origination_ltv' => $ltvs ? round($this->base->median($ltvs), 1) : null,
                'ltv_eligible_contracts' => count($ltvs),
                'ltv_quality_warning' => $cid !== 'null' && in_array((int) $cid, $warnIds, true),
            ];
        }
        usort($out, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);
        return $out;
    }

    // ---- exceptions

    private function exceptions(array $rows, array $maturity, array $closures): array
    {
        $item = function (string $type) use ($rows) {
            $meta = self::DETAIL_TYPES[$type];
            $list = array_filter($rows, fn ($r) => $r['flags'][$type]);
            return [
                'type' => $type, 'label' => $meta['label'], 'count' => count($list),
                'severity' => count($list) > 0 ? $meta['severity'] : 'none',
                'amount' => in_array($type, ['matured_active', 'maturing_7', 'negative_outstanding', 'completed_with_balance'], true)
                    ? round(array_sum(array_column($list, 'outstanding')), 2) : null,
            ];
        };
        return [
            'business_attention' => array_map($item, ['matured_active', 'maturing_7', 'repeated_topups', 'ltv_over_100']),
            'data_quality' => array_map($item, [
                'principal_mismatch', 'missing_disbursement', 'category_conflict', 'missing_estimate',
                'negative_outstanding', 'completed_with_balance', 'active_zero_balance', 'closed_without_date',
            ]),
            'tolerance_amd' => self::MATERIALITY_AMD,
        ];
    }

    /** Facts only; the UI words them. */
    private function highlights(array $pm): array
    {
        $out = [];
        if ($pm['maturity']['within_7_days']['count'] > 0) {
            $out[] = ['type' => 'maturing_7', 'count' => $pm['maturity']['within_7_days']['count']];
        }
        if ($pm['maturity']['matured_active']['count'] > 0) {
            $out[] = ['type' => 'matured_active', 'count' => $pm['maturity']['matured_active']['count']];
        }
        if ($pm['concentration']['top_5_share'] !== null && $pm['active_contracts'] > 5) {
            $out[] = ['type' => 'top_5_share', 'share_percent' => $pm['concentration']['top_5_share']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ drill-down

    /**
     * Paginated contract list behind a headline number. $opts: page, per_page (max 100), sort, dir, search.
     * Same row dataset as the report, so the list always matches the count.
     */
    public function details(int $pawnshopId, array $period, array $events, array $titles, string $type, array $opts): array
    {
        if (!isset(self::DETAIL_TYPES[$type])) {
            throw new \InvalidArgumentException("Unknown detail type: $type");
        }
        $rows = $this->rows($pawnshopId, $period['as_of'], $period['start'], $period['end'], $events, $titles);

        $items = $type === 'topups'
            ? $this->topupItems($events, $rows, $period['start'], $period['end'])
            : array_map(fn ($r) => $this->item($r, $type), array_values(array_filter($rows, fn ($r) => $r['flags'][$type])));

        if (($search = trim((string) ($opts['search'] ?? ''))) !== '') {
            $items = array_values(array_filter($items, fn ($i) => mb_stripos((string) $i['num'], $search) !== false || mb_stripos((string) $i['customer'], $search) !== false));
        }

        $sortKey = isset(self::SORT_FIELDS[$opts['sort'] ?? '']) ? $opts['sort'] : self::DETAIL_TYPES[$type]['sort'];
        $field = self::SORT_FIELDS[$sortKey];
        $desc = ($opts['dir'] ?? null) === null
            ? in_array($sortKey, ['outstanding', 'topup_amount', 'topup_total', 'origination_ltv', 'difference'], true)
            : $opts['dir'] === 'desc';
        usort($items, function ($a, $b) use ($field, $desc) {
            $x = $a['_sort'][$field] ?? null;
            $y = $b['_sort'][$field] ?? null;
            if ($x === null || $y === null) {
                return $x === $y ? 0 : ($x === null ? 1 : -1);   // nulls last in both directions
            }
            $c = $x <=> $y;
            return $desc ? -$c : $c;
        });

        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 25)));
        $total = count($items);
        $page = max(1, min((int) ($opts['page'] ?? 1), max(1, (int) ceil($total / $perPage))));
        $slice = array_map(function ($i) {
            unset($i['_sort']);
            return $i;
        }, array_slice($items, ($page - 1) * $perPage, $perPage));

        return [
            'type' => $type, 'label' => self::DETAIL_TYPES[$type]['label'], 'as_of' => $period['as_of'],
            'sort' => $sortKey, 'dir' => $desc ? 'desc' : 'asc',
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'items' => $slice,
        ];
    }

    private function item(array $r, string $type): array
    {
        $item = [
            'contract_id' => $r['id'], 'num' => $r['num'], 'customer' => $r['customer'], 'category' => $r['category'],
            'status' => $r['status'], 'first_disbursement_date' => $r['first_date'], 'first_disbursement_amount' => $r['first_amount'],
            'outstanding' => $r['outstanding'], 'estimated_collateral' => $r['estimated_collateral'],
            'deadline' => $r['deadline'], 'days_to_maturity' => $r['days_to_maturity'], 'closed_at' => $r['closed_at'],
            'exception_type' => self::DETAIL_TYPES[$type]['group'] === 'portfolio' ? null : $type,
            'details' => null,
        ];
        $item['details'] = match ($type) {
            'principal_mismatch' => ['history_outstanding' => $r['history_outstanding'], 'contract_provided_amount' => $r['contract_provided'], 'difference' => $r['difference']],
            'ltv_over_100' => ['initial_disbursement' => $r['first_amount'], 'origination_estimate' => $r['origination_estimate'], 'origination_ltv' => $r['origination_ltv'] === null ? null : round($r['origination_ltv'], 1)],
            'missing_estimate' => ['initial_disbursement' => $r['first_amount'], 'origination_estimate' => $r['origination_estimate']],
            'repeated_topups' => ['topup_count' => $r['topup_count'], 'lifetime_topup_total' => $r['topup_total']],
            'missing_disbursement' => ['contract_provided_amount' => $r['contract_provided'], 'history_outstanding' => $r['history_outstanding']],
            'completed_with_balance', 'negative_outstanding', 'active_zero_balance' => ['outstanding' => $r['outstanding'], 'contract_provided_amount' => $r['contract_provided']],
            default => null,
        };
        if ($type === 'repeated_topups') {
            $item['topup_count'] = $r['topup_count'];
            $item['lifetime_topup_total'] = $r['topup_total'];
        }
        $item['_sort'] = [
            'num' => $r['num'], 'outstanding' => $r['outstanding'], 'deadline' => $r['deadline'], 'first_date' => $r['first_date'],
            'topup_total' => $r['topup_total'], 'origination_ltv' => $r['origination_ltv'], 'difference_abs' => $r['difference_abs'],
            'closed_at' => $r['closed_at'], 'topup_amount' => null, 'topup_date' => null,
        ];
        return $item;
    }

    private function topupItems(array $events, array $rows, string $start, string $end): array
    {
        $out = [];
        foreach ($events as $e) {
            if ($e['is_first'] || $e['date'] < $start || $e['date'] > $end) {
                continue;
            }
            $r = $rows[$e['contract_id']] ?? null;
            $item = $r ? $this->item($r, 'topups') : [
                'contract_id' => $e['contract_id'], 'num' => null, 'customer' => null, 'category' => null, 'status' => null,
                'first_disbursement_date' => null, 'first_disbursement_amount' => null, 'outstanding' => null,
                'estimated_collateral' => null, 'deadline' => null, 'days_to_maturity' => null, 'closed_at' => null,
                'exception_type' => null, 'details' => null, '_sort' => [],
            ];
            $item['topup_date'] = $e['date'];
            $item['topup_amount'] = round($e['amount'], 2);
            $item['lifetime_topup_total'] = $r['topup_total'] ?? null;
            $item['topup_count'] = $r['topup_count'] ?? null;
            $item['_sort']['topup_date'] = $e['date'];
            $item['_sort']['topup_amount'] = $e['amount'];
            $item['_sort']['num'] = $r['num'] ?? null;
            $item['_sort']['outstanding'] = $r['outstanding'] ?? null;
            $item['_sort']['deadline'] = $r['deadline'] ?? null;
            $item['_sort']['first_date'] = $r['first_date'] ?? null;
            $out[] = $item;
        }
        return $out;
    }

    // ------------------------------------------------------------------ helpers

    private function validDate(?string $d): ?string
    {
        return ($d === null || $d === '' || $d < '1990-01-01') ? null : substr($d, 0, 10);
    }

    /** Whole days from $a to $b (b - a), timezone-independent. */
    private function daysBetween(string $a, string $b): int
    {
        return (int) round((strtotime($b . ' UTC') - strtotime($a . ' UTC')) / 86400);
    }

    private function customerName(object $r): string
    {
        if ($r->client_type === 'legal' && trim((string) $r->client_company) !== '') {
            return trim($r->client_company);
        }
        $name = trim(trim((string) $r->client_surname) . ' ' . trim((string) $r->client_name));
        return $name !== '' ? $name : ($r->client_company ? trim($r->client_company) : '#' . $r->client_id);
    }
}
