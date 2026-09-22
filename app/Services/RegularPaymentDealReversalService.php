<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealAction;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\Pawnshop;
use App\Models\Prepayment;

/**
 * Reverts everything AdminControllerNew::handleRegularPaymentDeal() used to do
 * inline for a filter_type=payment ("regular payment") deal — built the same
 * way as FullPaymentDealReversalService: reverse() both performs the reversal
 * AND returns a table-by-table diff, so the same method can back a real delete
 * (inside a committed transaction) and a preview (inside a rolled-back one)
 * without the two ever drifting apart.
 *
 * Note on Contract::left / provided_amount: this service does NOT restore them
 * directly. They come back via a separate mechanism —
 * DocumentJournal::syncContractProvidedAmountOnMotherPaymentDelete() — which
 * fires automatically when $deal->delete()'s cascade deletes the deal's
 * PAY_MOTHER_AMOUNT journal row. An earlier attempt at this restored them
 * explicitly here too, which double-counted with that cascade. Only
 * `collected` needs restoring in this class.
 */
class RegularPaymentDealReversalService
{
    private array $sections = [];
    private array $warnings = [];

    public function __construct(
        private readonly FullPaymentDealReversalService $fullPaymentDealReversalService,
    ) {}

    public function reverse(Deal $deal): array
    {
        $this->sections = [];
        $this->warnings = [];

        $this->reverseCashboxMovement($deal);

        $dealActions = DealAction::where('deal_id', $deal->id)->get();
        $contract    = Contract::find($deal->contract_id);

        $collectedBefore = null;
        if ($contract) {
            $collectedBefore = (float) $contract->collected;
            $contract->collected = max(0, $collectedBefore - (float) ($deal->interest_amount ?? 0));
        }

        foreach ($dealActions as $dealAction) {
            $history = $dealAction->history ?? [];

            if ($dealAction->type === 'penalty' && $dealAction->actionable) {
                $actionable = $dealAction->actionable;
                $this->recordSection('payments', 'delete', [[
                    'id'     => $actionable->id,
                    'amount' => (float) ($actionable->amount ?? 0),
                ]]);
                $actionable->delete();
                continue;
            }

            if ($dealAction->description === 'Regular payment') {
                foreach (($history['payment_changes'] ?? []) as $change) {
                    $this->restorePaymentStatus((int) $change['payment_id']);
                }
                continue;
            }

            if (in_array($dealAction->description, ['Partial payment with amount reduction', 'Partial payment with schedule recount'])) {
                foreach (($history['payment_changes'] ?? []) as $change) {
                    $this->restorePaymentFields((int) $change['payment_id'], [
                        'amount'            => $change['old_amount'],
                        'paid'              => $change['old_paid'] ?? 0,
                        'date'              => $change['old_date'],
                        'principal_payment' => $change['old_principal'],
                        'interest_payment'  => $change['old_interest'],
                        'status'            => 'initial',
                    ]);
                }
                if (isset($history['mother_amount']['payment_id'])) {
                    $this->restorePaymentFields((int) $history['mother_amount']['payment_id'], [
                        'mother' => $history['mother_amount']['old_mother'],
                        'status' => 'initial',
                    ]);
                }
                continue;
            }

            // 'Partial payment contract changes' — left/provided_amount are restored
            // separately, by DocumentJournal::syncContractProvidedAmountOnMotherPaymentDelete()
            // when the deal's PAY_MOTHER_AMOUNT journal row is cascade-deleted below.
        }

        if ($contract) {
            $this->recordUpdate(
                'contracts',
                $contract->id,
                ['collected' => $collectedBefore],
                ['collected' => (float) $contract->collected]
            );
            $contract->save();
        }

        $this->deletePaymentEntries($deal);
        $this->deletePrepayments($deal);

        $skippedModifications = [];
        if ($contract) {
            $result = $this->fullPaymentDealReversalService->deleteModificationsForContract(
                $contract,
                (string) $deal->date,
                ['PrincipalAmount', 'PercentsPaid', 'AmountsPaid']
            );
            if (!empty($result['deleted'])) {
                $this->recordSection('modifications', 'delete', $result['deleted']);
            }
            foreach ($result['skipped'] as $skipped) {
                $this->warnings[] = "Modification ({$skipped['field_code']}) not deleted: {$skipped['reason']}.";
            }
            $skippedModifications = $result['skipped'];
        }

        $this->deleteDealActions($deal);

        $this->recordSection('deals', 'delete', [[
            'id'          => $deal->id,
            'filter_type' => $deal->filter_type,
            'amount'      => (float) $deal->amount,
            'type'        => $deal->type,
        ]]);
        $deal->delete();

        return [
            'sections'              => $this->sections,
            'warnings'              => $this->warnings,
            'skipped_modifications' => $skippedModifications,
        ];
    }

