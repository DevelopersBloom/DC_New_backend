<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Payment;
use App\Models\Prepayment;
use App\Models\Transaction;
use App\Traits\ContractTrait;
use Illuminate\Support\Carbon;

class PrepaymentApplicationService
{
    use ContractTrait;

    public function __construct(
        protected PaymentService $paymentService,
        protected ScheduledPaymentHandler $scheduledHandler,
        protected PaymentDateClassifier $dateClassifier,
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

        if ($partialAmount > 0) {
            $futurePayments = Payment::where('contract_id', $contract->id)
                ->where('type', 'regular')
                ->where('status', 'initial')
                ->orderBy('date', 'asc')
                ->get();

            if ($futurePayments->isNotEmpty()) {
                $timing = $this->dateClassifier->classify($contract, $date);
                $this->scheduledHandler->processAmortized($contract, $futurePayments, $partialAmount, $date, $timing);
            }
        }

        $prepayment->status  = 'paid';
        $prepayment->paid_at = Carbon::parse($date)->startOfDay();
        $prepayment->save();
    }
}
