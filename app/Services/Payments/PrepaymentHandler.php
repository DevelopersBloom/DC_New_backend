<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Services\PrepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;


class PrepaymentHandler
{
    public function __construct(
        protected PrepaymentService    $prepaymentService,
        protected PaymentEntryRecorder $recorder,
    ) {}

    /**
     * Process one amortized installment under the prepayment mechanism.
     *
     * Interest is computed first (up to the scheduled interest_payment for this row),
     * then principal (up to the scheduled principal_payment). If paid before the due
     * date, only the interest actually accrued by the payment date (balance × elapsed
     * days × daily rate) is settled normally — the rest of the scheduled interest for
     * this row, plus the principal, go into the Prepayment record instead of posting
     * to the interest/loan accounts (→ liability account 39920).
     */
    public function handle(
        $contract, $payment, $payer, $cash, $deal_id,
        float $amount, float $interestAmount, ?string $date, float $balanceBefore
    ): array {
        $remainingAmount         = $amount;
        $remainingInterestAmount = $interestAmount;

        // ── Pay scheduled interest first ────────────────────────────────────
        $alreadyPaidInterest   = (float) $payment->entries()->sum('interest_amount');
        $remainingInterestPlan = max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
        $paidInterest          = min($remainingInterestPlan, $remainingAmount);
        $remainingInterestAmount -= $paidInterest;
        $remainingAmount         -= $paidInterest;

        // ── Pay scheduled principal ─────────────────────────────────────────
        $alreadyPaidPrincipal = (float) $payment->entries()->sum('principal_amount');
        $remainingPrincipal   = max(0, (float) ($payment->principal_payment ?? 0) - $alreadyPaidPrincipal);
        $paidPrincipal        = min($remainingAmount, $remainingPrincipal);
        $remainingAmount     -= $paidPrincipal;

        // Reduce loan balance immediately (prepayment commits the principal reduction)
        if ($paidPrincipal > 0) {
            $contract->left            = max(0, $contract->left - $paidPrincipal);
            $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipal);
        }

        // ── Record entry (complete or partial) ──────────────────────────────
        $totalPaid    = $paidInterest + $paidPrincipal;
        $alreadyPaid  = (float) $payment->entries()->sum('amount');
        $balanceAfter = (float) $contract->provided_amount;

        if ($alreadyPaid + $totalPaid >= (float) $payment->amount - 0.01) {
            $this->recorder->completePayment(
                $payment, $payer, $cash, $contract->id, $deal_id,
                $paidPrincipal, $paidInterest, $date, $balanceBefore, $balanceAfter, $totalPaid
            );
        } else {
            $this->recorder->partiallyCompletePayment(
                $payment, $totalPaid, $deal_id, [],
                $paidPrincipal, $paidInterest, $date, $balanceBefore, $balanceAfter
            );
        }

        // ── Before due date → only the accrued-to-date interest is recognized now;
        //    the not-yet-accrued remainder of this row's interest, plus principal,
        //    go into the Prepayment record instead (→ account 39920) ─────────────
        $due = Carbon::parse($payment->to_date ?? $payment->date)->startOfDay();
        $now = $date
            ? Carbon::parse($date, 'Asia/Yerevan')->startOfDay()
            : Carbon::now('Asia/Yerevan')->startOfDay();

        $prepaymentPrincipal = 0;
        if ($due->gt($now)) {
            $from        = Carbon::parse($payment->from_date)->startOfDay();
            $elapsedDays = max(0, $from->diffInDays($now));
            $rate        = (float) $contract->interest_rate;

            $accruedInterest  = min($paidInterest, $balanceBefore * $elapsedDays * $rate / 100);
            $deferredInterest = $paidInterest - $accruedInterest;
            $paidInterest     = $accruedInterest;

            if ($paidPrincipal > 0 || $deferredInterest > 0) {
                $this->prepaymentService->createSingle(
                    $contract->id, $payment->id, $deal_id, $paidPrincipal, $deferredInterest, $payment->to_date
                );
                $prepaymentPrincipal = $paidPrincipal + $deferredInterest;
                $paidPrincipal       = 0;
            }
        }

