<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\Modification;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\PaymentEntry;
use App\Models\Prepayment;
use App\Models\Transaction;

/**
 * Reverts everything App\Http\Controllers\PaymentControllerNew::makeFullPayment()
 * (and the services it delegates to) writes for a single full-payment deal.
 *
 * reverse() both performs the reversal AND returns a table-by-table diff of what
 * changed, so the same method can be used for a real delete (inside a committed
 * DB transaction) and for a dry-run preview (inside a transaction that gets
 * rolled back) without the two ever drifting apart.
 */
class FullPaymentDealReversalService
{
    private array $sections = [];
    private array $warnings = [];

    public function reverse(Deal $deal): array
    {
        $this->sections = [];
        $this->warnings = [];

        $contract = Contract::find($deal->contract_id);

        $this->reverseSiblingRefundDeals($deal);
        $this->restoreInitialPayments($deal);

        $fullAction = DealAction::where('deal_id', $deal->id)->where('type', 'full')->first();

        // Delete history/order ourselves, decoupled from Deal::deleting's cascade,
        // since deleting the deal's own Contract-linked journal rows below can
        // trigger that cascade early (see DocumentJournal::deleting) and we don't
        // want history/order cleanup to depend on that timing.
        $this->deleteHistoryAndOrder($deal);
        $this->deleteDocumentsJournalAndTransactions($deal);

        if ($fullAction && is_array($fullAction->history) && isset($fullAction->history['contract_changes'])) {
            $this->restoreContractChanges($fullAction->history['contract_changes']);
        }

        $this->reverseCashboxMovement($deal);
        $this->deletePaymentEntries($deal);

        if ($contract) {
            $result = $this->deleteModificationsForContract(
                $contract,
                (string) $deal->date,
                ['PrincipalAmount', 'PercentsPaid', 'AmountsPaid', 'LoanStatus']
            );
            if (!empty($result['deleted'])) {
                $this->recordSection('modifications', 'delete', $result['deleted']);
            }
            foreach ($result['skipped'] as $skipped) {
                $this->warnings[] = "Modification ({$skipped['field_code']}) not deleted: {$skipped['reason']}.";
            }
        }

        $this->deleteContractAmountHistories($deal);
        $this->deleteDealActions($deal);
        $this->collectWarnings($deal, $contract);

        $paymentIdToDelete = $deal->payment_id;
        if ($paymentIdToDelete) {
            $deal->update(['payment_id' => null]);
        }

        $this->recordSection('deals', 'delete', [[
            'id'          => $deal->id,
            'filter_type' => $deal->filter_type,
            'amount'      => (float) $deal->amount,
            'type'        => $deal->type,
        ]]);
        $deal->delete();

        if ($paymentIdToDelete) {
            $payment = Payment::withTrashed()->find($paymentIdToDelete);
            if ($payment) {
                $this->recordSection('payments', 'delete', [[
                    'id'     => $payment->id,
                    'type'   => $payment->type,
                    'amount' => (float) $payment->amount,
                    'date'   => (string) $payment->date,
                ]]);
            }
            Payment::where('id', $paymentIdToDelete)->forceDelete();
        }

        return [
            'sections' => $this->sections,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Prepayment-bucket refund / early-payoff lump-sum refund deals spawned
     * alongside the main full-payment deal share its `payment_id`'s Payment
     * row via their DealAction.actionable — find and revert them before that
     * Payment row is force-deleted.
     */
    private function reverseSiblingRefundDeals(Deal $deal): void
    {
        if (!$deal->payment_id) {
            return;
        }

        $siblingDeals = Deal::where('contract_id', $deal->contract_id)
            ->where('id', '!=', $deal->id)
            ->whereIn('filter_type', [Order::REFUND_PREPAYMENT_FILTER, Order::REFUND_LUMP_FILTER])
            ->get();

        foreach ($siblingDeals as $siblingDeal) {
            $isLinked = DealAction::where('deal_id', $siblingDeal->id)
                ->where('actionable_type', Payment::class)
                ->where('actionable_id', $deal->payment_id)
                ->exists();

            if (!$isLinked) {
                continue;
            }

            $this->reverseCashboxMovement($siblingDeal);

            $actionRows = DealAction::where('deal_id', $siblingDeal->id)->get(['id', 'type', 'amount']);
            if ($actionRows->isNotEmpty()) {
                $this->recordSection('deal_actions', 'delete', $actionRows->map(fn ($a) => [
                    'id' => $a->id, 'type' => $a->type, 'amount' => (float) $a->amount,
                ])->all());
                DealAction::where('deal_id', $siblingDeal->id)->delete();
            }

            $this->recordSection('deals', 'delete', [[
                'id'          => $siblingDeal->id,
                'filter_type' => $siblingDeal->filter_type,
                'amount'      => (float) $siblingDeal->amount,
                'type'        => $siblingDeal->type,
            ]]);
            $siblingDeal->delete();
        }
    }

    private function restoreInitialPayments(Deal $deal): void
    {
        $rows = Payment::onlyTrashed()
            ->where('contract_id', $deal->contract_id)
            ->where('status', 'initial')
            ->get(['id', 'PGI_ID', 'amount', 'date']);

        if ($rows->isEmpty()) {
            return;
        }

        $this->recordSection('payments', 'restore', $rows->map(fn ($p) => [
            'id' => $p->id, 'PGI_ID' => $p->PGI_ID, 'amount' => (float) $p->amount, 'date' => (string) $p->date,
        ])->all());

        Payment::onlyTrashed()
            ->where('contract_id', $deal->contract_id)
            ->where('status', 'initial')
            ->restore();
    }

    private function deleteHistoryAndOrder(Deal $deal): void
    {
        if ($deal->history) {
            $this->recordSection('histories', 'delete', [[
                'id' => $deal->history->id, 'amount' => (float) $deal->history->amount,
            ]]);
            $deal->history->delete();
        }

        if ($deal->order) {
            $this->recordSection('orders', 'delete', [[
                'id' => $deal->order->id, 'amount' => (float) $deal->order->amount,
            ]]);
            $deal->order->delete();
        }
    }

    private function deleteDocumentsJournalAndTransactions(Deal $deal): void
    {
        $journals = DocumentJournal::where('deal_id', $deal->id)
            ->get(['id', 'document_type', 'amount_amd', 'date', 'comment']);

        // Some journals (e.g. the reserve entry posted inline in makeFullPayment)
        // don't carry deal_id, but are linked as a "child" of one of the deal_id
        // journals via journalable_type=DocumentJournal/journalable_id.
        // DocumentJournal::deleting() cascades to delete those children too (see
        // its $journal->journals()->get()->each(...) branch) — captured here so
        // the diff/preview reflects what actually gets deleted.
        $primaryIds = $journals->pluck('id')->all();
        $childJournals = $primaryIds
            ? DocumentJournal::where('journalable_type', DocumentJournal::class)
                ->whereIn('journalable_id', $primaryIds)
                ->get(['id', 'document_type', 'amount_amd', 'date', 'comment'])
            : collect();

        $journals = $journals->concat($childJournals)->unique('id')->values();

        if ($journals->isEmpty()) {
            return;
        }

        $this->recordSection('documents_journal', 'delete', $journals->map(fn ($j) => [
            'id' => $j->id, 'document_type' => $j->document_type, 'amount_amd' => (float) $j->amount_amd, 'date' => (string) $j->date,
        ])->all());

        $journalIds = $journals->pluck('id')->all();
        $transactions = Transaction::where('transactionable_type', DocumentJournal::class)
            ->whereIn('transactionable_id', $journalIds)
            ->get(['id', 'document_type', 'amount_amd', 'date']);

        if ($transactions->isNotEmpty()) {
            $this->recordSection('transactions', 'delete', $transactions->map(fn ($t) => [
                'id' => $t->id, 'document_type' => $t->document_type, 'amount_amd' => (float) $t->amount_amd, 'date' => (string) $t->date,
            ])->all());
        }

        // Guarded: some of these journals are journalable_type=Contract, which makes
        // DocumentJournal::deleting() call back into $journal->deal->delete() for this
        // same deal. Without the guard that re-enters Deal::deleting's own cascade,
        // re-querying and re-processing whatever journal rows are still left in this
        // very loop. The guard makes each row here get deleted exactly once.
        Deal::withDeletionGuard($deal->id, function () use ($deal) {
            DocumentJournal::where('deal_id', $deal->id)->get()->each(fn (DocumentJournal $j) => $j->delete());
        });
    }

    /**
     * Mirrors AdminControllerNew::restoreHistory()'s 'contract_changes' branch —
     * the only history key processFullPayment() ever writes for a full-payment
     * DealAction.
     */
    private function restoreContractChanges(array $historyItem): void
    {
        $contract = Contract::find($historyItem['contract_id']);
        if (!$contract) {
            return;
        }

        $before = [
            'left'             => $contract->left,
            'collected'        => $contract->collected,
            'provided_amount'  => $contract->provided_amount,
            'status'           => $contract->status,
            'closed_at'        => $contract->closed_at,
        ];

        $after = [
            'left'             => $historyItem['old_left'],
            'collected'        => $historyItem['old_collected'],
            'provided_amount'  => $historyItem['old_provided'],
            'status'           => 'initial',
            'closed_at'        => null,
        ];

        Contract::where('id', $historyItem['contract_id'])->update($after);

        $this->recordUpdate('contracts', $contract->id, $before, $after);
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
        $delta = (float) $deal->amount * ($deal->type === 'in' ? -1 : 1);
        $pawnshop->{$column} = $before + $delta;
        $pawnshop->save();

        $this->recordUpdate('pawnshops', $pawnshop->id, [$column => $before], [$column => $pawnshop->{$column}]);
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

    /**
     * Shared with AdminControllerNew::handleRegularPaymentDeal(), which has an
     * inline copy of the same three-field-code loop (PrincipalAmount,
     * PercentsPaid, AmountsPaid) — full payment additionally writes LoanStatus.
     *
     * @return array{deleted: array, skipped: array}
     */
    public function deleteModificationsForContract(Contract $contract, string $effectiveDate, array $fieldCodes): array
    {
        $deleted = [];
        $skipped = [];

        foreach ($fieldCodes as $fieldCode) {
            $matches = Modification::where('subject_type', Contract::class)
                ->where('subject_id', $contract->id)
                ->where('effective_date', $effectiveDate)
                ->where('field_code', $fieldCode)
                ->get();

            if ($matches->count() !== 1) {
                if ($matches->count() > 1) {
                    $skipped[] = ['field_code' => $fieldCode, 'reason' => 'ambiguous same-day match'];
                }
                continue;
            }

            $modification = $matches->first();
            if ($modification->is_sent) {
                $skipped[] = ['field_code' => $fieldCode, 'reason' => 'already sent to registry'];
                continue;
            }

            $deleted[] = [
                'id' => $modification->id,
                'field_code' => $modification->field_code,
                'old_value' => $modification->old_value,
                'new_value' => $modification->new_value,
            ];
            $modification->delete();
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    private function deleteContractAmountHistories(Deal $deal): void
    {
        $rows = ContractAmountHistory::where('deal_id', $deal->id)->get(['id', 'amount', 'amount_type', 'type']);
        if ($rows->isEmpty()) {
            return;
        }

        $this->recordSection('contract_amount_histories', 'delete', $rows->map(fn ($h) => [
            'id' => $h->id, 'amount' => (float) $h->amount, 'amount_type' => $h->amount_type, 'type' => $h->type,
        ])->all());

        ContractAmountHistory::where('deal_id', $deal->id)->delete();
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

    private function collectWarnings(Deal $deal, ?Contract $contract): void
    {
        if ($contract && $contract->client_id) {
            $hasOtherActiveContracts = Contract::where('client_id', $contract->client_id)
                ->where('id', '!=', $contract->id)
                ->where('status', 'initial')
                ->exists();

            if (!$hasOtherActiveContracts) {
                $this->warnings[] = 'This payoff may have triggered a client-wide reserve release '
                    . 'correction when it was made (no other open contracts for this client at the time). '
                    . 'That correction cannot be automatically reversed — verify reserve balances for this '
                    . 'client manually.';
            }
        }

        if ($deal->contract_id) {
            $paidPrepayments = Prepayment::where('contract_id', $deal->contract_id)
                ->where('status', 'paid')
                ->count();

            if ($paidPrepayments > 0) {
                $this->warnings[] = "{$paidPrepayments} prepayment record(s) for this contract are marked "
                    . "'paid' and cannot be automatically traced back to this deal — verify manually whether "
                    . 'they should be reverted to \'unpaid\'.';
            }
        }
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
