<?php

namespace App\Services\Schedule;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\Prepayment;
use App\Models\ScheduleRegeneration;
use App\Services\ActivityService;
use App\Services\Payments\PaymentService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the open part of an amortized schedule after a principal payment.
 *
 * Kept: every completed row and the CURRENT row (first open row, the one whose
 * due date is next). Replaced: the open rows after it. The new rows run on the
 * principal left once the current row is paid, with due dates on the contract's
 * payment day and the last one on the business day of contracts.deadline.
 *
 * Principal-prepayment entries sitting on replaced rows are re-pointed to the
 * current row (amounts, dates and deals untouched) and the current row's gross
 * principal/amount grow by the same sum, so "row minus its entries" stays right.
 */
class ScheduleRegenerator
{
    private const TOLERANCE = 0.05;

    public function __construct(
        private AnnuityScheduleCalculator $calculator,
        private PaymentService $paymentService,
        private ActivityService $activityService,
    ) {}

    // ── Public API ──────────────────────────────────────────────────────────

    /** Read-only: what apply() would do. */
    public function preview(Contract $contract, string $effectiveDate, ?int $paymentDay = null): array
    {
        return $this->summary($this->plan($contract, $effectiveDate, $paymentDay));
    }

    /** @return array summary + regeneration_id + changed */
    public function apply(
        int $contractId,
        string $effectiveDate,
        ?int $paymentDay,
        ?string $reason,
        ?string $stateHash,
        ?int $userId
    ): array {
        return DB::transaction(function () use ($contractId, $effectiveDate, $paymentDay, $reason, $stateHash, $userId) {
            $contract = Contract::lockForUpdate()->findOrFail($contractId);
            Payment::where('contract_id', $contract->id)->where('type', 'regular')
                ->where('status', Contract::STATUS_INITIAL)->lockForUpdate()->get();

            $plan = $this->plan($contract, $effectiveDate, $paymentDay);
            if ($stateHash !== null && !hash_equals($plan['state_hash'], $stateHash)) {
                throw new ScheduleRegenerationException(
                    'STATE_CHANGED',
                    'Contract changed since the preview, preview again.',
                    409
                );
            }

            if ($plan['unchanged']) {
                return $this->summary($plan) + ['regeneration_id' => null, 'changed' => false];
            }

            $before = $this->protectedChecksum($contract->id, $plan['replace_ids']);

            $record = $this->write($contract, $plan, $reason, $userId);
            $this->assertInvariants($contract->fresh(), $plan, $before);

            $this->activityService->log(
                'regenerate_schedule',
                "Regenerated schedule of contract #{$contract->id} from {$plan['current']->to_date} on balance "
                . number_format($plan['balance'], 2, '.', '') . " (regeneration #{$record->id})",
                Contract::class,
                $contract->id
            );

            return $this->summary($plan) + ['regeneration_id' => $record->id, 'changed' => true];
        });
    }

    public function revert(int $regenerationId, ?int $userId): ScheduleRegeneration
    {
        return DB::transaction(function () use ($regenerationId, $userId) {
            $record   = ScheduleRegeneration::lockForUpdate()->findOrFail($regenerationId);
            $contract = Contract::lockForUpdate()->findOrFail($record->contract_id);

            if (!$record->isActive()) {
                throw new ScheduleRegenerationException('ALREADY_REVERTED', 'Regeneration is already reverted.');
            }

            $later = ScheduleRegeneration::where('contract_id', $record->contract_id)
                ->whereNull('reverted_at')->where('id', '>', $record->id)->exists();
            if ($later) {
                throw new ScheduleRegenerationException('REVERT_BLOCKED', 'A later regeneration exists, revert it first.');
            }

            $created = $record->created_payment_ids ?? [];
            if (PaymentEntry::whereIn('payment_id', $created)->exists()
                || Payment::whereIn('id', $created)->where('status', '!=', Contract::STATUS_INITIAL)->exists()) {
                throw new ScheduleRegenerationException('REVERT_BLOCKED', 'Payments were recorded on the new rows.');
            }
            if (PaymentEntry::where('contract_id', $contract->id)->where('created_at', '>', $record->created_at)->exists()) {
                throw new ScheduleRegenerationException('REVERT_BLOCKED', 'Payments were recorded after the regeneration.');
            }

            Payment::whereIn('id', $created)->forceDelete();
            Payment::withTrashed()->whereIn('id', $record->removed_payment_ids ?? [])->restore();

            foreach ($record->moved_entries ?? [] as $moved) {
                PaymentEntry::where('id', $moved['entry_id'])->update(['payment_id' => $moved['old_payment_id']]);
            }

            $before = $record->current_row_before;
            Payment::where('id', $record->current_payment_id)->update([
                'principal_payment'          => $before['principal_payment'],
                'original_principal_payment' => $before['original_principal_payment'],
                'amount'                     => $before['amount'],
            ]);

            $contract->payment_schedule = $record->old_payment_schedule;
            $contract->save();

            $record->reverted_at = now();
            $record->reverted_by = $userId;
            $record->save();

            $this->activityService->log(
                'revert_schedule_regeneration',
                "Reverted schedule regeneration #{$record->id} of contract #{$contract->id}",
                Contract::class,
                $contract->id
            );

            return $record;
        });
    }

