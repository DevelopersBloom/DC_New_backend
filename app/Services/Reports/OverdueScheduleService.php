<?php

namespace App\Services\Reports;

use App\Models\Contract;
use App\Models\DocumentJournal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Overdue part of the payment schedule as it stood at a given date.
 *
 * Only data that existed at $date is used: schedule rows created on or before $date and not
 * soft-deleted by then, and payment_entries dated on or before $date. A repayment or schedule
 * rebuild made after $date therefore does not change the result.
 *
 * Used by the V06 (v2) export for row 1.3; intended to also replace V09Export::overdueScheduleAmounts.
 */
class OverdueScheduleService
{
    /** Instalment remainders at or below this many AMD are rounding noise, not overdue debt. */
    public const NOISE = 0.5;

    /**
     * An instalment with no entries at all, below this many AMD (principal + interest), is assumed
     * paid when a later instalment of the same contract has entries up to the report date: money is
     * applied oldest-first, so the older one was cleared. Larger amounts are never assumed paid.
     */
    public const ASSUMED_PAID_LIMIT = 1000.0;

    private const CHUNK = 1000;

    /**
     * Where the 16200 balance (effective-rate adjustment of the whole asset) is reported.
     * false: all of it stays in row 1.1, so row 1.3 is exactly the principal, interest and
     * penalties that are due and unpaid. true: the overdue share of the principal moves to 1.3.
     * Pending the accountant's confirmation.
     */
    public const ADJUSTMENT_PRO_RATA_TO_OVERDUE = false;