        return [
            'interest_amount'      => $paidInterest,
            'principal_amount'     => $paidPrincipal,
            'prepayment_principal' => $prepaymentPrincipal,
            'amount'               => $remainingAmount,
            'remaining_interest'   => $remainingInterestAmount,
        ];
    }

    /**
     * Prepayment mode: apply leftover cash to upcoming installments.
     *
     * Walks future payment rows in ascending date order, reduces loan balance for the
     * principal portion, creates/accumulates a Prepayment record covering interest +
     * principal for each row, and writes a PaymentEntry so subsequent calls see the
     * covered amounts.
     *
     * @param  int[]  $alreadyProcessedIds  IDs of rows already handled in the main loop (skip them)
     * @return float  Total interest + principal registered as prepayments
     */
    public function applyRemaining(
        $contract, float $remaining, $payer, $cash, ?int $dealId,
        ?string $date, array $alreadyProcessedIds
    ): float {
        $totalPrepaid = 0;

        $now = $date
            ? Carbon::parse($date, 'Asia/Yerevan')->startOfDay()
            : Carbon::now('Asia/Yerevan')->startOfDay();

        $futurePayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->whereNotIn('id', $alreadyProcessedIds)
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($futurePayments as $row) {
            if ($remaining <= 0) break;

            $due = Carbon::parse($row->to_date ?? $row->date)->startOfDay();
            if (!$due->gt($now)) continue; // only rows whose due date is still ahead

            $alreadyPaidInterest = (float) $row->entries()->sum('interest_amount');
            $remainingInterest   = max(0, (float) $row->interest_payment - $alreadyPaidInterest);
            $paidInterestPart    = min($remaining, $remainingInterest);

            $alreadyPaidPrincipal = (float) $row->entries()->sum('principal_amount');
            $remainingPrincipal   = max(0, (float) ($row->principal_payment ?? 0) - $alreadyPaidPrincipal);
            $paidPrincipalPart    = min($remaining - $paidInterestPart, $remainingPrincipal);

            $toPrepay = $paidInterestPart + $paidPrincipalPart;
            if ($toPrepay <= 0) continue;

            $remaining    -= $toPrepay;
            $totalPrepaid += $toPrepay;

            // Reduce loan balance for the principal portion only (mirrors handle() behaviour)
            $balanceBefore = (float) $contract->provided_amount;
            if ($paidPrincipalPart > 0) {
                $contract->left            = max(0, $contract->left - $paidPrincipalPart);
                $contract->provided_amount = max(0, $contract->provided_amount - $paidPrincipalPart);
            }
            $balanceAfter = (float) $contract->provided_amount;

            // Record a partial entry so future handle() calls see the covered interest/principal
            PaymentEntry::create([
                'payment_id'       => $row->id,
                'contract_id'      => $contract->id,
                'deal_id'          => $dealId,
                'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
                'user_id'          => auth()->id() ?? 1,
                'reference'        => Str::uuid(),
                'amount'           => $toPrepay,
                'principal_amount' => $paidPrincipalPart,
                'interest_amount'  => $paidInterestPart,
                'penalty_amount'   => 0,
                'balance_before'   => $balanceBefore,
                'balance_after'    => $balanceAfter,
                'document_type'    => 'prepayment_payment',
                'date'             => $date ?? Carbon::now()->format('Y-m-d'),
                'cash'             => $cash ?? false,
            ]);

            // Create or accumulate the Prepayment record for this installment
            $this->prepaymentService->createSingle(
                $contract->id, $row->id, $dealId, $paidPrincipalPart, $paidInterestPart, $row->to_date
            );
        }

        return $totalPrepaid;
    }
}