    /** Active (not reverted) regeneration made at or after $since (e.g. a deal's created_at), if any. */
    public static function activeSince(int $contractId, $since): ?ScheduleRegeneration
    {
        return ScheduleRegeneration::where('contract_id', $contractId)
            ->whereNull('reverted_at')
            ->where('created_at', '>=', $since)
            ->first();
    }

    // ── Planning (no writes) ────────────────────────────────────────────────

    private function plan(Contract $contract, string $effectiveDate, ?int $paymentDay): array
    {
        $this->assertContractAllowed($contract);

        if ($paymentDay !== null && ($paymentDay < 1 || $paymentDay > 28)) {
            throw new ScheduleRegenerationException('INVALID_PAYMENT_DAY', 'Payment day must be between 1 and 28.');
        }

        $effective = Carbon::parse($effectiveDate, 'Asia/Yerevan')->startOfDay();

        $open = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', Contract::STATUS_INITIAL)
            ->orderBy('to_date')->orderBy('id')
            ->get();

        if ($open->isEmpty()) {
            throw new ScheduleRegenerationException('NO_FUTURE_ROWS', 'There are no open installments.');
        }

        $overdue = $open->filter(fn ($p) => Carbon::parse($p->to_date ?? $p->date)->startOfDay()->lt($effective));
        if ($overdue->isNotEmpty()) {
            $due = $overdue->sum(fn ($p) => max(0, (float) $p->amount - (float) $p->entries()->sum('amount')));
            throw new ScheduleRegenerationException('OVERDUE_ROWS', 'Overdue installments exist.', 422, ['amount' => round($due, 2)]);
        }
        $penalty = $this->paymentService->countPenalty($contract->id, $effective->toDateString());
        if ((float) ($penalty['penalty_amount'] ?? 0) > 0) {
            throw new ScheduleRegenerationException('OVERDUE_ROWS', 'Outstanding penalty exists.', 422, ['amount' => round((float) $penalty['penalty_amount'], 2)]);
        }

        $latestEntry = PaymentEntry::where('contract_id', $contract->id)->max('date');
        if ($latestEntry && Carbon::parse($latestEntry)->startOfDay()->gt($effective)) {
            throw new ScheduleRegenerationException('DATE_BEFORE_LAST_PAYMENT', 'Date is earlier than the last payment.');
        }

        if (Prepayment::where('contract_id', $contract->id)->where('status', 'unpaid')->exists()) {
            throw new ScheduleRegenerationException('UNPAID_PREPAYMENTS', 'Unpaid prepayments exist.');
        }

        $current  = $open->first();
        $replace  = $open->slice(1)->values();
        if ($replace->isEmpty()) {
            throw new ScheduleRegenerationException('NO_FUTURE_ROWS', 'There are no installments after the current one.');
        }

        $entries = PaymentEntry::whereIn('payment_id', $replace->pluck('id'))->get()->groupBy('payment_id');
        $moved   = collect();
        foreach ($replace as $index => $row) {
            foreach ($entries->get($row->id, collect()) as $entry) {
                $principalOnly = $entry->document_type === 'partial_payment'
                    && (float) $entry->interest_amount == 0.0
                    && (float) $entry->penalty_amount == 0.0;
                if (!$principalOnly) {
                    throw new ScheduleRegenerationException(
                        'ROW_HAS_COLLECTIONS',
                        'An installment to be replaced already has collected interest or penalty.',
                        422,
                        ['row' => $index + 2]
                    );
                }
                $moved->push($entry);
            }
        }
        $movedPrincipal = round((float) $moved->sum('principal_amount'), 2);

        $ownPrincipal    = (float) PaymentEntry::where('payment_id', $current->id)->sum('principal_amount');
        $unpaidCurrent   = max(0.0, (float) $current->principal_payment - $ownPrincipal);
        $balance         = round((float) $contract->provided_amount - $unpaidCurrent, 2);

        $replacedNet = (float) $replace->sum(fn ($p) => (float) $p->principal_payment)
            - (float) $replace->sum(fn ($p) => (float) $entries->get($p->id, collect())->sum('principal_amount'));
        if (abs($replacedNet - $balance) > 0.5) {
            throw new ScheduleRegenerationException(
                'INCONSISTENT_SCHEDULE',
                'Open rows do not add up to the contract balance.',
                422,
                ['rows' => round($replacedNet, 2), 'balance' => $balance]
            );
        }

        $periodStart   = Carbon::parse($current->to_date ?? $current->date, 'Asia/Yerevan')->startOfDay();
        $calendarStart = Carbon::parse($current->calendar_due_date ?: ($current->to_date ?? $current->date), 'Asia/Yerevan')->startOfDay();
        $anchorDay     = $paymentDay ?? ((int) $contract->payment_day ?: (int) $calendarStart->day);
        if ($anchorDay > 0 && $anchorDay !== (int) $calendarStart->day) {
            $calendarStart = $calendarStart->copy()->day(min($anchorDay, $calendarStart->daysInMonth));
        }

        try {
            $newRows = $this->calculator->build(
                $balance,
                (float) $contract->interest_rate,
                (float) ($contract->service_fee ?: 0),
                $periodStart,
                $calendarStart,
                Carbon::parse($contract->deadline, 'Asia/Yerevan')
            );
        } catch (\InvalidArgumentException $e) {
            throw new ScheduleRegenerationException('NO_FUTURE_ROWS', $e->getMessage());
        }

        $oldRows = $replace->map(function ($p) use ($entries) {
            $paidPrincipal = (float) $entries->get($p->id, collect())->sum('principal_amount');
            $principal     = (float) $p->principal_payment - $paidPrincipal;
            return [
                'date'      => (string) $p->to_date,
                'principal' => round($principal, 2),
                'interest'  => round((float) $p->interest_payment, 2),
                'total'     => round($principal + (float) $p->interest_payment, 2),
            ];
        })->all();

        $unchanged = $moved->isEmpty() && $this->sameRows($oldRows, $newRows);

        return [
            'contract'        => $contract,
            'effective'       => $effective->toDateString(),
            'current'         => $current,
            'replace'         => $replace,
            'replace_ids'     => $replace->pluck('id')->all(),
            'moved'           => $moved,
            'moved_principal' => $movedPrincipal,
            'balance'         => $balance,
            'anchor_day'      => $anchorDay,
            'old_rows'        => $oldRows,
            'new_rows'        => $newRows,
            'unchanged'       => $unchanged,
            'state_hash'      => $this->stateHash($contract, $open),
        ];
    }

