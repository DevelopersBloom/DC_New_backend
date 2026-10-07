<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Canonical historical payment layer: tells the as-of engine what was paid on every schedule row and WHEN, whichever
 * system recorded it. Hides the entry-vs-deal source complexity from PortfolioQualityService.
 *
 * WHAT THE PRODUCTION CODE DID (audited: PaymentService::processPayments, DealAction rows, payment_entries)
 *  - Every regular repayment is a `deals` row: type 'in', filter_type 'payment' (purpose "Հերթական վճարում") or 'full_payment'
 *    ("Ամբողջական վճարում"). Other 'in' deals (lender/ndm funds, expenses, the 600 AMD one-off "Միանվագ վճար") are not repayments.
 *  - For each schedule row a payment touches, PaymentService writes a `deal_actions` row (type 'regular', actionable = the
 *    payments row) whose `history.payment_changes[]` keeps the row's state BEFORE the payment (old_amount, old_principal,
 *    old_interest) and, until mid-July 2026, also the state AFTER it (new_amount): the row was zeroed/reduced in place.
 *    That is why scheduled amounts of rows paid before July no longer exist on the payments table.
 *  - Allocation order = the order the cashier selected the rows (penalty first, then the rows). It is recorded per row in
 *    deal_actions, so no FIFO/LIFO rule has to be assumed: the recorded allocation is used as is.
 *  - From mid-July 2026 rows keep their scheduled amounts and the allocation is written to `payment_entries` (carrying deal_id).
 *
 * SOURCE RULE (deterministic, per deal AND schedule row, so a repayment can never be counted twice)
 *  For a repayment deal X that touched schedule row R:
 *   1. the action ZEROED the row in place (history has new_amount)      -> deal_actions is the only allocation record: use it,
 *      and ignore any payment_entries of (X, R). (July 27: the first day entries existed they were incomplete for such rows.)
 *   2. otherwise, payment_entries exist for (X, R)                      -> payment_entries are the source; the action is ignored.
 *   3. otherwise (a repayment deal without entries)                     -> the action amount is the fallback.
 *  Entries of rows with no action are always used as they are. PAYMENT_ENTRY_SOURCE_FROM (the date entries became the source)
 *  is not hard-coded: it is MIN(date) of entries that belong to valid deals, returned for audit; July is therefore MIXED.
 *
 * Valid deal = not soft-deleted, valid ISO date, contract of the pawnshop and not soft-deleted.
 * Component split: known only when the row was fully settled by the deal (then old_principal / old_interest are the parts);
 * a partial settlement has an exact TOTAL but no principal/interest split, which is never estimated.
 */
class PaymentHistoryResolver
{
    private const ACTION_MODEL = 'App\\Models\\Payment';

