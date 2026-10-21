<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
use App\Models\DocumentJournal;
use App\Models\LoanApplication;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Cancels a contract that was created (POST /contracts) but never disbursed
 * (POST /contracts/pay-amount). Anything that already moved money must be
 * reversed through the deal / document journal flows instead.
 */
class ContractCancellationService
{
    public function __construct(protected ActivityService $activityService)
    {
    }

    /**
     * @throws HttpException 409 when the contract cannot be cancelled
     */
    public function cancel(int $contractId, ?string $reasonCode, string $reason, ?int $userId): Contract
    {
        return DB::transaction(function () use ($contractId, $reasonCode, $reason, $userId) {
            // Row lock so a concurrent pay-amount cannot disburse it halfway through.
            $contract = Contract::lockForUpdate()->findOrFail($contractId);

            if ($blocker = $this->blockingReason($contract)) {
                throw new HttpException(409, $blocker);
            }

            $contract->status             = Contract::STATUS_CANCELLED;
            $contract->cancelled_at       = now();
            $contract->cancelled_by       = $userId;
            $contract->cancel_reason_code = $reasonCode;
            $contract->cancel_reason      = $reason;
            $contract->save();

            $paymentCount = Payment::where('contract_id', $contract->id)->delete();

            // Free the collateral for a new contract; the ids stay in the activity log.
            $itemIds = $contract->items()->pluck('items.id')->all();
            $contract->items()->detach();

            $applicationIds = LoanApplication::where('contract_id', $contract->id)
                ->where('status', LoanApplication::STATUS_CONVERTED)
                ->pluck('id')
                ->all();
            if ($applicationIds !== []) {
                LoanApplication::whereIn('id', $applicationIds)->update([
                    'status'      => LoanApplication::STATUS_APPROVED,
                    'contract_id' => null,
                ]);
            }

            $this->activityService->log(
                'cancel_contract',
                sprintf(
                    'Cancelled contract #%s (%s): %s. Payments voided: %d; items detached: [%s]; loan applications reopened: [%s]',
                    $contract->num,
                    $reasonCode ?? 'no code',
                    $reason,
                    $paymentCount,
                    implode(',', $itemIds),
                    implode(',', $applicationIds)
                ),
                Contract::class,
                $contract->id
            );

            return $contract;
        });
    }

    /** Null when the contract can be cancelled, otherwise the reason it cannot. */
    public function blockingReason(Contract $contract): ?string
    {
        switch ($contract->status) {
            case Contract::STATUS_CANCELLED:
                return 'The contract is already cancelled.';
            case Contract::STATUS_COMPLETED:
            case Contract::STATUS_EXECUTED:
                return "The contract is {$contract->status} and cannot be cancelled.";
            case Contract::STATUS_INITIAL:
                break;
            default:
                return "A contract with status '{$contract->status}' cannot be cancelled.";
        }

        $disbursedMessage = 'The contract has already been disbursed (%s). Only contracts that were never '
            . 'disbursed can be cancelled; reverse the disbursement through the deal / document journal '
            . 'deletion flows instead.';

        if ($contract->provided_at !== null) {
            return sprintf($disbursedMessage, 'provided_at is set');
        }

        // Soft-deleted rows count too: they still mean money moved at some point.
        $checks = [
            'deals'                  => Deal::withTrashed()->where('contract_id', $contract->id),
            'orders'                 => Order::withTrashed()->where('contract_id', $contract->id),
            'journal entries'        => DocumentJournal::withTrashed()->where(function ($q) use ($contract) {
                $q->where('contract_id', $contract->id)
                    ->orWhere(fn ($q2) => $q2->where('journalable_type', Contract::class)
                        ->where('journalable_id', $contract->id));
            }),
            'transactions'           => Transaction::withTrashed()->where('contract_id', $contract->id),
            'provided amount history' => ContractAmountHistory::withTrashed()
                ->where('contract_id', $contract->id)
                ->where('amount_type', 'provided_amount'),
        ];

        foreach ($checks as $label => $query) {
            if ($query->exists()) {
                return sprintf($disbursedMessage, "it has {$label}");
            }
        }

        return null;
    }
}
