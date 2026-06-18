<?php

namespace App\Services\Payments;

use App\Models\DealAction;
use App\Models\Payment;
use App\Models\PaymentEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Centralises all PaymentEntry / DealAction writes.
 * Knows nothing about interest calculations or business rules —
 * it only records what the calling handler already computed.
 */
class PaymentEntryRecorder
{
    /**
     * Mark the payment row as completed, write a PaymentEntry, and log a DealAction.
     */
    public function completePayment(
        $payment, $payer, $cash, $contract_id,
        $deal_id = null, $principal_payment = null, $interest_payment = null,
        $date = null, float $balanceBefore = 0, float $balanceAfter = 0,
        float $amountPaid = 0
    ): void {
        $oldAmount = $amountPaid > 0 ? $amountPaid : (float) $payment['amount'];
        $oldDate   = $payment['date'];

        $payment->status = 'completed';
        $payment->save();

        $meta = $payer ? [
            'another_payer' => true,
            'name'          => $payer['name'] ?? null,
            'surname'       => $payer['surname'] ?? null,
            'phone'         => $payer['phone'] ?? null,
        ] : null;

        PaymentEntry::create([
            'payment_id'       => $payment->id,
            'contract_id'      => $contract_id,
            'deal_id'          => $deal_id,
            'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
            'user_id'          => auth()->id() ?? 1,
            'reference'        => Str::uuid(),
            'amount'           => $oldAmount,
            'principal_amount' => $principal_payment ?? 0,
            'interest_amount'  => $interest_payment  ?? 0,
            'penalty_amount'   => 0,
            'balance_before'   => $balanceBefore,
            'balance_after'    => $balanceAfter,
            'document_type'    => 'regular_payment',
            'date'             => $date ?? Carbon::now()->format('Y-m-d'),
            'cash'             => $cash ?? false,
            'meta_data'        => $meta,
        ]);

        $history['payment_changes'][] = [
            'payment_id'    => $payment->id,
            'old_amount'    => $oldAmount,
            'old_date'      => $oldDate,
            'old_mother'    => $payment->mother,
            'old_principal' => $principal_payment,
            'old_interest'  => $interest_payment,
            'updated_at'    => now()->toDateTimeString(),
        ];

        DealAction::create([
            'deal_id'         => $deal_id,
            'actionable_id'   => $payment->id,
            'actionable_type' => Payment::class,
            'amount'          => $oldAmount,
            'type'            => 'regular',
            'description'     => 'Regular payment',
            'date'            => $date ?? Carbon::now()->format('Y-m-d'),
            'history'         => $history,
        ]);
    }

    /**
     * Write a PaymentEntry for a partial payment and log a DealAction.
     * The payment row status is NOT changed to completed.
     */
    public function partiallyCompletePayment(
        $payment, $paid, $deal_id = null, $history = [],
        float $principalPaid = 0, float $interestPaid = 0,
        $date = null, float $balanceBefore = 0, float $balanceAfter = 0
    ): void {
        $oldAmount = $payment->amount;
        $oldDate   = $payment->date;

        PaymentEntry::create([
            'payment_id'       => $payment->id,
            'contract_id'      => $payment->contract_id,
            'deal_id'          => $deal_id,
            'pawnshop_id'      => auth()->user()->pawnshop_id ?? 1,
            'user_id'          => auth()->id() ?? 1,
            'reference'        => Str::uuid(),
            'amount'           => $paid,
            'principal_amount' => $principalPaid,
            'interest_amount'  => $interestPaid,
            'penalty_amount'   => 0,
            'balance_before'   => $balanceBefore,
            'balance_after'    => $balanceAfter,
            'document_type'    => 'regular_payment',
            'date'             => $date ?? Carbon::now()->format('Y-m-d'),
            'cash'             => false,
        ]);

        $history['payment_changes'][] = [
            'payment_id'    => $payment->id,
            'old_amount'    => $oldAmount,
            'old_date'      => $oldDate,
            'old_principal' => $principalPaid,
            'old_interest'  => $interestPaid,
            'updated_at'    => now()->toDateTimeString(),
        ];

        DealAction::create([
            'deal_id'         => $deal_id,
            'actionable_id'   => $payment->id,
            'actionable_type' => Payment::class,
            'amount'          => $paid,
            'type'            => 'regular',
            'description'     => 'Regular payment',
            'date'            => $date ?? Carbon::now()->format('Y-m-d'),
            'history'         => $history,
        ]);
    }

    /**
     * Choose between completePayment and partiallyCompletePayment based on whether
     * $originalAmount covers the full remaining due on this payment row.
     * Also fills any rounding gap when principal should close the row.
     */
    public function recordEntry(
        $contract, $payment, $payer, $cash, $deal_id,
        float $paidInterest, float $paidPrincipal, float $originalAmount,
        ?string $date, float $balanceBefore
    ): void {
        $alreadyPaid    = (float) $payment->entries()->sum('amount');
        $totalRemaining = max(0, (float) $payment->amount - $alreadyPaid);
        $balanceAfter   = (float) $contract->provided_amount;

        if ($originalAmount >= $totalRemaining) {
            // Fill any rounding gap so entry totals match the due amount exactly
            if ($paidPrincipal == 0 && $totalRemaining > $paidInterest) {
                $gap           = $totalRemaining - $paidInterest;
                $paidPrincipal = $gap;
                $contract->left            = max(0, $contract->left - $gap);
                $contract->provided_amount = max(0, $contract->provided_amount - $gap);
                $balanceAfter              = (float) $contract->provided_amount;
            }
            $this->completePayment(
                $payment, $payer, $cash, $contract->id, $deal_id,
                $paidPrincipal, $paidInterest,
                $date, $balanceBefore, $balanceAfter, $totalRemaining
            );
        } else {
            $this->partiallyCompletePayment(
                $payment, $paidInterest + $paidPrincipal, $deal_id, [],
                $paidPrincipal, $paidInterest,
                $date, $balanceBefore, $balanceAfter
            );
        }
    }
}