    private function reverseCashboxMovement(Deal $deal): void
    {
        if (!$deal->pawnshop_id || (float) $deal->amount <= 0) {
            return;
        }

        $pawnshop = Pawnshop::find($deal->pawnshop_id);
        if (!$pawnshop) {
            return;
        }

        $column = $deal->cash ? 'cashbox' : 'bank_cashbox';
        $before = (float) ($pawnshop->{$column} ?? 0);
        $delta  = (float) $deal->amount * ($deal->type === 'in' ? -1 : 1);
        $pawnshop->{$column} = $before + $delta;
        $pawnshop->save();

        $this->recordUpdate('pawnshops', $pawnshop->id, [$column => $before], [$column => $pawnshop->{$column}]);
    }

    private function restorePaymentStatus(int $paymentId): void
    {
        $payment = Payment::find($paymentId);
        if (!$payment) {
            return;
        }

        $before = ['status' => $payment->status];
        Payment::where('id', $paymentId)->update(['status' => 'initial']);
        $this->recordUpdate('payments', $paymentId, $before, ['status' => 'initial']);
    }

    private function restorePaymentFields(int $paymentId, array $after): void
    {
        $payment = Payment::find($paymentId);
        if (!$payment) {
            return;
        }

        $before = [];
        foreach (array_keys($after) as $column) {
            $before[$column] = $payment->{$column};
        }

        Payment::where('id', $paymentId)->update($after);
        $this->recordUpdate('payments', $paymentId, $before, $after);
    }

    private function deletePaymentEntries(Deal $deal): void
    {
        $rows = PaymentEntry::where('deal_id', $deal->id)->get(['id', 'document_type', 'amount']);
        if ($rows->isEmpty()) {
            return;
        }

        $this->recordSection('payment_entries', 'delete', $rows->map(fn ($e) => [
            'id' => $e->id, 'document_type' => $e->document_type, 'amount' => (float) $e->amount,
        ])->all());

        PaymentEntry::where('deal_id', $deal->id)->delete();
    }

    private function deletePrepayments(Deal $deal): void
    {
        $rows = Prepayment::where('deal_id', $deal->id)
            ->get(['id', 'status', 'principal_amount', 'interest_amount', 'partial_amount']);
        if ($rows->isEmpty()) {
            return;
        }

        $this->recordSection('prepayments', 'delete', $rows->map(fn ($p) => [
            'id'               => $p->id,
            'status'           => $p->status,
            'principal_amount' => (float) $p->principal_amount,
            'interest_amount'  => (float) $p->interest_amount,
            'partial_amount'   => (float) $p->partial_amount,
        ])->all());

        Prepayment::where('deal_id', $deal->id)->delete();
    }

    private function deleteDealActions(Deal $deal): void
    {
        $rows = DealAction::where('deal_id', $deal->id)->get(['id', 'type', 'amount']);
        if ($rows->isEmpty()) {
            return;
        }

        $this->recordSection('deal_actions', 'delete', $rows->map(fn ($a) => [
            'id' => $a->id, 'type' => $a->type, 'amount' => (float) $a->amount,
        ])->all());

        DealAction::where('deal_id', $deal->id)->delete();
    }

    private function recordSection(string $table, string $action, array $rows): void
    {
        $this->sections[] = ['table' => $table, 'action' => $action, 'rows' => $rows];
    }

    private function recordUpdate(string $table, int $rowId, array $before, array $after): void
    {
        $changes = [];
        foreach ($after as $column => $newValue) {
            $oldValue = $before[$column] ?? null;
            if ($oldValue != $newValue) {
                $changes[] = ['column' => $column, 'before' => $oldValue, 'after' => $newValue];
            }
        }

        if (empty($changes)) {
            return;
        }

        $this->sections[] = ['table' => $table, 'action' => 'update', 'row_id' => $rowId, 'changes' => $changes];
    }
}