    private function assertContractAllowed(Contract $contract): void
    {
        if (in_array($contract->status, [Contract::STATUS_EXECUTED, Contract::STATUS_CANCELLED, Contract::STATUS_TAKEN], true)
            || !$contract->isProvided()) {
            throw new ScheduleRegenerationException('CONTRACT_NOT_ACTIVE', 'Contract is not active.');
        }
        if ((float) $contract->provided_amount <= 0) {
            throw new ScheduleRegenerationException('NO_BALANCE', 'The contract has no balance.');
        }
        if ($contract->payment_type !== 'amortized') {
            throw new ScheduleRegenerationException('CONTRACT_NOT_ACTIVE', 'Only amortized contracts are supported.');
        }
    }

    private function sameRows(array $old, array $new): bool
    {
        if (count($old) !== count($new)) {
            return false;
        }
        foreach ($new as $i => $row) {
            if ($old[$i]['date'] !== $row['business_due_date']
                || abs($old[$i]['principal'] - $row['principal']) > 0.01
                || abs($old[$i]['interest'] - $row['interest']) > 0.01) {
                return false;
            }
        }
        return true;
    }

    private function stateHash(Contract $contract, Collection $open): string
    {
        $entries = PaymentEntry::where('contract_id', $contract->id);

        return sha1(json_encode([
            (string) $contract->provided_amount,
            $open->map(fn ($p) => [
                $p->id, (string) $p->updated_at, (string) $p->to_date,
                (string) $p->getRawOriginal('principal_payment'),
                (string) $p->getRawOriginal('interest_payment'),
                (string) $p->getRawOriginal('amount'),
            ])->all(),
            $entries->count(),
            round((float) $entries->sum('amount'), 2),
            Prepayment::where('contract_id', $contract->id)->where('status', 'unpaid')->count(),
        ]));
    }

