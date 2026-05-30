<?php

namespace App\Services;

use App\Models\ContractAmountHistory;
use App\Models\Deal;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class DealDeleteImpactService
{
    private const PAYMENT_FILTER_TYPES = [
        'payment',
        'partial_payment',
        'full_payment',
    ];

    public function getImpact(Deal $deal): array
    {
        $requiresLegacyRevert = in_array($deal->filter_type, self::PAYMENT_FILTER_TYPES, true);

        $dealActions = DealAction::where('deal_id', $deal->id)->orderByDesc('id')->get();
        $paymentsImpact = $this->collectPaymentsImpact($deal, $dealActions);
        $journals = DocumentJournal::where('deal_id', $deal->id)->get();
        $transactions = $this->collectTransactionsForJournals($journals);
        $contractHistories = ContractAmountHistory::where('deal_id', $deal->id)->get();

        $availableScopes = ['deal'];
        if ($deal->order_id || $deal->history_id) {
            $availableScopes[] = 'order_history';
        }
        if ($journals->isNotEmpty()) {
            $availableScopes[] = 'documents_journal';
            $availableScopes[] = 'transactions';
        }
        if ($paymentsImpact->isNotEmpty() || $deal->payment_id) {
            $availableScopes[] = 'payments';
        }
        if ($deal->contract_id && $requiresLegacyRevert) {
            $availableScopes[] = 'contract';
        }
        if ($dealActions->isNotEmpty()) {
            $availableScopes[] = 'deal_actions';
        }
        if ($contractHistories->isNotEmpty()) {
            $availableScopes[] = 'contract_amount_histories';
        }

        return [
            'deal_id' => $deal->id,
            'filter_type' => $deal->filter_type,
            'purpose' => $deal->purpose,
            'requires_legacy_revert' => $requiresLegacyRevert,
            'available_scopes' => array_values(array_unique($availableScopes)),
            'summary' => [
                'deal_actions_count' => $dealActions->count(),
                'payments_count' => $paymentsImpact->count(),
                'documents_journal_count' => $journals->count(),
                'transactions_count' => $transactions->count(),
                'contract_amount_histories_count' => $contractHistories->count(),
                'has_order' => (bool) $deal->order_id,
                'has_history' => (bool) $deal->history_id,
            ],
            'payments' => $paymentsImpact->take(20)->values()->all(),
            'documents_journal' => $journals->take(20)->map(fn ($j) => [
                'id' => $j->id,
                'document_type' => $j->document_type,
                'amount_amd' => $j->amount_amd,
                'date' => $j->date,
            ])->values()->all(),
            'transactions' => $transactions->take(20)->values()->all(),
            'deal_actions' => $dealActions->take(10)->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'amount' => $a->amount,
                'actionable_type' => $a->actionable_type,
                'actionable_id' => $a->actionable_id,
            ])->values()->all(),
            'contract_revert' => $requiresLegacyRevert
                ? $this->extractContractRevertPreview($dealActions)
                : null,
            'warnings' => $requiresLegacyRevert
                ? [[
                    'scope' => 'payments',
                    'message' => 'Payment deals use full revert of schedule and contract balances via deal action history.',
                ]]
                : [],
        ];
    }

    private function collectPaymentsImpact(Deal $deal, Collection $dealActions): Collection
    {
        $rows = collect();

        if ($deal->payment_id) {
            $payment = Payment::withTrashed()->find($deal->payment_id);
            if ($payment) {
                $rows->push([
                    'id' => $payment->id,
                    'source' => 'deal.payment_id',
                    'amount' => $payment->amount,
                    'paid' => $payment->paid,
                    'status' => $payment->status,
                    'action' => in_array($deal->filter_type, ['full_payment', 'partial_payment'], true)
                        ? 'force_delete_or_revert'
                        : 'revert_via_history',
                ]);
            }
        }

        foreach ($dealActions as $action) {
            if ($action->actionable_type === Payment::class && $action->actionable_id) {
                $payment = Payment::withTrashed()->find($action->actionable_id);
                if ($payment && !$rows->contains(fn ($r) => ($r['id'] ?? null) === $payment->id)) {
                    $rows->push([
                        'id' => $payment->id,
                        'source' => 'deal_action',
                        'amount' => $payment->amount,
                        'paid' => $payment->paid,
                        'status' => $payment->status,
                        'action' => 'revert_via_history',
                    ]);
                }
            }

            $history = $action->history;
            if (!is_array($history)) {
                continue;
            }
            foreach ($history['payment_changes'] ?? [] as $change) {
                $paymentId = $change['payment_id'] ?? null;
                if (!$paymentId || $rows->contains(fn ($r) => ($r['id'] ?? null) === $paymentId)) {
                    continue;
                }
                $rows->push([
                    'id' => $paymentId,
                    'source' => 'payment_changes_history',
                    'old_amount' => $change['old_amount'] ?? null,
                    'old_paid' => $change['old_paid'] ?? null,
                    'action' => 'restore_to_old_values',
                ]);
            }
        }

        return $rows;
    }

    private function collectTransactionsForJournals(Collection $journals): Collection
    {
        if ($journals->isEmpty()) {
            return collect();
        }

        return Transaction::query()
            ->where('transactionable_type', DocumentJournal::class)
            ->whereIn('transactionable_id', $journals->pluck('id'))
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'document_number' => $t->document_number,
                'document_type' => $t->document_type,
                'amount_amd' => $t->amount_amd,
                'debit_account_id' => $t->debit_account_id,
                'credit_account_id' => $t->credit_account_id,
                'journal_id' => $t->transactionable_id,
            ]);
    }

    private function extractContractRevertPreview(Collection $dealActions): ?array
    {
        foreach ($dealActions as $action) {
            $history = $action->history;
            if (!is_array($history) || empty($history['contract_changes'])) {
                continue;
            }

            return $history['contract_changes'];
        }

        return null;
    }
}