    /**
     * @param  int[]  $contractIds
     * @param  array|null  $settledWithGap  filled with contract_id => instalments treated as settled
     *                                      although the entries fall short (see analyse())
     * @param  array|null  $assumedPaid     filled with contract_id => instalments without entries that
     *                                      were assumed paid (see ASSUMED_PAID_LIMIT)
     * @return array<int, list<array{payment_id:int,due_date:string,principal:float,interest:float,days_overdue:int}>>
     *         contract_id => overdue instalments, oldest first. Contracts with none are absent.
     */
    public function overdueAtDate(array $contractIds, string $date, ?array &$settledWithGap = null, ?array &$assumedPaid = null): array
    {
        $settledWithGap = [];
        $assumedPaid = [];
        $result = [];
        foreach (array_chunk(array_values(array_unique($contractIds)), self::CHUNK) as $chunk) {
            $installments = $this->loadInstallments($chunk, $date);
            $byContract = [];
            foreach ($installments as $row) {
                $byContract[$row['contract_id']][] = $row;
            }
            $latestPaid = $this->latestPaidDueDates($chunk, $date);
            foreach ($byContract as $contractId => $rows) {
                $analysis = self::analyse($rows, $date, $latestPaid[$contractId] ?? null);
                if ($analysis['overdue']) {
                    $result[$contractId] = $analysis['overdue'];
                }
                if ($analysis['settled_with_gap']) {
                    $settledWithGap[$contractId] = $analysis['settled_with_gap'];
                }
                if ($analysis['assumed_paid']) {
                    $assumedPaid[$contractId] = $analysis['assumed_paid'];
                }
            }
        }

        return $result;
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
     *   slightly from the schedule (interest is recalculated for the real days).
     * - An instalment with no entries at all is unpaid, whatever its status.
     * - Otherwise unpaid = scheduled - entries up to $date, floored at 0.
     * - Overdue only if due date < $date (due exactly on $date is not overdue) and the unpaid
     *   principal or interest exceeds NOISE.
     *
     * @return list<array{payment_id:int,due_date:string,principal:float,interest:float,days_overdue:int}>
     */
    public static function classify(iterable $installments, string $date): array
    {
        return self::analyse($installments, $date)['overdue'];
    }

    /**
     * Same rules as classify(), also returning the instalments that were treated as settled only
     * because their last entry is dated on or before $date although the entries do not cover the
     * schedule (e.g. contract 13: interest 1,690.81 lower than scheduled). They are not overdue,
     * but the V2 export logs them so the accountant can see every one.
     *
     * $latestPaidDueDate is the due date of the contract's latest instalment that has entries up to
     * $date; it enables the assumed-paid rule (see ASSUMED_PAID_LIMIT), reported in 'assumed_paid'.
     *
     * @return array{overdue: list<array>, settled_with_gap: list<array>, assumed_paid: list<array>}
     */
    public static function analyse(iterable $installments, string $date, ?string $latestPaidDueDate = null): array
    {
        $reportDate = new DateTimeImmutable($date);
        $out = [];
        $gaps = [];
        $assumed = [];
        foreach ($installments as $row) {
            $due = substr((string) $row['date'], 0, 10);
            if ($due >= $date) {
                continue;
            }

            $scheduledPrincipal = (float) $row['principal_payment'];
            $scheduledInterest = (float) $row['interest_payment'];
            if (($row['status'] ?? null) === 'completed' && empty($row['last_entry_date'])
                && $latestPaidDueDate !== null && $latestPaidDueDate > $due
                && $scheduledPrincipal + $scheduledInterest < self::ASSUMED_PAID_LIMIT
                && $scheduledPrincipal + $scheduledInterest > self::NOISE) {
                $assumed[] = [
                    'payment_id' => (int) $row['id'],
                    'due_date' => $due,
                    'principal' => $scheduledPrincipal,
                    'interest' => $scheduledInterest,
                    'paid_instalment_due_date' => $latestPaidDueDate,
                ];
                continue;
            }

            $principal = max(0.0, $scheduledPrincipal - (float) ($row['entry_principal'] ?? 0));
            $interest = max(0.0, $scheduledInterest - (float) ($row['entry_interest'] ?? 0));

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

        return ['overdue' => $out, 'settled_with_gap' => $gaps, 'assumed_paid' => $assumed];
    }

    /**
     * Second calculation: cumulative due against cumulative paid, paid taken from the ledger.
     *
     * Per contract: scheduled principal and interest of the instalments due before $date, less the
     * repayments the ledger shows up to $date (credits to 16200NV / 16201NI whose debit is cash,
     * bank or a prepayment account; penalty payments and write-offs are not repayments). A positive
     * result is overdue, an excess is ignored. The money is applied to the oldest instalments first,
     * so what stays unpaid sits on the later instalments of the arrears (see allocateOldestFirst()).
     *
     * Stored splits on payments / payment_entries can disagree with each other (contract 13); the
     * ledger journal is what was actually booked.
     *
     * @param  int[]  $contractIds
     * @param  float  $threshold  contract-level minimum of unpaid principal + interest (0 = none)
     * @return array<int, list<array{payment_id:int,due_date:string,principal:float,interest:float,days_overdue:int}>>
     */
    public function ledgerOverdueAtDate(array $contractIds, string $date, float $threshold = 0.0): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($contractIds)), self::CHUNK) as $chunk) {
            $byContract = [];
            foreach ($this->loadInstallments($chunk, $date, false) as $row) {
                $byContract[$row['contract_id']][] = $row;
            }
            $paid = $this->ledgerRepaymentsAtDate($chunk, $date);
            foreach ($byContract as $contractId => $rows) {
                $overdue = self::allocateOldestFirst(
                    $rows,
                    $paid[$contractId]['principal'] ?? 0.0,
                    $paid[$contractId]['interest'] ?? 0.0,
                    $date
                );
                $total = array_sum(array_column($overdue, 'principal')) + array_sum(array_column($overdue, 'interest'));
                if ($overdue && $total >= max($threshold, self::NOISE)) {
                    $result[$contractId] = $overdue;
                }
            }
        }

        return $result;
    }

    /**
     * Pure: apply cumulative paid principal / interest to the instalments due before $date, oldest
     * first; whatever is not covered is overdue.
     *
     * @param  iterable  $installments  rows with id, date, principal_payment, interest_payment and
     *                               optionally original_principal_payment, original_interest_payment
     * @return list<array{payment_id:int,due_date:string,principal:float,interest:float,days_overdue:int}>
     */
    public static function allocateOldestFirst(iterable $installments, float $paidPrincipal, float $paidInterest, string $date): array
    {
        $rows = [];
        foreach ($installments as $row) {
            if (substr((string) $row['date'], 0, 10) < $date) {
                $rows[] = $row;
            }
        }
        usort($rows, fn ($a, $b) => [substr((string) $a['date'], 0, 10), $a['id']] <=> [substr((string) $b['date'], 0, 10), $b['id']]);

        $reportDate = new DateTimeImmutable($date);
        $out = [];
        foreach ($rows as $row) {
            // The schedule is rewritten as repayments come in (an instalment settled early shows 0.00
            // today); the original_* columns keep what was contractually due, so the larger wins.
            $duePrincipal = max((float) $row['principal_payment'], (float) ($row['original_principal_payment'] ?? 0));
            $dueInterest = max((float) $row['interest_payment'], (float) ($row['original_interest_payment'] ?? 0));
            $coveredPrincipal = min($duePrincipal, max($paidPrincipal, 0.0));
            $coveredInterest = min($dueInterest, max($paidInterest, 0.0));
            $paidPrincipal -= $coveredPrincipal;
            $paidInterest -= $coveredInterest;

            $principal = $duePrincipal - $coveredPrincipal;
            $interest = $dueInterest - $coveredInterest;
            if ($principal <= self::NOISE && $interest <= self::NOISE) {
                continue;
            }
            $due = substr((string) $row['date'], 0, 10);
            $out[] = [
                'payment_id' => (int) $row['id'],
                'due_date' => $due,
                'principal' => $principal,
                'interest' => $interest,
                'days_overdue' => (int) (new DateTimeImmutable($due))->diff($reportDate)->days,
            ];
        }

        return $out;
    }

    /**
     * Repayments booked in the ledger up to $date per contract.
     *
     * @param  int[]  $contractIds
     * @return array<int, array{principal: float, interest: float}>
     */
    public function ledgerRepaymentsAtDate(array $contractIds, string $date): array
    {
        $nv = DB::table('chart_of_accounts')->where('code', '16200NV')->value('id');
        $ni = DB::table('chart_of_accounts')->where('code', '16201NI')->value('id');
        if (!$nv || !$ni || !$contractIds) {
            return [];
        }
        $moneyAccounts = DB::table('chart_of_accounts')
            ->where(fn ($q) => $q->where('code', 'like', '10%')->orWhere('code', '39220'))
            ->pluck('id')->all();

        $rows = DB::table('documents_journal as j')
            ->leftJoin('documents_journal as parent', function ($join) {
                $join->on('parent.id', '=', 'j.journalable_id')
                    ->where('j.journalable_type', '=', DocumentJournal::class);
            })
            ->whereNull('j.deleted_at')
            ->whereDate('j.date', '<=', $date)
            ->whereIn('j.credit_account_id', [$nv, $ni])
            ->whereIn('j.debit_account_id', $moneyAccounts)
            ->where('j.document_type', 'not like', '%Տուգանք%')
            ->where('j.document_type', 'not like', '%penalty%')
            ->selectRaw(
                "COALESCE(j.contract_id,
                    CASE WHEN j.journalable_type = ? THEN j.journalable_id END,
                    parent.contract_id,
                    CASE WHEN parent.journalable_type = ? THEN parent.journalable_id END) as cid,
                 j.credit_account_id as acc, j.amount_amd as amount",
                [Contract::class, Contract::class]
            )
            ->get();

        $wanted = array_flip($contractIds);
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r->cid;
            if (!isset($wanted[$cid])) {
                continue;
            }
            $key = (int) $r->acc === (int) $nv ? 'principal' : 'interest';
            $out[$cid][$key] = ($out[$cid][$key] ?? 0.0) + (float) $r->amount;
        }

        return $out;
    }

    /**
     * Fit the schedule's overdue amounts to the contract's ledger balances.
     *
     * The ledger is the total:
     * - overdue principal is capped at the 16200NV balance (every overdue instalment scaled down
     *   proportionally);
     * - overdue interest = unpaid interest of the overdue instalments + accrued unpaid penalties
     *   (penalties are posted to 16201NI against 66301), capped at the positive 16201NI balance;
     * - 16200 is the effective-rate adjustment of the whole asset, not a penalty; it stays in
     *   row 1.1 unless ADJUSTMENT_PRO_RATA_TO_OVERDUE is set (then pro rata to principal).
     * A contract with overdue instalments but no principal on the ledger (written off) stays
     * overdue, with zero principal. A shortfall that only the schedule shows, with no support in
     * the ledger, does not make a contract overdue.
     *
     * @param  list<array{payment_id:int,due_date:string,principal:float,interest:float,days_overdue:int}>  $overdue  from classify()
     * @return array{
     *     instalments: list<array{payment_id:int,due_date:string,principal:float,interest:float,days_overdue:int}>,
     *     principal: float, interest: float, adjustment: float, gross: float,
     *     schedule_principal: float, capped: bool, overdue: bool
     * }
     */
    public static function reconcile(
        array $overdue,
        float $ledgerPrincipal,
        float $ledgerInterest,
        float $ledgerAdjustment,
        float $unpaidPenalties = 0.0
    ): array {
        $scheduleP = array_sum(array_column($overdue, 'principal'));
        $scheduleI = array_sum(array_column($overdue, 'interest'));
        $niBalance = max($ledgerInterest, 0.0);

        $principal = $ledgerPrincipal > self::NOISE ? min($ledgerPrincipal, $scheduleP) : 0.0;
        $overdueInterest = min($niBalance, $scheduleI);

        if ($scheduleP <= self::NOISE && $overdueInterest <= self::NOISE) {
            return [
                'instalments' => [], 'principal' => 0.0, 'interest' => 0.0, 'adjustment' => 0.0,
                'gross' => 0.0, 'schedule_principal' => $scheduleP, 'capped' => false, 'overdue' => false,
            ];
        }

        $interest = min($niBalance, $scheduleI + max($unpaidPenalties, 0.0));
        $adjustment = self::ADJUSTMENT_PRO_RATA_TO_OVERDUE && $ledgerPrincipal > self::NOISE
            ? $ledgerAdjustment * $principal / $ledgerPrincipal
            : 0.0;

        $scale = $scheduleP > 0 ? $principal / $scheduleP : 0.0;
        $instalments = array_map(function ($row) use ($scale) {
            $row['principal'] *= $scale;
            return $row;
        }, $overdue);

        return [
            'instalments' => $instalments,
            'principal' => $principal,
            'interest' => $interest,
            'adjustment' => $adjustment,
            'gross' => $principal + $interest + $adjustment,
            'schedule_principal' => $scheduleP,
            'capped' => $scheduleP - $principal > self::NOISE,
            'overdue' => true,
        ];
    }

    /**
     * Accrued, still unpaid penalties at $date, from the ledger: accruals (Dr 16201NI / Cr 66301)
     * less penalty repayments (Cr 16201NI, "Տուգանքի մարում"), counting only postings dated after
     * the due date of the contract's oldest overdue instalment (earlier penalties belong to earlier,
     * settled arrears and are not part of the current overdue asset), floored at 0.
     *
     * @param  array<int, string>  $sinceByContract  contract_id => oldest overdue due date (Y-m-d)
     * @return array<int, float> contract_id => unpaid penalties
     */
    public function unpaidPenaltiesAtDate(array $sinceByContract, string $date): array
    {
        $ni = DB::table('chart_of_accounts')->where('code', '16201NI')->value('id');
        $income = DB::table('chart_of_accounts')->where('code', '66301')->value('id');
        if (!$ni || !$income || !$sinceByContract) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_keys($sinceByContract), self::CHUNK) as $chunk) {
            // Contract of a journal row: its contract_id, else the contract it is posted on, else
            // the contract of the parent document (same keying as the V06 ledger queries).
            $rows = DB::table('documents_journal as j')
                ->leftJoin('documents_journal as parent', function ($join) {
                    $join->on('parent.id', '=', 'j.journalable_id')
                        ->where('j.journalable_type', '=', DocumentJournal::class);
                })
                ->whereNull('j.deleted_at')
                ->whereDate('j.date', '<=', $date)
                ->where(function ($q) use ($ni, $income) {
                    $q->where(fn ($w) => $w->where('j.debit_account_id', $ni)->where('j.credit_account_id', $income))
                        ->orWhere(fn ($w) => $w->where('j.credit_account_id', $ni)->where('j.document_type', 'like', '%Տուգանքի մարում%'));
                })
                ->selectRaw(
                    "COALESCE(j.contract_id,
                        CASE WHEN j.journalable_type = ? THEN j.journalable_id END,
                        parent.contract_id,
                        CASE WHEN parent.journalable_type = ? THEN parent.journalable_id END) as cid,
                     DATE(j.date) as d, j.amount_amd as amount, (j.credit_account_id = ?) as is_payment",
                    [Contract::class, Contract::class, $ni]
                )
                ->get();

            foreach ($rows as $r) {
                $cid = (int) $r->cid;
                if (!isset($sinceByContract[$cid]) || $r->d <= $sinceByContract[$cid] || !in_array($cid, $chunk, true)) {
                    continue;
                }
                $out[$cid] = ($out[$cid] ?? 0.0) + ($r->is_payment ? -1 : 1) * (float) $r->amount;
            }
        }

        return array_map(fn ($v) => max(0.0, $v), $out);
    }

    /** Form 6 / Table I column for a number of days overdue. */
    public static function columnForDays(int $days): string
    {
        if ($days <= 90) return 'B';
        if ($days <= 180) return 'D';
        if ($days <= 270) return 'F';
        if ($days <= 366) return 'H';
        if ($days <= 1826) return 'J';
        return 'L';
    }

    /**
     * Due date of each contract's latest instalment (any due date) that has a principal/interest
     * entry dated on or before $date.
     *
     * @param  int[]  $contractIds
     * @return array<int, string> contract_id => Y-m-d
     */
    private function latestPaidDueDates(array $contractIds, string $date): array
    {
        $endOfDay = $date . ' 23:59:59';

        return DB::table('payments as p')
            ->join('payment_entries as e', 'e.payment_id', '=', 'p.id')
            ->whereIn('p.contract_id', $contractIds)
            ->where('p.type', 'regular')
            ->where('p.created_at', '<=', $endOfDay)
            ->where(function ($q) use ($endOfDay) {
                $q->whereNull('p.deleted_at')->orWhere('p.deleted_at', '>', $endOfDay);
            })
            ->whereDate('e.date', '<=', $date)
            ->whereRaw('e.principal_amount + e.interest_amount > 0')
            ->groupBy('p.contract_id')
            ->selectRaw('p.contract_id, MAX(DATE(p.date)) as latest')
            ->pluck('latest', 'contract_id')
            ->map(fn ($d) => (string) $d)
            ->all();
    }

    /** @param int[] $contractIds */
    private function loadInstallments(array $contractIds, string $date, bool $withEntries = true): array
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
            ->select('p.id', 'p.contract_id', 'p.date', 'p.principal_payment', 'p.interest_payment', 'p.status',
                'p.original_principal_payment', 'p.original_interest_payment')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }
        if (!$withEntries) {
            return $rows->map(fn ($r) => [
                'id' => $r->id,
                'contract_id' => (int) $r->contract_id,
                'date' => $r->date,
                'principal_payment' => $r->principal_payment,
                'interest_payment' => $r->interest_payment,
                'status' => $r->status,
                'original_principal_payment' => $r->original_principal_payment,
                'original_interest_payment' => $r->original_interest_payment,
            ])->all();
        }

        $entries = DB::table('payment_entries')
            ->whereIn('payment_id', $rows->pluck('id'))
            ->groupBy('payment_id')
            ->selectRaw(
                'payment_id, COUNT(*) as n,
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
