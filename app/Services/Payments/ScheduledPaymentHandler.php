<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Traits\ContractTrait;
use Illuminate\Support\Carbon;

/**
 * Handles all scheduled (non-prepayment) per-row payment logic:
 *  - Amortized rows: early-split calculation or standard interest→principal path
 *  - Classic rows: interest-only scheduled payment
 *  - Schedule recalculation after principal changes
 *  - Distribution of partial payments across the remaining schedule
 */
class ScheduledPaymentHandler
{
    use ContractTrait;

    public function __construct(
        protected PaymentEntryRecorder $recorder,
        protected PaymentDateClassifier $dateClassifier,
    ) {}

    // ── Per-row handlers ────────────────────────────────────────────────────

    /**
     * Amortized installment: SOONER payments go through the R10 "reduce principal"
     * split (C2); everything else falls back to the scheduled interest-first →
     * principal (past-due only) path.
     */
    public function handleAmortized(
        $contract, $payment, $payer, $cash, $deal_id,
        float $amount, float $interestAmount, ?string $date, float $balanceBefore
    ): array {
        $remainingAmount         = $amount;
        $remainingInterestAmount = $interestAmount;

        $timing = $this->dateClassifier->classifyAgainstDueDate($payment->to_date ?? $payment->date, $date);
        if ($timing === PaymentDateClassifier::SOONER) {
            return $this->handleSoonerPrincipal(
                $contract, $payment, $payer, $cash, $deal_id, $remainingAmount, $date, $balanceBefore
            );
        }

        // Scheduled path: interest first
        $alreadyPaidInterest   = (float) $payment->entries()->sum('interest_amount');
        $remainingInterestPlan = max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
        $paidInterest          = 0;
        if ($remainingInterestAmount > 0 && $remainingInterestPlan > 0) {
            $paidInterest            = min($remainingInterestAmount, $remainingInterestPlan, $amount);
            $remainingInterestAmount -= $paidInterest;
            $remainingAmount         -= $paidInterest;
        }

        // Then principal — only if the installment is past due
        $paidPrincipal = 0;
        if ($payment->to_date <= ($date ?? now()->format('Y-m-d'))) {
            $alreadyPaidPrincipal = (float) $payment->entries()->sum('principal_amount');
            $remainingPrincipal   = max(0, (float) $payment->principal_payment - $alreadyPaidPrincipal);
            $paidPrincipal        = min($remainingAmount, $remainingPrincipal);
            $remainingAmount     -= $paidPrincipal;

            $contract->left            = max(0, $contract->left - $paidPrincipal);
            $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipal);
        }
        $this->recorder->recordEntry(
            $contract, $payment, $payer, $cash, $deal_id,
            $paidInterest, $paidPrincipal, $amount, $date, $balanceBefore
        );

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => $paidPrincipal,
            'prepayment_principal' => 0,
            'amount'               => $remainingAmount,
            'remaining_interest'   => $remainingInterestAmount,
        ];
    }

    /**
     * Classic installment: pay up to the remaining scheduled amount (interest only).
     */
    public function handleClassic(
        $contract, $payment, $payer, $cash, $deal_id,
        float $amount, float $interestAmount, ?string $date, float $balanceBefore
    ): array {
        $alreadyPaid     = (float) $payment->entries()->sum('amount');
        $remainingDue    = max(0, (float) $payment->amount - $alreadyPaid);
        $paidInterest    = min($amount, $remainingDue);
        $remainingAmount = $amount - $paidInterest;

        $this->recorder->recordEntry(
            $contract, $payment, $payer, $cash, $deal_id,
            $paidInterest, 0, $amount, $date, $balanceBefore
        );

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => 0,
            'prepayment_principal' => 0,
            'amount'               => $remainingAmount,
            'remaining_interest'   => $interestAmount,
        ];
    }

    // ── Schedule recalculation ──────────────────────────────────────────────

    /**
     * Recompute interest and line amounts on all open regular installments
     * (e.g. after a partial payment or deal edit).
     */
    public function recalculateSchedule(Contract $contract, ?string $date = null): void
    {
        $payments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->orderBy('to_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if ($payments->isEmpty()) {
            return;
        }

        $this->recalculateInterest($contract, $payments, $date);
    }

    /**
     * Running-balance recalculation: interest for each period = balance × days × rate.
     *
     * Only the last row in the schedule gets its still-unpaid principal added back
     * into the balance for its own interest calc, since that portion hasn't
     * actually left the balance yet. Earlier rows use the balance as-is.
     */
    public function recalculateInterest(Contract $contract, $payments, $date = null, array $pendingPrincipalReductions = []): void
    {
        $payments = $payments->where('status', 'initial')
            ->sortBy(fn ($p) => [$p->date, $p->id ?? 0])
            ->values();

        $balance  = (float) $contract->provided_amount;
        $rate     = (float) $contract->interest_rate;
        $prevDate = Carbon::parse($contract->date);

        foreach ($payments as $payment) {
            $payment     = $this->normalizePaymentDates($payment, $contract);
            $paymentDate = Carbon::parse($payment->to_date)->startOfDay();
            $fromDate    = Carbon::parse($payment->from_date)->startOfDay();
            $now         = $date ?? now()->startOfDay();

            $selectedDate = $fromDate->lt($now) ? $now : $fromDate;
            $days         = max(1, $paymentDate->diffInDays($selectedDate));
            $prevDate     = $paymentDate;

            // Entries for the reduction currently being applied (processAmortized)
            // aren't persisted yet at this point, so they must be added explicitly —
            // otherwise the last row's balance add-back below double-counts principal
            // that's about to be paid off in this same request.
            $alreadyPaidPrincipal = (float) $payment->entries()->sum('principal_amount')
                + ($pendingPrincipalReductions[$payment->id] ?? 0);
            $principal            = (float) $payment->principal_payment;
            $balanceForRow        = $balance;
            if ($payment->id === $payments->last()->id) {
                $remainingPrincipal = max(0, $principal - $alreadyPaidPrincipal);
                $balanceForRow      = $balance + $remainingPrincipal;
                $principal          = $remainingPrincipal;
            }

            $interest  = $balanceForRow * $days * ($rate / 100);
            $diff      = $payment->interest_payment - $interest;
            $payment->interest_payment          = $interest;
            //$payment->original_interest_payment -= $diff;
            $payment->amount = $payment->interest_payment + $principal;

            if ((float) $payment->amount <= 0) {
                $payment->status = 'completed';
            }

            if ($balance < 0) {
                $balance = 0;
            }

            $payment->remaining = round($balance, 10);
            $payment->save();
        }
    }

    // ── Partial-payment schedule adjusters ─────────────────────────────────

    /**
     * Distribute a partial payment across amortized installments
     * (reduces principal per row, then recalculates interest on the new balances).
     */
    public function processAmortized(Contract $contract, $payments, $remainingPartial, $now): array
    {
        $payments = $payments->where('status', 'initial')
            ->sortBy(fn ($p) => [$p->date, $p->id ?? 0])
            ->values();

        $changes = [];

        foreach ($payments as $payment) {
            if ($remainingPartial <= 0) break;

            $alreadyPaidPrincipal = (float) PaymentEntry::where('payment_id', $payment->id)->sum('principal_amount');
            $effectiveRemaining   = max(0, (float) $payment->principal_payment - $alreadyPaidPrincipal);
            $reduction            = min($remainingPartial, $effectiveRemaining);
            if ($reduction <= 0) continue;

            $changes[] = [
                'payment_id'    => $payment->id,
                'old_amount'    => $payment->amount,
                'old_paid'      => $payment->paid,
                'old_principal' => $payment->principal_payment,
                'old_date'      => $payment->date,
                'old_interest'  => $payment->interest_payment,
                'reduction'     => $reduction,
            ];

            // Mutate in-memory only — recalculateInterest will set the correct
            // amount (new_principal + new_interest) and persist everything atomically.
            $remainingPartial -= $reduction;
            $payment->save();
        }
dd($changes);
        if (!empty($changes)) {
            $affectedPaymentIds = array_column($changes, 'payment_id');

            $affectedPayments = Payment::where('contract_id', $contract->id)
                ->where('type', 'regular')
                ->where('status', 'initial')
                ->whereIn('id', $affectedPaymentIds)
                ->orderBy('to_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $pendingPrincipalReductions = array_column($changes, 'reduction', 'payment_id');
            $this->recalculateInterest($contract, $affectedPayments, $now, $pendingPrincipalReductions);
        }

        foreach ($changes as &$change) {
            $p = Payment::find($change['payment_id']);
            if ($p) {
                $change['new_principal'] = $p->principal_payment;
                $change['new_interest']  = $p->interest_payment;
                $change['new_amount']    = $p->amount;
            }
        }
        unset($change);

        return $changes;
    }

    /**
     * Distribute a partial payment across classic installments
     * (reduces each future row's amount proportionally / by elapsed-day delta).
     */
    public function processClassic($payments, $contract, $partialAmount, $now): array
    {
        $history = [
            'payments'     => [],
            'mother_amount'=> null,
        ];
        $startedToChange = false;

        foreach ($payments as $index => $payment) {
            if ($payment->amount <= 0) continue;

            $payment     = $this->normalizePaymentDates($payment, $contract);
            $dateToCheck = Carbon::parse($payment->date);

            if ($dateToCheck->gt($now)) {
                $oldPaid      = $payment->paid;
                $oldAmount    = $payment->amount;
                $oldDate      = $payment->date;
                $oldPrincipal = $payment->principal_payment;
                $oldInterest  = $payment->interest_payment;

                if ($startedToChange) {
                    $coeff     = ($contract->left - $partialAmount) / $contract->left;
                    $newAmount = intval(ceil($oldAmount * $coeff / 10) * 10);
                } else {
                    $startedToChange = true;
                    $prevDate  = $index === 0 ? $contract->date : $payments[$index - 1]->date;
                    $daysLeft  = $payment->days - $now->diffInDays(Carbon::parse($prevDate));

                    $newAmount = $oldAmount
                        - $this->calcAmount($contract->left, $daysLeft, $contract->interest_rate)
                        + $this->calcAmount($contract->left - $partialAmount, $daysLeft, $contract->interest_rate);
                }

                $payment->amount = max(0, $newAmount);
                $payment->save();

                $history['payments'][] = [
                    'payment_id'    => $payment->id,
                    'old_amount'    => $oldAmount,
                    'new_amount'    => $payment->amount,
                    'old_paid'      => $oldPaid,
                    'old_date'      => $oldDate,
                    'old_principal' => $oldPrincipal,
                    'old_interest'  => $oldInterest,
                    'updated_at'    => $now->toDateTimeString(),
                ];
            }

            if ($payment->last_payment) {
                $this->updateLastPayment($payment, $contract->left - $partialAmount, $history);
            }
        }

        return $history;
    }

    // ── Public preview helper ───────────────────────────────────────────────

    /**
     * Read-only R10 split calculation for payment preview (no DB writes).
     */
    public function calculateEarlySplitPreview(
        Contract $contract, Payment $payment, float $cashAfterPenalty, ?string $paymentDate = null
    ): ?array {
        return $this->calculateSoonerPrincipalSplit($contract, $payment, $cashAfterPenalty, $paymentDate);
    }

    // ── Sooner + "reduce principal" (C2 / R10) ──────────────────────────────

    /**
     * C2/R10: interest_due = balance × elapsed days (from_date → payment date) × rate,
     * collected first; whatever cash remains is returned as principal_part, to be
     * applied as an ordinary principal reduction (R9) by the caller — which also
     * naturally recalculates this row's remaining interest (payment date → due
     * date) on the reduced balance.
     *
     * Q3: if cash doesn't even cover the accrued interest, all of it is taken as
     * interest and principal_part is 0 (no rejection).
     *
     * Pure — no DB writes — so it can also back the read-only preview endpoint.
     */
    public function calculateSoonerPrincipalSplit(
        Contract $contract, Payment $payment, float $cashAfterPenalty, ?string $paymentDate = null
    ): ?array {
        if ($payment->type !== 'regular' || $payment->status !== 'initial') {
            return null;
        }

        $timing = $this->dateClassifier->classifyAgainstDueDate($payment->to_date ?? $payment->date, $paymentDate);
        if ($timing !== PaymentDateClassifier::SOONER) {
            return null;
        }

        $from = Carbon::parse($payment->from_date)->startOfDay();
        $now  = $paymentDate
            ? Carbon::parse($paymentDate)->setTimezone('Asia/Yerevan')->startOfDay()
            : Carbon::now('Asia/Yerevan')->startOfDay();
        $elapsedDays = max(0, $from->diffInDays($now));
        $balance = (float) $contract->provided_amount;
        $rate    = (float) $contract->interest_rate;

        $alreadyPaidInterest = (float) $payment->entries()->sum('interest_amount');
        $interestDue         = max(0, $balance * $elapsedDays * $rate / 100 - $alreadyPaidInterest);

        $paidInterest  = min(max(0, $cashAfterPenalty), $interestDue);
        $principalPart = max(0, $cashAfterPenalty - $interestDue);

        return [
            'paid_interest'  => (float) $paidInterest,
            'principal_part' => (float) $principalPart,
        ];
    }

    private function handleSoonerPrincipal(
        $contract, $payment, $payer, $cash, $deal_id,
        float $remainingAmount, ?string $date, float $balanceBefore
    ): array {
        $split = $this->calculateSoonerPrincipalSplit($contract, $payment, $remainingAmount, $date);
        $paidInterest  = $split['paid_interest']  ?? 0.0;
        $principalPart = $split['principal_part'] ?? $remainingAmount;

        // Only the interest is recorded against this row here; principalPart flows
        // back as leftover 'amount' so the normal partial-payment path (R9) applies
        // it — first to this row's own principal, then spills to future rows, and
        // recalculates affected rows' interest on the reduced balance.
        $this->recorder->partiallyCompletePayment(
            $payment, $paidInterest, $deal_id, [],
            0, $paidInterest, $date, $balanceBefore, $balanceBefore
        );

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => 0,
            'prepayment_principal' => 0,
            'amount'               => $principalPart,
            'remaining_interest'   => 0,
        ];
    }

    private function updateLastPayment($payment, $newMother, &$history): void
    {
        $history['mother_amount'] = [
            'payment_id' => $payment->id,
            'old_mother' => $payment->mother,
            'new_mother' => $newMother,
        ];

        $payment->mother = $newMother;
        if ($payment->mother <= 0) $payment->status = 'completed';
        $payment->save();
    }

    /**
     * @deprecated Dead code — not currently called. Kept for future use.
     */
    private function applyExtraToFutureInterest(
        Contract $contract, float $extra, ?int $excludePaymentId = null
    ): void {
        $futurePayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->when($excludePaymentId, fn ($q) => $q->where('id', '!=', $excludePaymentId))
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($futurePayments as $payment) {
            if ($extra <= 0) break;

            $deduct = min($extra, (float) $payment->interest_payment);
            if ($deduct <= 0) continue;

           // $payment->interest_payment -= $deduct;
            $payment->amount            = max(0, $payment->amount - $deduct);
            $extra                     -= $deduct;

            if ((float) $payment->amount <= 0) {
                $payment->status = 'completed';
            }
            $payment->save();
        }
    }
}