    private function summary(array $plan): array
    {
        $new = $plan['new_rows'];
        $old = $plan['old_rows'];
        $cur = $plan['current'];

        // Same value before and after the write: relocated entries and the row's raised amount cancel out.
        $dueLeft = max(0.0, (float) $cur->amount - (float) $cur->entries()->sum('amount'));

        return [
            'effective_date' => $plan['effective'],
            'current_row'    => [
                'id'       => $cur->id,
                'due'      => (string) $cur->to_date,
                'due_left' => round($dueLeft, 2),
            ],
            'anchor_day'     => $plan['anchor_day'],
            'balance'        => $plan['balance'],
            'kept_rows'      => Payment::where('contract_id', $cur->contract_id)->where('type', 'regular')
                ->where('date', '<=', $cur->date)->count(),
            'removed_rows'   => count($old),
            'created_rows'   => count($new),
            'moved_entries'  => $plan['moved']->count(),
            'old' => [
                'monthly_min'    => $old ? min(array_column($old, 'total')) : 0,
                'monthly_max'    => $old ? max(array_column($old, 'total')) : 0,
                'total_interest' => round(array_sum(array_column($old, 'interest')), 2),
                'last_date'      => $old ? end($old)['date'] : null,
                'rows'           => $old,
            ],
            'new' => [
                'monthly'        => $new[0]['total'] ?? 0,
                'total_interest' => round(array_sum(array_column($new, 'interest')), 2),
                'last_date'      => $new ? end($new)['business_due_date'] : null,
                'rows'           => array_map(fn ($r) => [
                    'date' => $r['business_due_date'], 'from' => $r['from_date'], 'days' => $r['days'],
                    'principal' => $r['principal'], 'interest' => $r['interest'], 'total' => $r['total'],
                    'balance' => $r['balance_after'],
                ], $new),
            ],
            'warnings'   => $this->warnings($plan),
            'state_hash' => $plan['state_hash'],
        ];
    }

    private function warnings(array $plan): array
    {
        $warnings = [];
        $old = $plan['old_rows'];
        $new = $plan['new_rows'];
        if ($old && end($old)['date'] !== end($new)['business_due_date']) {
            $warnings[] = ['code' => 'LAST_ROW_DIFFERS_FROM_OLD', 'message' => 'The last installment date changes.'];
        }
        $contract = $plan['contract'];
        if (!$contract->payment_day && Carbon::parse($contract->date)->lt(Carbon::parse('2026-08-28'))) {
            $warnings[] = ['code' => 'LEGACY_RATE_CONVENTION', 'message' => 'Installment sizing differs slightly from the original schedule.'];
        }
        return $warnings;
    }

    // ── Writing ─────────────────────────────────────────────────────────────

    private function write(Contract $contract, array $plan, ?string $reason, ?int $userId): ScheduleRegeneration
    {
        $current = $plan['current'];

        $currentBefore = [
            'principal_payment'          => (string) $current->getRawOriginal('principal_payment'),
            'original_principal_payment' => (string) $current->getRawOriginal('original_principal_payment'),
            'amount'                     => (string) $current->getRawOriginal('amount'),
        ];
        $oldSchedule = $contract->payment_schedule;

        $movedLog = [];
        foreach ($plan['moved'] as $entry) {
            $movedLog[] = ['entry_id' => $entry->id, 'old_payment_id' => $entry->payment_id];
        }
        if ($movedLog) {
            PaymentEntry::whereIn('id', array_column($movedLog, 'entry_id'))->update(['payment_id' => $current->id]);
            $current->principal_payment          = (float) $current->getRawOriginal('principal_payment') + $plan['moved_principal'];
            $current->original_principal_payment = (float) $current->getRawOriginal('original_principal_payment') + $plan['moved_principal'];
            $current->amount                     = (float) $current->getRawOriginal('amount') + $plan['moved_principal'];
            $current->save();
        }

        Payment::whereIn('id', $plan['replace_ids'])->delete();

        $pgi = (int) Payment::withTrashed()->where('contract_id', $contract->id)->max('PGI_ID');
        $createdIds = [];

        foreach ($plan['new_rows'] as $row) {
            $payment = Payment::create([
                'contract_id'                => $contract->id,
                'PGI_ID'                     => ++$pgi,
                'date'                       => $row['business_due_date'],
                'to_date'                    => $row['business_due_date'],
                'calendar_due_date'          => $row['calendar_due_date'],
                'from_date'                  => $row['from_date'],
                'days'                       => $row['days'],
                'amount'                     => $row['total'],
                'original_amount'            => $row['total'],
                'principal_payment'          => $row['principal'],
                'original_principal_payment' => $row['principal'],
                'interest_payment'           => $row['interest'],
                'original_interest_payment'  => $row['interest'],
                'service_fee_payment'        => $row['service_fee'],
                'remaining'                  => $row['balance_after'],
                'is_broken_period'           => false,
                'last_payment'               => $row['is_last'],
                'kasko_amount'               => 0,
                'pawnshop_id'                => $current->pawnshop_id,
            ]);
            $createdIds[] = $payment->id;
        }

        $contract->payment_schedule = $this->scheduleJson($contract->id);
        $contract->save();

        $summary = $this->summary($plan);

        return ScheduleRegeneration::create([
            'contract_id'          => $contract->id,
            'user_id'              => $userId,
            'effective_date'       => $plan['effective'],
            'current_payment_id'   => $current->id,
            'anchor_day'           => $plan['anchor_day'],
            'reason'               => $reason,
            'balance'              => $plan['balance'],
            'old_total_interest'   => $summary['old']['total_interest'],
            'new_total_interest'   => $summary['new']['total_interest'],
            'old_monthly'          => $summary['old']['monthly_max'],
            'new_monthly'          => $summary['new']['monthly'],
            'old_last_date'        => $summary['old']['last_date'],
            'new_last_date'        => $summary['new']['last_date'],
            'removed_payment_ids'  => $plan['replace_ids'],
            'created_payment_ids'  => $createdIds,
            'moved_entries'        => $movedLog,
            'current_row_before'   => $currentBefore,
            'old_payment_schedule' => $oldSchedule,
        ]);

    }

