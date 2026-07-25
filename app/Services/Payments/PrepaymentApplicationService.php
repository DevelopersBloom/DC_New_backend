<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Prepayment;
use App\Models\Transaction;
use App\Traits\ContractTrait;
use Illuminate\Support\Carbon;

/**
 * B3: applies a due Prepayment bucket entry.
 *
 * The row's own principal_amount + interest_amount were already reflected in
 * payment_entries at deposit time (via PrepaymentHandler::handle()); what's still
 * pending is the accounting side — reclassifying that money from the liability
 * account (39920) into the real loan/interest accounts, and actually reducing the
 * contract balance by principal_amount (deliberately deferred until now). Any
 * partial_amount (cash beyond what the row needed) reduces upcoming installments'
 * principal per R9, reusing PaymentService::payPartial().
 */
class PrepaymentApplicationService
{
    use ContractTrait;

    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    public function apply(Contract $contract, Prepayment $prepayment, ?string $date = null): void
    {
        $date = $date ?? Carbon::now()->format('Y-m-d');

        $principalAmount = (float) $prepayment->principal_amount;
        $interestAmount  = (float) $prepayment->interest_amount;
        $partialAmount   = (float) $prepayment->partial_amount;

        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->firstOrFail();

        $docNum = Transaction::getNextDocumentNumber();

        // Both principal_amount and partial_amount were credited to the liability
        // account (39920) together at deposit time (PrepaymentHandler::handle() ->
        // $prepaymentPrincipal = paidPrincipal + deferredInterest + partialAmount),
        // so the "principal" reclassification leg here must debit 39920 for their
        // sum too, or 39920 keeps a residual balance for partial_amount forever.
        $principalLegAmount = $principalAmount + $partialAmount;

        if ($principalLegAmount > 0) {
            $contract->left            = max(0, $contract->left - $principalLegAmount);
            $contract->provided_amount = max(0, $contract->provided_amount - $principalLegAmount);

            $rule = $this->getPostingRule('prepayment_apply_principal');
            $this->postEntry(
                $date, $docNum, DocumentJournal::PREPAYMENT_APPLY_PRINCIPAL,
                $principalLegAmount, 'prepayment_apply_principal',
                $rule->debit_account_id, $rule->credit_account_id,
                null, $journal->id, $contract->client_id, $contract->id, $rule, $contract
            );
        }

        if ($interestAmount > 0) {
            $rule = $this->getPostingRule('prepayment_apply_interest');
            $this->postEntry(
                $date, $docNum, DocumentJournal::PREPAYMENT_APPLY_INTEREST,
                $interestAmount, 'prepayment_apply_interest',
                $rule->debit_account_id, $rule->credit_account_id,
                null, $journal->id, $contract->client_id, $contract->id, $rule, $contract
            );
        }

        $contract->save();

        $prepayment->status  = 'paid';
        $prepayment->paid_at = Carbon::parse($date)->startOfDay();
        $prepayment->save();
    }
}