    /** Loads valid repayment deals with their schedule allocations (1 query) and per-deal entry totals (1 query). */
    public function load(int $pawnshopId): array
    {
        $valid = "d.type = 'in' AND d.filter_type IN ('payment','full_payment') AND d.deleted_at IS NULL
                  AND d.date REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'";
        $rows = DB::select("
            SELECT d.id AS deal_id, d.date AS deal_date, d.contract_id, d.filter_type, d.amount AS deal_amount,
                   EXISTS (SELECT 1 FROM payment_entries pe WHERE pe.deal_id = d.id) AS covered,
                   da.id AS action_id, da.actionable_id AS payment_id, da.amount AS action_amount, da.history
            FROM deals d
            JOIN contracts c ON c.id = d.contract_id AND c.pawnshop_id = :pid AND c.deleted_at IS NULL
            LEFT JOIN deal_actions da ON da.deal_id = d.id AND da.actionable_type = :model AND da.type = 'regular' AND da.deleted_at IS NULL
            WHERE $valid
            ORDER BY d.date, d.id, da.id
        ", ['pid' => $pawnshopId, 'model' => self::ACTION_MODEL]);

        $deals = [];
        $actions = [];
        foreach ($rows as $r) {
            $deals[$r->deal_id] ??= ['id' => (int) $r->deal_id, 'date' => $r->deal_date, 'contract_id' => (int) $r->contract_id, 'filter_type' => $r->filter_type,
                'amount' => (float) $r->deal_amount, 'covered' => (bool) $r->covered, 'actions' => 0];
            if ($r->action_id === null) {
                continue;
            }
            $change = null;
            foreach ((json_decode((string) $r->history, true)['payment_changes'] ?? []) as $c) {
                if ((int) ($c['payment_id'] ?? 0) === (int) $r->payment_id) {
                    $change = $c;
                }
            }
            $deals[$r->deal_id]['actions']++;
            $old = $change !== null && isset($change['old_amount']) ? (float) $change['old_amount'] : null;
            $zeroing = $change !== null && array_key_exists('new_amount', $change) && $change['new_amount'] !== null;
            $applied = $zeroing && $old !== null ? $old - (float) $change['new_amount'] : (float) $r->action_amount;
            // fully settled by this deal: the row's parts are known (old_principal / old_interest are the state before it)
            $full = $old !== null && $applied >= $old - 1 && isset($change['old_principal'], $change['old_interest']);
            $actions[] = [
                'deal_id' => (int) $r->deal_id, 'date' => $r->deal_date, 'contract_id' => (int) $r->contract_id, 'payment_id' => (int) $r->payment_id,
                'action_id' => (int) $r->action_id, 'covered' => (bool) $r->covered, 'applied' => round($applied, 4), 'zeroing' => $zeroing,
                'principal' => $full ? (float) $change['old_principal'] : null, 'interest' => $full ? (float) $change['old_interest'] : null,
                'old_principal' => isset($change['old_principal']) ? (float) $change['old_principal'] : null,
                'old_interest' => isset($change['old_interest']) ? (float) $change['old_interest'] : null,
                'usable' => $change !== null && $old !== null,
            ];
        }

        $entryTotals = [];
        foreach (DB::select("
            SELECT pe.deal_id, SUM(pe.amount) AS total, SUM(pe.penalty_amount) AS penalty,
                   SUM(CASE WHEN pe.document_type = 'regular_payment' THEN pe.amount ELSE 0 END) AS regular,
                   COUNT(*) AS n
            FROM payment_entries pe JOIN deals d ON d.id = pe.deal_id JOIN contracts c ON c.id = d.contract_id
            WHERE c.pawnshop_id = :pid AND $valid GROUP BY pe.deal_id
        ", ['pid' => $pawnshopId]) as $e) {
            $entryTotals[(int) $e->deal_id] = ['total' => (float) $e->total, 'penalty' => (float) $e->penalty, 'regular' => (float) $e->regular, 'n' => (int) $e->n];
        }
        $boundary = DB::selectOne("
            SELECT MIN(pe.date) AS d FROM payment_entries pe JOIN deals d ON d.id = pe.deal_id JOIN contracts c ON c.id = d.contract_id
            WHERE c.pawnshop_id = :pid AND $valid", ['pid' => $pawnshopId])->d ?? null;

        return ['deals' => $deals, 'actions' => $actions, 'entry_totals' => $entryTotals, 'entry_source_from' => $boundary ? substr($boundary, 0, 10) : null];
    }

    /**
     * Attaches deal-derived payments to the schedule rows.
     * @param array $sched       contract_id => list of rows (see PortfolioQualityService::context)
     * @param array $byPayment   payment_id => [contract_id, row index]
     * @param bool  $forceDeals  validation mode: ignore payment_entries entirely and use deal_actions for every deal
     * @return array statistics (which rule each allocation fell under)
     */
    public function attach(array &$sched, array $byPayment, array $loaded, bool $forceDeals = false): array
    {
        $stats = ['actions_used' => 0, 'actions_skipped_no_row' => 0, 'actions_unusable' => 0, 'rows_with_deal_events' => 0];
        // entries per (deal, row): decides rule 2 vs 3
        $entryOf = [];
        foreach ($sched as $cid => $rows) {
            foreach ($rows as $row) {
                foreach ($row['entries'] as $e) {
                    if (($e[3] ?? null) !== null) {
                        $entryOf[$e[3] . ':' . $row['id']] = true;
                    }
                }
            }
        }
        $perRow = [];
        $stats['zeroing_used'] = $stats['fallback_used'] = $stats['entries_preferred'] = 0;
        $dropEntries = [];
        foreach ($loaded['actions'] as $a) {
            if (!isset($byPayment[$a['payment_id']])) {
                $stats['actions_skipped_no_row']++;              // the row is gone (rebuilt/deleted)
                continue;
            }
            if (!$a['usable']) {
                $stats['actions_unusable']++;
                continue;
            }
            $key = $a['deal_id'] . ':' . $a['payment_id'];
            if (!$forceDeals && !$a['zeroing'] && isset($entryOf[$key])) {
                $stats['entries_preferred']++;                   // rule 2
                continue;
            }
            $stats[$a['zeroing'] ? 'zeroing_used' : 'fallback_used']++;
            if ($a['zeroing'] || $forceDeals) {
                $dropEntries[$key] = true;                       // rule 1: entries of this (deal, row) are not counted again
            }
            $perRow[$a['payment_id']][] = $a;
        }
        foreach ($dropEntries as $key => $_) {
            [$dealId, $paymentId] = array_map('intval', explode(':', $key));
            [$cid, $k] = $byPayment[$paymentId];
            $sched[$cid][$k]['entries'] = array_values(array_filter($sched[$cid][$k]['entries'], fn ($e) => ($e[3] ?? null) !== $dealId));
        }

        foreach ($perRow as $paymentId => $list) {
            [$cid, $k] = $byPayment[$paymentId];
            usort($list, fn ($x, $y) => [$x['date'], $x['deal_id'], $x['action_id']] <=> [$y['date'], $y['deal_id'], $y['action_id']]);
            $row = &$sched[$cid][$k];
            // Row p/i stay the CURRENT state. Zeroing events carry the state before the payment (old_p/old_i), which the
            // as-of engine uses for dates before the payment; current p/i already net out zeroing payments.
            foreach ($list as $a) {
                $row['deal_events'][] = ['date' => $a['date'], 'applied' => $a['applied'], 'p' => $a['principal'], 'i' => $a['interest'], 'deal_id' => $a['deal_id'],
                    'zeroing' => $a['zeroing'], 'old_p' => $a['old_principal'], 'old_i' => $a['old_interest']];
            }
            unset($row);
            $stats['actions_used'] += count($list);
            $stats['rows_with_deal_events']++;
        }
        if ($forceDeals) {
            foreach ($sched as $cid => &$rows) {
                foreach ($rows as &$row) {
                    $row['entries'] = [];
                }
                unset($row);
            }
            unset($rows);
        }
        return $stats;
    }

    /** Deal classification summary for audit: counts and amounts by source. */
    public static function classification(array $loaded, string $from, string $to): array
    {
        $out = ['entries' => ['deals' => 0, 'amount' => 0.0], 'deals' => ['deals' => 0, 'amount' => 0.0], 'without_schedule_allocation' => ['deals' => 0, 'amount' => 0.0]];
        foreach ($loaded['deals'] as $d) {
            if ($d['date'] < $from || $d['date'] > $to) {
                continue;
            }
            $k = $d['covered'] ? 'entries' : ($d['actions'] > 0 ? 'deals' : 'without_schedule_allocation');
            $out[$k]['deals']++;
            $out[$k]['amount'] += $d['amount'];
        }
        return $out;
    }

    /**
     * Control: for deals that have BOTH sources, reconstructed schedule allocation (deal_actions) vs payment_entries.
     * Tells how faithfully deal_actions reproduce the entries (and so how far the earlier, deal-only months can be trusted).
     */
    public static function control(array $loaded, string $from, string $to): array
    {
        $actionSum = [];
        foreach ($loaded['actions'] as $a) {
            if ($a['usable']) {
                $actionSum[$a['deal_id']] = ($actionSum[$a['deal_id']] ?? 0) + $a['applied'];
            }
        }
        $r = ['deals' => 0, 'deal_amount' => 0.0, 'entries_total' => 0.0, 'amount_diff' => 0.0, 'deals_amount_mismatch' => 0,
            'entries_regular' => 0.0, 'actions_applied' => 0.0, 'allocation_diff' => 0.0, 'deals_allocation_mismatch' => 0];
        foreach ($loaded['deals'] as $d) {
            $e = $loaded['entry_totals'][$d['id']] ?? null;
            if (!$e || $d['date'] < $from || $d['date'] > $to) {
                continue;
            }
            $r['deals']++;
            $r['deal_amount'] += $d['amount'];
            $r['entries_total'] += $e['total'];
            $r['deals_amount_mismatch'] += abs($d['amount'] - $e['total']) > 1 ? 1 : 0;
            $a = $actionSum[$d['id']] ?? 0.0;
            $r['entries_regular'] += $e['regular'];
            $r['actions_applied'] += $a;
            $r['deals_allocation_mismatch'] += $d['filter_type'] === 'payment' && abs($a - $e['regular']) > 1 ? 1 : 0;
        }
        $r['amount_diff'] = round($r['deal_amount'] - $r['entries_total'], 2);
        $r['allocation_diff'] = round($r['actions_applied'] - $r['entries_regular'], 2);
        foreach (['deal_amount', 'entries_total', 'entries_regular', 'actions_applied'] as $k) {
            $r[$k] = round($r[$k], 2);
        }
        return $r;
    }
}