    /** One JSON shape for the snapshot column, built from the regular rows. */
    private function scheduleJson(int $contractId): array
    {
        return Payment::where('contract_id', $contractId)->where('type', 'regular')
            ->orderBy('to_date')->orderBy('id')->get()
            ->map(fn ($p) => [
                'date'        => (string) $p->to_date,
                'payment'     => round((float) $p->amount, 3),
                'monthly_fee' => round((float) $p->service_fee_payment, 3),
                'total'       => round((float) $p->amount, 3),
                'principal'   => round((float) $p->principal_payment, 3),
                'interest'    => round((float) $p->interest_payment, 3),
                'balance'     => round((float) $p->remaining, 3),
            ])->all();
    }

    // ── Invariants ──────────────────────────────────────────────────────────

    /** Everything that must be identical after the write: all rows except the ones touched. */
    private function protectedChecksum(int $contractId, array $replaceIds): array
    {
        return [
            'completed' => Payment::withTrashed()->where('contract_id', $contractId)
                ->where(fn ($q) => $q->where('status', '!=', Contract::STATUS_INITIAL)->orWhere('type', '!=', 'regular'))
                ->orderBy('id')->get(['id', 'amount', 'principal_payment', 'interest_payment', 'status', 'deleted_at'])
                ->map(fn ($p) => [$p->id, (string) $p->getRawOriginal('amount'), (string) $p->getRawOriginal('principal_payment'),
                    (string) $p->getRawOriginal('interest_payment'), $p->status, (string) $p->deleted_at])->all(),
            'entries' => [
                PaymentEntry::where('contract_id', $contractId)->count(),
                round((float) PaymentEntry::where('contract_id', $contractId)->sum('amount'), 2),
                round((float) PaymentEntry::where('contract_id', $contractId)->sum('principal_amount'), 2),
                round((float) PaymentEntry::where('contract_id', $contractId)->sum('interest_amount'), 2),
            ],
        ];
    }

    private function assertInvariants(Contract $contract, array $plan, array $before): void
    {
        $after = $this->protectedChecksum($contract->id, $plan['replace_ids']);
        if ($after !== $before) {
            $this->fail('Completed rows or payment entries changed.');
        }

        $open = Payment::where('contract_id', $contract->id)->where('type', 'regular')
            ->where('status', Contract::STATUS_INITIAL)->orderBy('to_date')->orderBy('id')->get();

        $net = 0.0;
        foreach ($open as $row) {
            $net += (float) $row->principal_payment - (float) PaymentEntry::where('payment_id', $row->id)->sum('principal_amount');
        }
        if (abs($net - (float) $contract->provided_amount) > self::TOLERANCE) {
            $this->fail("Open principal {$net} differs from the balance {$contract->provided_amount}.");
        }

        $expectedLast = WorkingDays::next(Carbon::parse($contract->deadline, 'Asia/Yerevan')->startOfDay())->toDateString();
        $last = $open->last();
        if (!$last || (string) $last->to_date !== $expectedLast || abs((float) $last->remaining) > self::TOLERANCE) {
            $this->fail('The last installment is not the deadline.');
        }
    }

    private function fail(string $message): never
    {
        throw new ScheduleRegenerationException('INVARIANT_FAILED', $message, 500);
    }
}
