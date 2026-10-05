<?php

namespace App\Services\Reports;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which contracts were overdue on a given date, judged only by data that existed on that date:
 * schedule rows created on or before it and not soft-deleted by then, and payment_entries dated on
 * or before it. A repayment, a schedule rebuild or a status change made afterwards does not change
 * the answer, and the contract's current status is never looked at.
 *
 * Used by the V06 export to decide which contracts belong in row 1.3.
 */
class OverdueScheduleService
{
    /** Instalment remainders at or below this many AMD are rounding noise, not overdue debt. */
    public const NOISE = 0.5;

    private const CHUNK = 1000;

    /**
     * Contracts whose overdue principal + interest on $date is at least $minAmount.
     *
     * The amount is the schedule's unpaid principal and interest of the instalments due before
     * $date (not capped at the ledger, so a written-off contract that still has an overdue
     * schedule stays overdue).
     *
     * @param  int[]  $contractIds
     * @param  array|null  $settledWithGap  filled with contract_id => instalments treated as settled
     *                                      although the entries fall short (see analyse())
     * @return array<int, float> contract_id => overdue principal + interest
     */
    public function overdueAmountsAtDate(array $contractIds, string $date, float $minAmount, ?array &$settledWithGap = null): array
    {
        $settledWithGap = [];
        $result = [];

        foreach (array_chunk(array_values(array_unique(array_map('intval', $contractIds))), self::CHUNK) as $chunk) {
            $byContract = [];
            foreach ($this->loadInstallments($chunk, $date) as $row) {
                $byContract[$row['contract_id']][] = $row;
            }
            foreach ($byContract as $contractId => $rows) {
                $analysis = self::analyse($rows, $date);
                $total = array_sum(array_column($analysis['overdue'], 'principal'))
                    + array_sum(array_column($analysis['overdue'], 'interest'));
                if ($total >= max($minAmount, self::NOISE)) {
                    $result[$contractId] = $total;
                }
                if ($analysis['settled_with_gap']) {
                    $settledWithGap[$contractId] = $analysis['settled_with_gap'];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<array{payment_id:int,due_date:string,principal:float,interest:float,days_overdue:int}>
     */
    public static function classify(iterable $installments, string $date): array
    {
        return self::analyse($installments, $date)['overdue'];
    }

    /**
     * Pure rule set (no database).
     *
     * Each input row: id, date (due date), principal_payment, interest_payment, status,
     * entry_principal, entry_interest (entries dated <= $date, already summed), last_entry_date
     * (latest entry of any date that carries principal or interest, or null).
     *
     * - Payment entries dated up to $date are the only proof of payment. payments.status and
     *   payments.to_date are not: to_date is the due date, and status is set when the instalment
     *   is settled, which may be after $date.
     * - The one use of status: a 'completed' instalment whose last principal/interest entry is
     *   dated on or before $date is settled at $date even if the entered interest differs
     *   slightly from the schedule (interest is recalculated for the real days). Such instalments
     *   are returned in 'settled_with_gap' so they can be logged.
     * - An instalment with no entries at all is unpaid, whatever its status.
     * - Unpaid = scheduled - entries up to $date, floored at 0.
     * - Overdue only if due date < $date (due exactly on $date is not overdue) and the unpaid
     *   principal or interest exceeds NOISE.
     *
     * @return array{overdue: list<array>, settled_with_gap: list<array{payment_id:int,due_date:string,principal_gap:float,interest_gap:float}>}
     */
    public static function analyse(iterable $installments, string $date): array
    {
        $reportDate = new DateTimeImmutable($date);
        $out = [];
        $gaps = [];
        foreach ($installments as $row) {
            $due = substr((string) $row['date'], 0, 10);
            if ($due >= $date) {
                continue;
            }

            $principal = max(0.0, (float) $row['principal_payment'] - (float) ($row['entry_principal'] ?? 0));
            $interest = max(0.0, (float) $row['interest_payment'] - (float) ($row['entry_interest'] ?? 0));

            if (($row['status'] ?? null) === 'completed' && !empty($row['last_entry_date'])
                && substr((string) $row['last_entry_date'], 0, 10) <= $date) {
                if ($principal > self::NOISE || $interest > self::NOISE) {
                    $gaps[] = [
                        'payment_id' => (int) $row['id'],
                        'due_date' => $due,
                        'principal_gap' => $principal,
                        'interest_gap' => $interest,
                    ];
                }
                continue;
            }

            if ($principal <= self::NOISE && $interest <= self::NOISE) {
                continue;
            }

            $out[] = [
                'payment_id' => (int) $row['id'],
                'due_date' => $due,
                'principal' => $principal,
                'interest' => $interest,
                'days_overdue' => (int) (new DateTimeImmutable($due))->diff($reportDate)->days,
            ];
        }

        usort($out, fn ($a, $b) => [$a['due_date'], $a['payment_id']] <=> [$b['due_date'], $b['payment_id']]);

        return ['overdue' => $out, 'settled_with_gap' => $gaps];
    }

    /** @param int[] $contractIds */
    private function loadInstallments(array $contractIds, string $date): array
    {
        $endOfDay = $date . ' 23:59:59';

        $rows = DB::table('payments as p')
            ->whereIn('p.contract_id', $contractIds)
            ->where('p.type', 'regular')
            ->whereDate('p.date', '<', $date)
            ->where('p.created_at', '<=', $endOfDay)
            ->where(function ($q) use ($endOfDay) {
                $q->whereNull('p.deleted_at')->orWhere('p.deleted_at', '>', $endOfDay);
            })
            ->select('p.id', 'p.contract_id', 'p.date', 'p.principal_payment', 'p.interest_payment', 'p.status')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $entries = DB::table('payment_entries')
            ->whereIn('payment_id', $rows->pluck('id'))
            ->groupBy('payment_id')
            ->selectRaw(
                'payment_id,
                 SUM(CASE WHEN DATE(date) <= ? THEN principal_amount ELSE 0 END) as principal,
                 SUM(CASE WHEN DATE(date) <= ? THEN interest_amount ELSE 0 END) as interest,
                 MAX(CASE WHEN principal_amount + interest_amount > 0 THEN DATE(date) END) as last_entry',
                [$date, $date]
            )
            ->get()
            ->keyBy('payment_id');

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'contract_id' => (int) $r->contract_id,
            'date' => $r->date,
            'principal_payment' => $r->principal_payment,
            'interest_payment' => $r->interest_payment,
            'status' => $r->status,
            'entry_principal' => $entries[$r->id]->principal ?? 0,
            'entry_interest' => $entries[$r->id]->interest ?? 0,
            'last_entry_date' => $entries[$r->id]->last_entry ?? null,
        ])->all();
    }
}
