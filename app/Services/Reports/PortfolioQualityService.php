<?php

namespace App\Services\Reports;

use App\Exports\Reports\V06Export;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Tranche 4 of /reports: PORTFOLIO QUALITY, DELINQUENCY AND COLLECTIONS (payment performance, not maturity).
 *
 * REPAYMENT MODEL (audited)
 *  - payments (type 'regular') = the schedule: one row per instalment, `date` = due date, `principal_payment` and
 *    `interest_payment` = scheduled amounts. payments.amount/status/paid/remaining describe the CURRENT state and are
 *    never used for history. Type 'penalty' rows are separate penalty documents, 'full' rows are full-repayment records.
 *  - payment_entries = what was actually paid (date, principal_amount, interest_amount, penalty_amount) linked to the
 *    instalment (payment_id) it settles; this is the only proof of payment. Prepayment entries are linked to the
 *    instalment they pre-pay, so they reduce that instalment's unpaid amount (no false overdue).
 *  - Schedules are rebuilt by soft-deleting rows and creating new ones, hence rows are judged by created_at/deleted_at
 *    relative to the as-of date. Entries are not soft-deleted; an entry removed by a reversal cannot be reconstructed.
 *
 * CANONICAL DEFINITIONS (one engine: OverdueScheduleService::analyse(), the same rule set as Form 6 row 1.3)
 *  - scheduled_due = principal_payment + interest_payment of an instalment (penalties/fees are not part of the schedule,
 *    so no fee split is returned). unpaid = max(0, scheduled - entries dated <= D) per component.
 *  - An instalment is overdue at D when due date < D (due on D is not overdue) and unpaid > OverdueScheduleService::NOISE.
 *  - A contract is overdue at D when it is ACTIVE at D (Tranche 3 definition) and its overdue principal + interest is at
 *    least OVERDUE_MIN_AMOUNT (the Form 6 constant, equal to Contract::is_overdue's 1,000 AMD). Below it counts as current.
 *  - DPD = D - earliest overdue instalment due date. contract.deadline is never used.
 *  - PAR X = outstanding principal of active contracts with DPD > X / active outstanding principal (whole contract exposure).
 *  - Collection rate = collected / scheduled for the DUE COHORT: instalments due inside the period (not on contracts closed
 *    before the due date), collected = entries dated <= period end capped at the instalment's scheduled component.
 *  - Historical D: only schedule rows existing at D and entries dated <= D are used; later payments do not change it.
 */
class PortfolioQualityService
{
    /** Contract-level materiality (AMD): the existing Form 6 / Contract::is_overdue threshold. */
    public const OVERDUE_MIN_AMOUNT = V06Export::V06_OVERDUE_MIN_AMOUNT;

    /** [key, min dpd, max dpd|null] */
    public const BUCKETS = [['current', 0, 0], ['1-7', 1, 7], ['8-30', 8, 30], ['31-60', 31, 60], ['61-90', 61, 90], ['91+', 91, null]];

    public const DETAIL_TYPES = [
        'overdue_all' => 'Ժամկետանց պայմանագրեր',
        'dpd_1_7' => '1–7 օր ժամկետանց',
        'dpd_8_30' => '8–30 օր ժամկետանց',
        'dpd_31_60' => '31–60 օր ժամկետանց',
        'dpd_61_90' => '61–90 օր ժամկետանց',
        'dpd_91_plus' => '91+ օր ժամկետանց',
        'newly_overdue' => 'Նոր ժամկետանց դարձած',
        'cured' => 'Կարգավորված (վերադարձել են ժամկետի մեջ)',
        'worsened' => 'Վատացած ժամկետանցում',
        'improved' => 'Բարելավված ժամկետանցում',
        'multi_overdue_borrower' => 'Մեկից ավելի ժամկետանց պայմանագրով վարկառուներ',
    ];
    public const SORT_FIELDS = ['num' => 'num', 'outstanding' => 'outstanding', 'overdue_amount' => 'overdue_amount', 'dpd' => 'dpd',
        'earliest_due_date' => 'earliest_due', 'deadline' => 'deadline'];

    private const CLOSED_STATUSES = ['completed', 'executed'];
    private const AGE_LABELS = [['0-30', 0, 30], ['31-90', 31, 90], ['91-180', 91, 180], ['181-365', 181, 365], ['365+', 366, null]];

    /** Validation switch: ignore payment_entries and rebuild every payment from deals (used to test deals against entries). */
    public bool $forceDeals = false;

    private PaymentHistoryResolver $history;

    public function __construct(private CreditActivityReportService $base, private PortfolioManagementService $pm, ?PaymentHistoryResolver $history = null)
    {
        $this->history = $history ?? new PaymentHistoryResolver();
    }

    // ------------------------------------------------------------------ public entry points

    public function build(int $pawnshopId, int $month, int $year, int $trendMonths = 6): array
    {
        $trendMonths = $trendMonths === 12 ? 12 : 6;
        $a = $this->analyse($pawnshopId, $month, $year, $trendMonths);
        $ctx = $a['ctx'];
        $D = $a['end'];
        $O = $a['opening'];
        $snapD = $a['snaps'][$D];
        $snapO = $a['snaps'][$O];
        $m = $this->metrics($snapD, $ctx, $D);
        $mO = $this->metrics($snapO, $ctx, $O);

        // collections
        $cohort = $this->cohort($ctx, $a['start'], $D);
        $overdueCollected = $this->overdueCollected($ctx, $snapO, $O, $D);
        $transitions = $this->transitions($snapO, $snapD, $ctx, $O, $D);
        $loaded = $ctx['history'];
        $control = PaymentHistoryResolver::control($loaded, '0000-00-00', '9999-12-31');
        $deal = $this->dealReliable($control);
        $source = $cohort['sources'];
        $overSource = $this->sources($ctx, $O, $D);

        // opening + new - collected + other = closing
        $newOverdue = 0.0;
        foreach ($snapD as $cid => $s) {
            if ($s['overdue']) {
                foreach ($s['unpaid'] as $u) {
                    if ($u['due'] >= $O) {
                        $newOverdue += $u['amount'];
                    }
                }
            }
        }
        $opening = $mO['overdue_amount'];
        $closing = $m['overdue_amount'];
        $rollforward = [
            'opening_date' => $O, 'opening_overdue' => round($opening, 2), 'new_overdue' => round($newOverdue, 2),
            'collected' => round($overdueCollected, 2), 'closing_overdue' => round($closing, 2),
            'other_adjustments' => round($closing - ($opening + $newOverdue - $overdueCollected), 2),
            'recovery_rate_percent' => $this->base->pct($overdueCollected, $opening),
        ];

        // trend
        $trend = [];
        foreach ($a['months'] as $mo) {
            $tm = $this->metrics($a['snaps'][$mo['end']], $ctx, $mo['end']);
            $tc = $this->cohort($ctx, $mo['start'], $mo['end']);
            $trend[] = ['month' => $mo['month'], 'as_of' => $mo['end'], 'overdue_contracts' => $tm['overdue_contracts'], 'overdue_amount' => round($tm['overdue_amount'], 2),
                'par_1' => $tm['par']['1'], 'par_30' => $tm['par']['30'], 'par_90' => $tm['par']['90'],
                'collection_due' => round($tc['due'], 2), 'collection_collected' => round($tc['collected'], 2), 'collection_rate_percent' => $tc['rate'],
                'payment_source' => $tc['sources'], 'reliability' => $this->reliability($tc['sources'], $tc['rows_total_only'] === 0, $tm['split_known'], $deal)];
        }

        return [
            'period' => ['start' => $a['start'], 'end' => $D, 'as_of' => $D, 'opening_date' => $O, 'is_mtd' => $a['is_mtd']],
            'definitions' => ['overdue_min_amount_amd' => self::OVERDUE_MIN_AMOUNT, 'noise_amd' => OverdueScheduleService::NOISE,
                'dpd' => 'as_of - earliest overdue instalment due date', 'par' => 'whole outstanding principal of contracts with DPD > X / active outstanding'],
            'portfolio_quality' => [
                'as_of' => $D,
                'active_contracts' => $m['active_contracts'], 'active_outstanding' => round($m['active_outstanding'], 2),
                'overdue_contracts' => $m['overdue_contracts'], 'overdue_borrowers' => $m['overdue_borrowers'],
                'overdue_amount' => round($m['overdue_amount'], 2),
                'overdue_principal' => $m['split_known'] ? round($m['overdue_principal'], 2) : null, 'overdue_interest' => $m['split_known'] ? round($m['overdue_interest'], 2) : null,
                'overdue_outstanding' => round($m['overdue_outstanding'], 2),
                'par' => $m['par'], 'par_amount' => $m['par_amount'],
                'previous' => ['as_of' => $O, 'overdue_contracts' => $mO['overdue_contracts'], 'overdue_amount' => round($mO['overdue_amount'], 2), 'par' => $mO['par']],
                'median_dpd' => $m['median_dpd'], 'max_dpd' => $m['max_dpd'],
                'buckets' => $m['buckets'],
                'by_category' => $m['by_category'],
                'by_contract_age' => $m['by_age'],
                'borrowers' => ['overdue' => $m['overdue_borrowers'], 'multiple_overdue_contracts' => $m['multi_borrowers']],
                'concentration' => $m['concentration'],
                'maturity_vs_delinquency' => $m['maturity_cross'],
            ],
            'collections' => [
                'cohort' => ['start' => $a['start'], 'end' => $D, 'contracts' => $cohort['contracts'], 'instalments' => $cohort['instalments'],
                    'due' => round($cohort['due'], 2), 'collected' => round($cohort['collected'], 2), 'unpaid' => round($cohort['due'] - $cohort['collected'], 2),
                    'principal_due' => round($cohort['p_due'], 2), 'principal_collected' => $cohort['rows_total_only'] === 0 ? round($cohort['p_paid'], 2) : null,
                    'interest_due' => round($cohort['i_due'], 2), 'interest_collected' => $cohort['rows_total_only'] === 0 ? round($cohort['i_paid'], 2) : null,
                    'payment_source' => $source, 'reliability' => $this->reliability($source, $cohort['rows_total_only'] === 0, $m['split_known'], $deal),
                    'collection_rate_percent' => $cohort['rate'],
                    'note' => 'Collected = payments dated <= period end (payment_entries, or deal allocations before they existed) applied to instalments due in the period, capped at the scheduled amount; includes early payments, excludes penalties.'],
                'overdue_recovery' => ['opening_overdue' => round($opening, 2), 'collected' => round($overdueCollected, 2), 'recovery_rate_percent' => $rollforward['recovery_rate_percent']],
                'rollforward' => $rollforward + ['payment_source' => $overSource],
                'transitions' => $transitions['summary'],
                'source_reconciliation' => [
                    'payment_entry_source_from' => $loaded['entry_source_from'],
                    'rule' => 'per deal: has payment_entries -> entries; otherwise deal_actions (recorded schedule allocation). Never both.',
                    'period_deals' => PaymentHistoryResolver::classification($loaded, $a['start'], $D),
                    'period_control' => PaymentHistoryResolver::control($loaded, $a['start'], $D),
                    'overall_control' => $control,
                    'deal_reconstruction_trusted' => $deal,
                ],
            ],
            'trend' => $trend,
            'data_quality' => $this->dataQuality($ctx, $D, $snapD),
        ];
    }

    /** Paginated contracts behind a number. */
    public function details(int $pawnshopId, int $month, int $year, string $type, array $opts = []): array
    {
        if (!isset(self::DETAIL_TYPES[$type])) {
            throw new \InvalidArgumentException("Unknown detail type: $type");
        }
        $a = $this->analyse($pawnshopId, $month, $year, 0);
        $ctx = $a['ctx'];
        $D = $a['end'];
        $O = $a['opening'];
        $sD = $a['snaps'][$D];
        $sO = $a['snaps'][$O];
        $tr = $this->transitions($sO, $sD, $ctx, $O, $D);

        $ids = match (true) {
            $type === 'overdue_all' => array_keys(array_filter($sD, fn ($s) => $s['overdue'])),
            str_starts_with($type, 'dpd_') => array_keys(array_filter($sD, fn ($s) => $s['overdue'] && $s['bucket'] === ['dpd_1_7' => '1-7', 'dpd_8_30' => '8-30', 'dpd_31_60' => '31-60', 'dpd_61_90' => '61-90', 'dpd_91_plus' => '91+'][$type])),
            $type === 'newly_overdue' => $tr['ids']['newly_overdue'],
            $type === 'cured' => $tr['ids']['cured'],
            $type === 'worsened' => $tr['ids']['worsened'],
            $type === 'improved' => $tr['ids']['improved'],
            default => $this->multiOverdueIds($sD, $ctx),
        };

        $items = [];
        foreach ($ids as $cid) {
            $c = $ctx['contracts'][$cid];
            $d = $sD[$cid] ?? null;
            $o = $sO[$cid] ?? null;
            $items[] = [
                'contract_id' => $cid, 'num' => $c['num'], 'customer' => $c['customer'], 'category' => $c['category'], 'status' => $c['status'],
                'outstanding' => round($d['outstanding'] ?? ($o['outstanding'] ?? 0), 2),
                'overdue_amount' => round($d['overdue_amount'] ?? 0, 2),
                'earliest_due_date' => $d['earliest_due'] ?? null, 'dpd' => $d['dpd'] ?? 0, 'bucket' => $d['bucket'] ?? 'closed',
                'deadline' => $c['deadline'], 'last_payment_date' => $this->lastPayment($ctx, $cid, $D), 'next_due_date' => $this->nextDue($ctx, $cid, $D),
                'opening_bucket' => $o['bucket'] ?? 'not active', 'closing_bucket' => $d ? $d['bucket'] : 'not active',
                'opening_overdue_amount' => round($o['overdue_amount'] ?? 0, 2), 'closing_overdue_amount' => round($d['overdue_amount'] ?? 0, 2),
                '_sort' => ['num' => $c['num'], 'outstanding' => $d['outstanding'] ?? 0, 'overdue_amount' => $d['overdue_amount'] ?? 0, 'dpd' => $d['dpd'] ?? 0,
                    'earliest_due' => $d['earliest_due'] ?? null, 'deadline' => $c['deadline']],
            ];
        }

        if (($q = trim((string) ($opts['search'] ?? ''))) !== '') {
            $items = array_values(array_filter($items, fn ($i) => mb_stripos((string) $i['num'], $q) !== false || mb_stripos((string) $i['customer'], $q) !== false));
        }
        $sortKey = isset(self::SORT_FIELDS[$opts['sort'] ?? '']) ? $opts['sort'] : 'dpd';
        $field = self::SORT_FIELDS[$sortKey];
        $desc = ($opts['dir'] ?? null) === null ? $sortKey !== 'num' && $sortKey !== 'earliest_due_date' && $sortKey !== 'deadline' : $opts['dir'] === 'desc';
        usort($items, function ($x, $y) use ($field, $desc) {
            $a1 = $x['_sort'][$field];
            $b1 = $y['_sort'][$field];
            if ($a1 === null || $b1 === null) {
                return $a1 === $b1 ? 0 : ($a1 === null ? 1 : -1);
            }
            return ($desc ? -1 : 1) * ($a1 <=> $b1);
        });

        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 25)));
        $total = count($items);
        $page = max(1, min((int) ($opts['page'] ?? 1), max(1, (int) ceil($total / $perPage))));
        return [
            'type' => $type, 'label' => self::DETAIL_TYPES[$type], 'as_of' => $D, 'opening_date' => $O,
            'sort' => $sortKey, 'dir' => $desc ? 'desc' : 'asc', 'page' => $page, 'per_page' => $perPage, 'total' => $total,
            'items' => array_map(function ($i) {
                unset($i['_sort']);
                return $i;
            }, array_slice($items, ($page - 1) * $perPage, $perPage)),
        ];
    }

    // ------------------------------------------------------------------ dataset

    /** Loads everything once (4-5 queries) and computes one snapshot per needed date. */
    private function analyse(int $pawnshopId, int $month, int $year, int $trendMonths): array
    {
        $p = $this->base->resolvePeriods($month, $year);
        $D = $p['end']->toDateString();
        $start = $p['start']->toDateString();
        $O = $p['start']->copy()->subDay()->toDateString();

        $months = [];
        for ($i = $trendMonths - 1; $i >= 0; $i--) {
            $ms = $p['start']->copy()->subMonthsNoOverflow($i);
            $me = $i === 0 ? $D : $ms->copy()->endOfMonth()->toDateString();
            $months[] = ['month' => $ms->format('Y-m'), 'start' => $ms->toDateString(), 'end' => $me];
        }

        $events = $this->base->disbursementEvents($pawnshopId, '1900-01-01', $D);
        $firstDate = null;
        foreach ($events as $e) {
            if ($e['is_first'] && ($firstDate === null || $e['date'] < $firstDate)) {
                $firstDate = $e['date'];
            }
        }
        $months = array_values(array_filter($months, fn ($m) => $firstDate !== null && substr($firstDate, 0, 7) <= $m['month']));

        $dates = array_values(array_unique(array_merge([$O, $D], array_column($months, 'end'))));
        sort($dates);
        $ctx = $this->context($pawnshopId, $dates, $events, Category::query()->pluck('title', 'id')->all());
        $snaps = [];
        foreach ($dates as $d) {
            $snaps[$d] = $this->snapshot($ctx, $d);
        }
        return ['ctx' => $ctx, 'snaps' => $snaps, 'start' => $start, 'end' => $D, 'opening' => $O, 'months' => $months, 'is_mtd' => $p['is_mtd']];
    }

    private function context(int $pawnshopId, array $dates, array $events, array $titles): array
    {
        $bind = ['pid' => $pawnshopId];
        $cols = [];
        $signed = "CASE h.type WHEN 'in' THEN h.amount WHEN 'out' THEN -h.amount ELSE 0 END";
        foreach ($dates as $i => $d) {
            $cols[] = "SUM(CASE WHEN h.date <= :d$i THEN $signed ELSE 0 END) AS p$i";
            $bind["d$i"] = $d;
        }
        $prov = [];
        foreach (DB::select("
            SELECT h.contract_id, " . implode(', ', $cols) . "
            FROM contract_amount_histories h
            WHERE h.pawnshop_id = :pid AND h.deleted_at IS NULL AND h.amount_type = 'provided_amount'
            GROUP BY h.contract_id", $bind) as $r) {
            foreach ($dates as $i => $d) {
                $prov[$r->contract_id][$d] = (float) $r->{"p$i"};
            }
        }

        $first = [];
        foreach ($events as $e) {
            if ($e['is_first']) {
                $first[$e['contract_id']] = $e;
            }
        }

        $contracts = [];
        foreach (DB::select("
            SELECT c.id, c.num, c.client_id, c.status, c.deadline, c.closed_at, c.category_id,
                   cl.type AS client_type, cl.name AS client_name, cl.surname AS client_surname, cl.company_name AS client_company
            FROM contracts c LEFT JOIN clients cl ON cl.id = c.client_id
            WHERE c.pawnshop_id = :pid AND c.deleted_at IS NULL", ['pid' => $pawnshopId]) as $r) {
            $name = $r->client_type === 'legal' && trim((string) $r->client_company) !== '' ? trim($r->client_company)
                : trim(trim((string) $r->client_surname) . ' ' . trim((string) $r->client_name));
            $contracts[(int) $r->id] = [
                'id' => (int) $r->id, 'num' => $r->num, 'client_id' => (int) $r->client_id, 'customer' => $name !== '' ? $name : '#' . $r->client_id,
                'status' => $r->status, 'deadline' => $r->deadline && $r->deadline >= '1990-01-01' ? substr($r->deadline, 0, 10) : null,
                'closed_at' => $r->closed_at && $r->closed_at >= '1990-01-01' ? substr($r->closed_at, 0, 10) : null,
                'category_id' => $r->category_id === null ? null : (int) $r->category_id,
                'category' => $r->category_id === null ? 'Առանց տեսակի' : ($titles[$r->category_id] ?? "#{$r->category_id}"),
                'first' => $first[(int) $r->id] ?? null, 'prov' => $prov[(int) $r->id] ?? [],
            ];
        }

        $minEnd = $dates[0] . ' 23:59:59';
        $maxEnd = end($dates) . ' 23:59:59';
        $sched = [];
        $byPayment = [];
        foreach (DB::select("
            SELECT p.id, p.contract_id, p.date, p.principal_payment, p.interest_payment, p.status, p.created_at, p.deleted_at
            FROM payments p JOIN contracts c ON c.id = p.contract_id
            WHERE c.pawnshop_id = :pid AND c.deleted_at IS NULL AND p.type = 'regular'
              AND p.created_at <= :maxEnd AND (p.deleted_at IS NULL OR p.deleted_at > :minEnd)", ['pid' => $pawnshopId, 'maxEnd' => $maxEnd, 'minEnd' => $minEnd]) as $r) {
            $row = ['id' => (int) $r->id, 'date' => substr((string) $r->date, 0, 10), 'p' => (float) $r->principal_payment, 'i' => (float) $r->interest_payment,
                'status' => $r->status, 'created' => $r->created_at, 'deleted' => $r->deleted_at, 'entries' => []];
            $sched[(int) $r->contract_id][] = $row;
        }
        foreach ($sched as $cid => &$rows) {
            foreach ($rows as $k => &$row) {
                $byPayment[$row['id']] = [$cid, $k];
            }
            unset($row);
        }
        unset($rows);
        foreach (DB::select("
            SELECT pe.payment_id, pe.deal_id, DATE(pe.date) AS d, pe.principal_amount, pe.interest_amount
            FROM payment_entries pe JOIN contracts c ON c.id = pe.contract_id
            WHERE c.pawnshop_id = :pid AND c.deleted_at IS NULL", ['pid' => $pawnshopId]) as $r) {
            if (isset($byPayment[$r->payment_id])) {
                [$cid, $k] = $byPayment[$r->payment_id];
                $sched[$cid][$k]['entries'][] = [$r->d, (float) $r->principal_amount, (float) $r->interest_amount, $r->deal_id === null ? null : (int) $r->deal_id];
            }
        }

        $loaded = $this->history->load($pawnshopId);
        $attach = $this->history->attach($sched, $byPayment, $loaded, $this->forceDeals);

        return ['contracts' => $contracts, 'sched' => $sched, 'dates' => $dates, 'history' => $loaded, 'history_stats' => $attach];
    }

    /** Active contracts at $d (Tranche 3 rule) with their overdue state. Pure PHP over the loaded context. */
    private function snapshot(array $ctx, string $d): array
    {
        $tol = PortfolioManagementService::MATERIALITY_AMD;
        $out = [];
        foreach ($ctx['contracts'] as $cid => $c) {
            $f = $c['first'];
            if ($f === null || $f['amount'] <= 0 || $f['date'] > $d) {
                continue;
            }
            $closed = in_array($c['status'], self::CLOSED_STATUSES, true);
            if ($closed && ($c['closed_at'] === null || $c['closed_at'] <= $d)) {
                continue;
            }
            $prov = $c['prov'][$d] ?? 0.0;
            if ($prov <= $tol) {
                continue;
            }
            $inst = $this->installments($ctx['sched'][$cid] ?? [], $d);
            $res = OverdueScheduleService::analyse($inst, $d);
            $p = array_sum(array_column($res['overdue'], 'principal'));
            $i = array_sum(array_column($res['overdue'], 'interest'));
            $total = $p + $i;
            $overdue = $total >= max(self::OVERDUE_MIN_AMOUNT, OverdueScheduleService::NOISE);
            $dpd = $overdue ? (int) $res['overdue'][0]['days_overdue'] : 0;
            $unpaid = [];
            $splitKnown = true;
            if ($overdue) {
                $unknown = array_column(array_filter($inst, fn ($r) => !$r['split_known']), 'id');
                foreach ($res['overdue'] as $o) {
                    $unpaid[$o['payment_id']] = ['due' => $o['due_date'], 'amount' => $o['principal'] + $o['interest']];
                    $splitKnown = $splitKnown && !in_array($o['payment_id'], $unknown, true);
                }
            }
            $out[$cid] = [
                'outstanding' => $prov, 'overdue' => $overdue, 'overdue_amount' => $overdue ? $total : 0.0,
                'overdue_principal' => $overdue ? $p : 0.0, 'overdue_interest' => $overdue ? $i : 0.0,
                'sub_threshold' => !$overdue && $total > OverdueScheduleService::NOISE,
                'dpd' => $dpd, 'bucket' => $this->bucket($dpd), 'earliest_due' => $overdue ? $res['overdue'][0]['due_date'] : null,
                'unpaid' => $unpaid, 'split_known' => $splitKnown, 'has_schedule' => $inst !== [],
            ];
        }
        return $out;
    }

    /**
     * First deal-derived payment that ZEROED the row in place, optionally only those after $after. Its old_p/old_i are the
     * row's exact state just before that payment.
     */
    private function zeroingEvent(array $row, ?string $after = null): ?array
    {
        $best = null;
        foreach ($row['deal_events'] ?? [] as $e) {
            if ($e['zeroing'] && $e['old_p'] !== null && ($after === null || $e['date'] > $after) && ($best === null || $e['date'] < $best['date'])) {
                $best = $e;
            }
        }
        return $best;
    }

    /** Scheduled principal/interest of a row at the start of its life: the state before its first zeroing payment, else current. */
    private function originalSchedule(array $row): array
    {
        $z = $this->zeroingEvent($row);
        return $z ? [$z['old_p'], $z['old_i']] : [$row['p'], $row['i']];
    }

    /** Row state on $d: the state recorded just before the first zeroing payment after $d, else the current state. */
    private function stateAt(array $row, string $d): array
    {
        $z = $this->zeroingEvent($row, $d);
        return $z ? [$z['old_p'], $z['old_i']] : [$row['p'], $row['i']];
    }

    /**
     * Payments applied to a row up to $d from both sources, as [principal, interest, unsplit, lastDate].
     * Zeroing deal payments are counted only when $withZeroing (they are already netted out of the current row state, which
     * is what the as-of state uses); the cohort counts them against the original schedule. Entries carry the split; a deal
     * payment carries it only when it settled the row completely, otherwise it is an exact TOTAL with an unknown split.
     */
    private function paidUpTo(array $row, string $d, bool $withZeroing): array
    {
        $ep = $ei = $un = 0.0;
        $last = null;
        foreach ($row['entries'] as [$ed, $p, $i]) {
            if ($ed <= $d) {
                $ep += $p;
                $ei += $i;
            }
            if ($p + $i > 0 && ($last === null || $ed > $last)) {
                $last = $ed;
            }
        }
        foreach ($row['deal_events'] ?? [] as $e) {
            if ($e['date'] <= $d && ($withZeroing || !$e['zeroing'])) {
                if ($e['p'] !== null) {
                    $ep += $e['p'];
                    $ei += $e['i'];
                } else {
                    $un += $e['applied'];
                }
            }
            if ($e['applied'] > 0 && ($last === null || $e['date'] > $last)) {
                $last = $e['date'];
            }
        }
        return [$ep, $ei, $un, $last];
    }

    /** Instalments as they stood on $d, in the shape OverdueScheduleService::analyse() expects (+ 'split_known'). */
    private function installments(array $rows, string $d): array
    {
        $end = $d . ' 23:59:59';
        $out = [];
        foreach ($rows as $r) {
            if ($r['created'] > $end || ($r['deleted'] !== null && $r['deleted'] <= $end)) {
                continue;
            }
            [$p0, $i0] = $this->stateAt($r, $d);
            [$ep, $ei, $un, $last] = $this->paidUpTo($r, $d, false);
            $row = ['id' => $r['id'], 'date' => $r['date'], 'principal_payment' => $p0, 'interest_payment' => $i0, 'status' => $r['status'],
                'entry_principal' => $ep, 'entry_interest' => $ei, 'last_entry_date' => $last, 'split_known' => $un <= 0];
            if ($un > 0) {   // total-only mode: exact unpaid total, no principal/interest split
                $row['principal_payment'] = $p0 + $i0;
                $row['interest_payment'] = 0.0;
                $row['entry_principal'] = $ep + $ei + $un;
                $row['entry_interest'] = 0.0;
            }
            $out[] = $row;
        }
        return $out;
    }

    private function bucket(int $dpd): string
    {
        foreach (self::BUCKETS as [$k, $lo, $hi]) {
            if ($dpd >= $lo && ($hi === null || $dpd <= $hi)) {
                return $k;
            }
        }
        return '91+';
    }

    private function bucketIndex(string $b): int
    {
        foreach (self::BUCKETS as $i => $x) {
            if ($x[0] === $b) {
                return $i;
            }
        }
        return 0;
    }

    // ------------------------------------------------------------------ metrics

    private function metrics(array $snap, array $ctx, string $d): array
    {
        $activeOut = array_sum(array_column($snap, 'outstanding'));
        $over = array_filter($snap, fn ($s) => $s['overdue']);
        $overOut = array_sum(array_column($over, 'outstanding'));
        $overAmt = array_sum(array_column($over, 'overdue_amount'));
        $par = [];
        $parAmt = [];
        foreach ([1 => 0, 30 => 30, 90 => 90] as $label => $x) {
            $amt = array_sum(array_column(array_filter($snap, fn ($s) => $s['overdue'] && $s['dpd'] > $x), 'outstanding'));
            $par[(string) $label] = $this->base->pct($amt, $activeOut);
            $parAmt[(string) $label] = round($amt, 2);
        }
        $dpds = array_column($over, 'dpd');

        $buckets = [];
        foreach (self::BUCKETS as [$k]) {
            $in = array_filter($snap, fn ($s) => $s['bucket'] === $k);
            $out = array_sum(array_column($in, 'outstanding'));
            $oa = array_sum(array_column($in, 'overdue_amount'));
            $clients = array_unique(array_map(fn ($cid) => $ctx['contracts'][$cid]['client_id'], array_keys($in)));
            $buckets[] = ['bucket' => $k, 'contracts' => count($in), 'borrowers' => count($clients), 'outstanding' => round($out, 2), 'overdue_amount' => round($oa, 2),
                'share_of_active_percent' => $this->base->pct($out, $activeOut),
                'share_of_overdue_percent' => $k === 'current' ? null : $this->base->pct($out, $overOut)];
        }

        $byCat = [];
        foreach ($snap as $cid => $s) {
            $byCat[$ctx['contracts'][$cid]['category_id'] ?? 'null'][] = $s + ['cid' => $cid];
        }
        ksort($byCat);
        $cats = [];
        foreach ($byCat as $k => $list) {
            $o = array_filter($list, fn ($s) => $s['overdue']);
            $out = array_sum(array_column($list, 'outstanding'));
            $cats[] = [
                'category_id' => $k === 'null' ? null : (int) $k, 'category' => $ctx['contracts'][$list[0]['cid']]['category'],
                'active_outstanding' => round($out, 2), 'active_contracts' => count($list), 'overdue_contracts' => count($o),
                'overdue_amount' => round(array_sum(array_column($o, 'overdue_amount')), 2),
                'par_1' => $this->base->pct(array_sum(array_column($o, 'outstanding')), $out),
                'par_30' => $this->base->pct(array_sum(array_column(array_filter($o, fn ($s) => $s['dpd'] > 30), 'outstanding')), $out),
                'par_90' => $this->base->pct(array_sum(array_column(array_filter($o, fn ($s) => $s['dpd'] > 90), 'outstanding')), $out),
                'median_dpd' => $o ? round($this->base->median(array_column($o, 'dpd')), 1) : null, 'max_dpd' => $o ? max(array_column($o, 'dpd')) : null,
            ];
        }

        $age = [];
        foreach (self::AGE_LABELS as [$label, $lo, $hi]) {
            $age[$label] = ['bucket' => $label, 'active_contracts' => 0, 'overdue_contracts' => 0];
        }
        foreach ($snap as $cid => $s) {
            $days = (int) round((strtotime($d . ' UTC') - strtotime($ctx['contracts'][$cid]['first']['date'] . ' UTC')) / 86400);
            foreach (self::AGE_LABELS as [$label, $lo, $hi]) {
                if ($days >= $lo && ($hi === null || $days <= $hi)) {
                    $age[$label]['active_contracts']++;
                    $age[$label]['overdue_contracts'] += $s['overdue'] ? 1 : 0;
                    break;
                }
            }
        }
        foreach ($age as &$a) {
            $a['overdue_rate_percent'] = $this->base->pct($a['overdue_contracts'], $a['active_contracts']);
        }
        unset($a);

        $perClient = [];
        foreach ($over as $cid => $s) {
            $perClient[$ctx['contracts'][$cid]['client_id']][] = $s['outstanding'];
        }
        $multi = array_filter($perClient, fn ($l) => count($l) > 1);

        $ov = array_column($over, 'outstanding');
        rsort($ov);

        $matured = array_filter($snap, fn ($s, $cid) => ($ctx['contracts'][$cid]['deadline'] ?? '9999') < $d, ARRAY_FILTER_USE_BOTH);

        return [
            'active_contracts' => count($snap), 'active_outstanding' => $activeOut,
            'overdue_contracts' => count($over), 'overdue_borrowers' => count($perClient), 'multi_borrowers' => count($multi),
            'split_known' => count(array_filter($over, fn ($s) => !$s['split_known'])) === 0,
            'overdue_amount' => $overAmt, 'overdue_principal' => array_sum(array_column($over, 'overdue_principal')), 'overdue_interest' => array_sum(array_column($over, 'overdue_interest')),
            'overdue_outstanding' => $overOut, 'par' => $par, 'par_amount' => $parAmt,
            'median_dpd' => $dpds ? round($this->base->median($dpds), 1) : null, 'max_dpd' => $dpds ? max($dpds) : null,
            'buckets' => $buckets, 'by_category' => $cats, 'by_age' => array_values($age),
            'concentration' => [
                'largest_overdue_outstanding' => $ov ? round($ov[0], 2) : null,
                'top_5_share_percent' => $this->base->pct(array_sum(array_slice($ov, 0, 5)), $overOut),
                'top_10_share_percent' => $this->base->pct(array_sum(array_slice($ov, 0, 10)), $overOut),
            ],
            'maturity_cross' => [
                'matured_and_overdue' => count(array_filter($matured, fn ($s) => $s['overdue'])),
                'matured_not_overdue' => count(array_filter($matured, fn ($s) => !$s['overdue'])),
                'overdue_not_matured' => count(array_filter($over, fn ($s, $cid) => !isset($matured[$cid]), ARRAY_FILTER_USE_BOTH)),
            ],
        ];
    }

    private function multiOverdueIds(array $snap, array $ctx): array
    {
        $by = [];
        foreach ($snap as $cid => $s) {
            if ($s['overdue']) {
                $by[$ctx['contracts'][$cid]['client_id']][] = $cid;
            }
        }
        return array_merge([], ...array_values(array_filter($by, fn ($l) => count($l) > 1)));
    }

    // ------------------------------------------------------------------ collections

    /**
     * Due cohort: instalments due in [start, end]; collected = payments applied by the period end (entries + deal-derived),
     * capped per component when the split is known and in total otherwise.
     * 'rows_total_only' > 0 means the principal/interest split of the collected amount is not available (partial).
     */
    private function cohort(array $ctx, string $start, string $end): array
    {
        $r = ['contracts' => 0, 'instalments' => 0, 'due' => 0.0, 'collected' => 0.0, 'p_due' => 0.0, 'p_paid' => 0.0, 'i_due' => 0.0, 'i_paid' => 0.0,
            'rows_total_only' => 0, 'sources' => []];
        $endTs = $end . ' 23:59:59';
        foreach ($ctx['sched'] as $cid => $rows) {
            $c = $ctx['contracts'][$cid] ?? null;
            if (!$c) {
                continue;
            }
            $closed = in_array($c['status'], self::CLOSED_STATUSES, true);
            $any = false;
            foreach ($rows as $row) {
                if ($row['date'] < $start || $row['date'] > $end || $row['created'] > $endTs || ($row['deleted'] !== null && $row['deleted'] <= $endTs)) {
                    continue;
                }
                if ($closed && ($c['closed_at'] === null || $row['date'] > $c['closed_at'])) {
                    continue;   // obligation extinguished by the contract's closure
                }
                [$rp, $ri] = $this->originalSchedule($row);
                [$ep, $ei, $un] = $this->paidUpTo($row, $end, true);
                $sched = $rp + $ri;
                if ($un > 0) {
                    $paid = min($sched, $ep + $ei + $un);
                    $r['p_due'] += $rp;
                    $r['i_due'] += $ri;
                    $r['rows_total_only']++;
                    $r['collected'] += $paid;
                } else {
                    $pp = min($rp, $ep);
                    $ip = min($ri, $ei);
                    $r['p_due'] += $rp;
                    $r['i_due'] += $ri;
                    $r['p_paid'] += $pp;
                    $r['i_paid'] += $ip;
                    $r['collected'] += $pp + $ip;
                }
                $r['instalments']++;
                $any = true;
            }
            $r['contracts'] += $any ? 1 : 0;
        }
        $r['due'] = $r['p_due'] + $r['i_due'];
        $r['rate'] = $this->base->pct($r['collected'], $r['due']);
        $r['sources'] = $this->sources($ctx, $start, $end);
        return $r;
    }

    /** Which systems evidence the payments dated in [start, end]: payment_entries, deals, mixed or none. */
    private function sources(array $ctx, string $start, string $end): string
    {
        $entries = $deals = false;
        foreach ($ctx['sched'] as $rows) {
            foreach ($rows as $row) {
                foreach ($row['entries'] as [$ed, $p, $i]) {
                    $entries = $entries || ($ed >= $start && $ed <= $end && $p + $i > 0);
                }
                foreach ($row['deal_events'] ?? [] as $e) {
                    $deals = $deals || ($e['date'] >= $start && $e['date'] <= $end && $e['applied'] > 0);
                }
            }
            if ($entries && $deals) {
                return 'mixed';
            }
        }
        return $entries ? 'payment_entries' : ($deals ? 'deals' : 'none');
    }

    /** Payments in (opening, end] that settled instalments which were overdue at the opening date (entries + deal-derived). */
    private function overdueCollected(array $ctx, array $snapO, string $O, string $D): float
    {
        $sum = 0.0;
        foreach ($snapO as $cid => $s) {
            if (!$s['overdue']) {
                continue;
            }
            foreach ($ctx['sched'][$cid] ?? [] as $row) {
                if (!isset($s['unpaid'][$row['id']])) {
                    continue;
                }
                $paid = 0.0;
                foreach ($row['entries'] as [$ed, $p, $i]) {
                    if ($ed > $O && $ed <= $D) {
                        $paid += $p + $i;
                    }
                }
                foreach ($row['deal_events'] ?? [] as $e) {
                    if ($e['date'] > $O && $e['date'] <= $D) {
                        $paid += $e['applied'];
                    }
                }
                $sum += min($s['unpaid'][$row['id']]['amount'], $paid);
            }
        }
        return $sum;
    }

    /** Newly overdue / cured / worsened / improved between the opening and the end date. */
    private function transitions(array $snapO, array $snapD, array $ctx, string $O, string $D): array
    {
        $ids = ['newly_overdue' => [], 'cured' => [], 'worsened' => [], 'improved' => []];
        $curedClosed = 0;
        $droppedOverdue = 0;
        foreach ($snapD as $cid => $s) {
            $was = $snapO[$cid] ?? null;
            if ($s['overdue'] && !($was['overdue'] ?? false)) {
                $ids['newly_overdue'][] = $cid;
            }
            if ($was !== null) {
                $bo = $this->bucketIndex($was['bucket']);
                $bd = $this->bucketIndex($s['bucket']);
                if ($bd > $bo) {
                    $ids['worsened'][] = $cid;
                } elseif ($bd < $bo) {
                    // back to current = cured; a better bucket that is still overdue = improved (disjoint sets)
                    $ids[$bd === 0 ? 'cured' : 'improved'][] = $cid;
                }
            }
        }
        foreach ($snapO as $cid => $s) {
            if ($s['overdue'] && !isset($snapD[$cid])) {
                $c = $ctx['contracts'][$cid];
                if ($c['status'] === 'completed' && $c['closed_at'] !== null && $c['closed_at'] > $O && $c['closed_at'] <= $D) {
                    $ids['cured'][] = $cid;   // full repayment extinguished the obligations
                    $curedClosed++;
                } else {
                    $droppedOverdue++;
                }
            }
        }
        $sum = fn ($list, $snap) => round(array_sum(array_map(fn ($cid) => $snap[$cid]['outstanding'] ?? 0, $list)), 2);
        $borrowers = fn ($list) => count(array_unique(array_map(fn ($cid) => $ctx['contracts'][$cid]['client_id'], $list)));
        return [
            'ids' => $ids,
            'summary' => [
                'newly_overdue' => ['contracts' => count($ids['newly_overdue']), 'borrowers' => $borrowers($ids['newly_overdue']), 'outstanding' => $sum($ids['newly_overdue'], $snapD),
                    'overdue_amount' => round(array_sum(array_map(fn ($c) => $snapD[$c]['overdue_amount'], $ids['newly_overdue'])), 2)],
                'cured' => ['contracts' => count($ids['cured']), 'borrowers' => $borrowers($ids['cured']), 'of_which_closed' => $curedClosed,
                    'opening_overdue_amount' => round(array_sum(array_map(fn ($c) => $snapO[$c]['overdue_amount'], $ids['cured'])), 2), 'outstanding' => $sum($ids['cured'], $snapD)],
                'worsened' => ['contracts' => count($ids['worsened']), 'outstanding' => $sum($ids['worsened'], $snapD)],
                'improved' => ['contracts' => count($ids['improved']), 'outstanding' => $sum($ids['improved'], $snapD)],
                'overdue_dropped_without_explanation' => $droppedOverdue,
            ],
        ];
    }

    // ------------------------------------------------------------------ helpers / data quality

    private function lastPayment(array $ctx, int $cid, string $d): ?string
    {
        $last = null;
        foreach ($ctx['sched'][$cid] ?? [] as $row) {
            foreach ($row['entries'] as [$ed, $p, $i]) {
                if ($ed <= $d && $p + $i > 0 && ($last === null || $ed > $last)) {
                    $last = $ed;
                }
            }
            foreach ($row['deal_events'] ?? [] as $e) {
                if ($e['date'] <= $d && $e['applied'] > 0 && ($last === null || $e['date'] > $last)) {
                    $last = $e['date'];
                }
            }
        }
        return $last;
    }

    private function nextDue(array $ctx, int $cid, string $d): ?string
    {
        $next = null;
        foreach ($this->installments($ctx['sched'][$cid] ?? [], $d) as $r) {
            if ($r['date'] >= $d && ($next === null || $r['date'] < $next) && $r['principal_payment'] + $r['interest_payment'] > OverdueScheduleService::NOISE) {
                $next = $r['date'];
            }
        }
        return $next;
    }

    /** Deal-derived payments are trusted when, on deals that have both sources, deal_actions reproduce the entries. */
    private function dealReliable(array $control): bool
    {
        if ($control['deals'] === 0) {
            return false;
        }
        $base = max(1.0, $control['entries_regular']);
        return abs($control['allocation_diff']) / $base <= 0.01 && $control['deals_allocation_mismatch'] / $control['deals'] <= 0.05;
    }

    /** RELIABLE / PARTIAL / UNAVAILABLE per KPI group for a window. */
    private function reliability(string $source, bool $collectionSplit, bool $overdueSplit, bool $dealsTrusted): array
    {
        $totals = in_array($source, ['deals', 'mixed'], true) && !$dealsTrusted ? 'partial' : 'reliable';
        return [
            'collection_total' => $totals,
            'collection_components' => $totals === 'partial' || !$collectionSplit ? 'partial' : 'reliable',
            'overdue_state' => $totals,                      // overdue contracts, DPD buckets, PAR
            'overdue_components' => $totals === 'partial' || !$overdueSplit ? 'partial' : 'reliable',
        ];
    }

    private function dataQuality(array $ctx, string $D, array $snapD): array
    {
        $noSchedule = 0;
        $subThreshold = 0;
        foreach ($snapD as $s) {
            $noSchedule += $s['has_schedule'] ? 0 : 1;
            $subThreshold += $s['sub_threshold'] ? 1 : 0;
        }
        $closedWithUnpaid = 0;
        $closedUnpaidAmount = 0.0;
        $overpaid = 0;
        $invalidDates = 0;
        $entriesAfter = 0;
        $unexplained = 0;
        foreach ($ctx['sched'] as $cid => $rows) {
            $c = $ctx['contracts'][$cid] ?? null;
            if (!$c) {
                continue;
            }
            if (in_array($c['status'], self::CLOSED_STATUSES, true) && $c['closed_at'] !== null && $c['closed_at'] <= $D) {
                $res = OverdueScheduleService::analyse($this->installments($rows, $D), $D);
                $t = array_sum(array_column($res['overdue'], 'principal')) + array_sum(array_column($res['overdue'], 'interest'));
                if ($t >= self::OVERDUE_MIN_AMOUNT) {
                    $closedWithUnpaid++;
                    $closedUnpaidAmount += $t;
                }
            }
            foreach ($rows as $row) {
                if ($row['date'] < '1990-01-01') {
                    $invalidDates++;
                }
                if ($row['p'] + $row['i'] < 0.5 && $row['status'] === 'completed' && empty($row['entries']) && empty($row['deal_events']) && $row['date'] <= $D) {
                    $unexplained++;   // paid-and-zeroed row whose payment we cannot see in either source
                }
                foreach ($row['entries'] as [$ed]) {
                    $entriesAfter += $ed > $D ? 1 : 0;
                }
                [$rp, $ri] = $this->originalSchedule($row);
                [$ep, $ei, $un] = $this->paidUpTo($row, $D, true);
                if ($un > 0) {
                    if ($ep + $ei + $un > $rp + $ri + 1) {
                        $overpaid++;
                    }
                    continue;
                }
                if ($ep > $rp + 1 || $ei > $ri + 1) {
                    $overpaid++;
                }
            }
        }
        return [
            'active_without_schedule' => $noSchedule,
            'overdue_below_threshold' => $subThreshold,
            'closed_contracts_with_unpaid_schedule' => ['contracts' => $closedWithUnpaid, 'amount' => round($closedUnpaidAmount, 2)],
            'instalments_overpaid_by_entries' => $overpaid,
            'invalid_due_dates' => $invalidDates,
            'entries_after_as_of_ignored' => $entriesAfter,
            'history_stats' => $ctx['history_stats'],
            'zeroed_rows_without_deal_action' => $unexplained,
            'notes' => ['Payment entries removed by a reversal cannot be reconstructed for past dates.', 'Penalties and fees are outside the schedule and are not part of overdue amounts.'],
        ];
    }
}
