<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
use App\Models\DealAction;
use App\Models\DocumentJournal;
use App\Models\History;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Pawnshop;
use App\Models\PostingRule;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class DealUpdateService
{
  private const PAYMENT_PURPOSES = [
    'Ամբողջական վճարում',
    'Հերթական վճարում',
    'Մասնակի վճարում',
  ];

  private const PAYMENT_FILTER_TYPES = [
    'payment',
    'partial_payment',
    'full_payment',
    'regular',
    'partial',
    'full',
  ];

  public function updateMany(array $dealsData, array $globalScopes = []): void
  {
    DB::transaction(function () use ($dealsData, $globalScopes) {
      foreach ($dealsData as $dealData) {
        $scopes = $dealData['scopes'] ?? $globalScopes;
        if (isset($dealData['scopes'])) {
          unset($dealData['scopes']);
        }
        $this->updateOne($dealData, is_array($scopes) ? $scopes : []);
      }
    });
  }

  public function hasAnyScope(array $scopes): bool
  {
    return count(array_filter($scopes)) > 0;
  }

  public function updateOne(array $dealData, array $scopes = []): void
  {
    $deal = Deal::findOrFail($dealData['id']);

    $oldAmount = (float) ($deal->amount ?? 0);
    $oldInterest = (float) ($deal->interest_amount ?? 0);
    $oldPenalty = (float) ($deal->penalty ?? 0);
    $oldCash = (bool) $deal->cash;
    $oldDate = (string) $deal->date;

    $newAmount = (float) ($dealData['amount'] ?? $oldAmount);
    $newInterest = (float) ($dealData['interest_amount'] ?? $oldInterest);
    $newPenalty = (float) ($dealData['penalty'] ?? $oldPenalty);
    $newCash = array_key_exists('cash', $dealData) ? (bool) $dealData['cash'] : $oldCash;
    $newDate = (string) ($dealData['date'] ?? $oldDate);

    $oldPrincipal = $this->resolvePrincipal($deal, $oldAmount, $oldInterest, $oldPenalty);
    $newPrincipal = array_key_exists('principal_amount', $dealData) && $dealData['principal_amount'] !== null
      ? (float) $dealData['principal_amount']
      : $this->resolvePrincipal($deal, $newAmount, $newInterest, $newPenalty);

    Deal::query()->whereKey($deal->id)->update([
      'amount' => $newAmount,
      'interest_amount' => $newInterest,
      'penalty' => $newPenalty,
      'principal_amount' => $newPrincipal,
      'cash' => $newCash,
      'date' => $newDate,
    ]);

    $deal->refresh();

    if (!$this->hasAnyScope($scopes)) {
      return;
    }

    if (!empty($scopes['pawnshop'])) {
      $this->adjustPawnshopCashbox($deal, $oldAmount, $oldCash, $newAmount, $newCash);
    }

    if (!empty($scopes['order']) || !empty($scopes['history']) || !empty($scopes['order_history'])) {
      $this->syncOrder($deal, $newAmount, $newCash, $newDate);
      $this->syncHistory($deal, $newAmount, $newInterest, $newPenalty, $newDate);
    }

    if (!empty($scopes['deal_actions'])) {
      $this->syncDealActions($deal, $newAmount, $newInterest, $newPrincipal, $newPenalty, $newDate);
    }

    if (!empty($scopes['payments'])) {
      $this->syncPayment($deal, $newAmount, $newInterest, $newPrincipal, $newPenalty, $newDate, $newCash);
    }

    if (!empty($scopes['contract'])) {
      $this->syncContract($deal, $oldInterest, $oldPenalty, $oldPrincipal, $newInterest, $newPenalty, $newPrincipal);
    }

    if (!empty($scopes['documents_journal']) || !empty($scopes['transactions']) || !empty($scopes['accounting'])) {
      $this->syncAccounting($deal, $newInterest, $newPrincipal, $newPenalty, $newCash);
    }

    if ($oldDate !== $newDate) {
      $this->syncRelatedDates($deal, $newDate);
    }
  }

  private function resolvePrincipal(Deal $deal, float $amount, float $interest, float $penalty): float
  {
    if ($deal->purpose && in_array($deal->purpose, self::PAYMENT_PURPOSES, true)) {
      return max(0, $amount - $interest - $penalty);
    }

    return max(0, $amount - $interest - $penalty);
  }

  private function adjustPawnshopCashbox(Deal $deal, float $oldAmount, bool $oldCash, float $newAmount, bool $newCash): void
  {
    if (!$deal->pawnshop_id) {
      return;
    }

    $pawnshop = Pawnshop::find($deal->pawnshop_id);
    if (!$pawnshop) {
      return;
    }

    $this->applyCashboxMovement($pawnshop, $deal->type, $oldAmount, $oldCash, false);
    $this->applyCashboxMovement($pawnshop, $deal->type, $newAmount, $newCash, true);
    $pawnshop->save();

    $deal->setRelation('pawnshop', $pawnshop);
  }

  private function applyCashboxMovement(Pawnshop $pawnshop, ?string $type, float $amount, bool $cash, bool $apply): void
  {
    if ($amount <= 0 || !$type) {
      return;
    }

    $sign = $apply ? 1 : -1;
    $delta = $amount * $sign;

    if ($type === Deal::IN_DEAL) {
      if ($cash) {
        $pawnshop->cashbox = ($pawnshop->cashbox ?? 0) + $delta;
      } else {
        $pawnshop->bank_cashbox = ($pawnshop->bank_cashbox ?? 0) + $delta;
      }
      return;
    }

    if (in_array($type, [Deal::OUT_DEAL, Deal::COST_OUT_DEAL, Deal::EXPENSE_DEAL], true)) {
      if ($cash) {
        $pawnshop->cashbox = ($pawnshop->cashbox ?? 0) - $delta;
      } else {
        $pawnshop->bank_cashbox = ($pawnshop->bank_cashbox ?? 0) - $delta;
      }
    }
  }

  private function syncOrder(Deal $deal, float $amount, bool $cash, string $date): void
  {
    if (!$deal->order_id) {
      return;
    }

    Order::where('id', $deal->order_id)->update([
      'amount' => $amount,
      'cash' => $cash,
      'date' => $date,
    ]);
  }

  private function syncHistory(Deal $deal, float $amount, float $interest, float $penalty, string $date): void
  {
    if (!$deal->history_id) {
      return;
    }

    History::where('id', $deal->history_id)->update([
      'amount' => $amount,
      'interest_amount' => $interest,
      'penalty' => $penalty,
      'date' => $date,
    ]);
  }

  private function syncRelatedDates(Deal $deal, string $date): void
  {
    if (in_array($deal->filter_type, self::PAYMENT_FILTER_TYPES, true)) {
      DealAction::where('deal_id', $deal->id)->update(['date' => $date]);

      DealAction::where('deal_id', $deal->id)->each(function (DealAction $dealAction) use ($date) {
        if ($dealAction->actionable) {
          $dealAction->actionable->update(['date' => $date]);
        }
      });

      ContractAmountHistory::where('deal_id', $deal->id)->update(['date' => $date]);
    }
  }

  private function syncAccounting(Deal $deal, float $interest, float $principal, float $penalty, bool $cash): void
  {
    $journals = DocumentJournal::where('deal_id', $deal->id)->get();
    $totalAmount = (float) $deal->fresh()->amount;
    $classification = $this->resolveContractClassificationName($deal->contract_id);

    $splitTargets = [
      DocumentJournal::PAY_INTEREST_AMOUNT => $interest,
      DocumentJournal::PAY_MOTHER_AMOUNT => $principal,
      DocumentJournal::PAY_MOTHER_AMOUNT_CASH => $principal,
      DocumentJournal::PAY_PENALTY_AMOUNT => $penalty,
      DocumentJournal::RECOVERY_INTEREST => $interest,
      DocumentJournal::RECOVERY_PRINCIPAL => $principal,
    ];

    $handledTypes = array_keys($splitTargets);

    foreach ($splitTargets as $documentType => $targetTotal) {
      $group = $journals->where('document_type', $documentType)->sortBy('id')->values();
      if ($group->isEmpty()) {
        continue;
      }

      $amounts = $this->sequentialJournalAmounts($group, (float) $targetTotal);
      foreach ($group as $index => $journal) {
        $newAmount = $amounts[$index] ?? 0.0;
        $this->applyJournalAmountUpdate($deal, $journal, $newAmount, $cash, $deal->date, $classification);
      }
    }

    foreach ($journals as $journal) {
      if (in_array($journal->document_type, $handledTypes, true)) {
        continue;
      }

      // For unsupported document types, keep amount as-is and only sync date/cash/accounts.
      $this->applyJournalMetadataUpdate($deal, $journal, $cash, $deal->date, $classification);
    }
  }

  /**
   * When several journal lines share one economic total (e.g. makePayment + partial mother posting),
   * reallocate the new total in row order:
   * - Decrease: take from the smallest amount line first until it hits zero, then the next smallest, etc.
   * - Increase: add the full increase to the smallest amount line first (lower id breaks ties),
   *   matching the reverse of decreases (same sort order as reductions).
   *
   * Returned amounts are in the same order as $journals.
   *
   * @param  \Illuminate\Support\Collection<int, DocumentJournal>  $journals
   * @return list<float>
   */
  private function sequentialJournalAmounts($journals, float $targetTotal): array
  {
    $count = $journals->count();
    if ($count === 0) {
      return [];
    }

    if ($count === 1) {
      return [$targetTotal];
    }

    $rowKey = static function (DocumentJournal $j): string {
      return $j->id !== null ? 'id:' . (string) (int) $j->id : 'obj:' . spl_object_id($j);
    };

    $newByKey = [];
    foreach ($journals as $j) {
      $newByKey[$rowKey($j)] = max(0.0, (float) ($j->amount_amd ?? 0));
    }

    $sumOld = array_sum($newByKey);

    if ($targetTotal <= $sumOld + 1e-9) {
      $toRemove = $sumOld - $targetTotal;
      $sorted = $journals->sort(function (DocumentJournal $a, DocumentJournal $b) {
        $av = (float) ($a->amount_amd ?? 0);
        $bv = (float) ($b->amount_amd ?? 0);
        if (abs($av - $bv) > 1e-9) {
          return $av <=> $bv;
        }

        return ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0));
      })->values();

      foreach ($sorted as $journal) {
        if ($toRemove <= 1e-9) {
          break;
        }
        $k = $rowKey($journal);
        $current = $newByKey[$k];
        $removable = min($current, $toRemove);
        $newByKey[$k] = $current - $removable;
        $toRemove -= $removable;
      }
    } else {
      $toAdd = $targetTotal - $sumOld;
      $sortedAsc = $journals->sort(function (DocumentJournal $a, DocumentJournal $b) {
        $av = (float) ($a->amount_amd ?? 0);
        $bv = (float) ($b->amount_amd ?? 0);
        if (abs($av - $bv) > 1e-9) {
          return $av <=> $bv;
        }

        return ((int) ($a->id ?? 0)) <=> ((int) ($b->id ?? 0));
      })->values();

      $smallest = $sortedAsc->first();
      $newByKey[$rowKey($smallest)] += $toAdd;
    }

    return $journals->map(fn (DocumentJournal $j) => $newByKey[$rowKey($j)])->values()->all();
  }

  private function applyJournalAmountUpdate(
    Deal $deal,
    DocumentJournal $journal,
    float $amountAmd,
    bool $cash,
    string $date,
    ?string $classification = null
  ): void
  {
    $journalUpdates = [
      'amount_amd' => $amountAmd,
      'cash' => $cash,
      'date' => $date,
    ];

    $rule = $this->resolvePostingRuleForJournal($deal, $journal->document_type, $cash, $classification);
    if ($rule) {
      $journalUpdates['debit_account_id'] = $rule->debit_account_id;
      $journalUpdates['credit_account_id'] = $rule->credit_account_id;
    }

    $journal->update($journalUpdates);

    $transactionUpdates = [
      'amount_amd' => $amountAmd,
      'date' => $date,
    ];

    if ($rule) {
      $transactionUpdates['debit_account_id'] = $rule->debit_account_id;
      $transactionUpdates['credit_account_id'] = $rule->credit_account_id;
    }

    Transaction::where('transactionable_type', DocumentJournal::class)
      ->where('transactionable_id', $journal->id)
      ->update($transactionUpdates);
  }

  private function applyJournalMetadataUpdate(
    Deal $deal,
    DocumentJournal $journal,
    bool $cash,
    string $date,
    ?string $classification = null
  ): void
  {
    $journalUpdates = [
      'cash' => $cash,
      'date' => $date,
    ];

    $transactionUpdates = [
      'date' => $date,
    ];

    $rule = $this->resolvePostingRuleForJournal($deal, $journal->document_type, $cash, $classification);
    if ($rule) {
      $journalUpdates['debit_account_id'] = $rule->debit_account_id;
      $journalUpdates['credit_account_id'] = $rule->credit_account_id;
      $transactionUpdates['debit_account_id'] = $rule->debit_account_id;
      $transactionUpdates['credit_account_id'] = $rule->credit_account_id;
    }

    $journal->update($journalUpdates);
    Transaction::where('transactionable_type', DocumentJournal::class)
      ->where('transactionable_id', $journal->id)
      ->update($transactionUpdates);
  }

  private function resolvePostingRuleForJournal(
    Deal $deal,
    ?string $documentType,
    bool $cash,
    ?string $classification = null
  ): ?PostingRule
  {
    if (!$deal->contract_id) {
      return null;
    }
    $classification = $classification ?? $this->resolveContractClassificationName($deal->contract_id);

    $eventFilter = match ($documentType) {
      DocumentJournal::PAY_INTEREST_AMOUNT => $classification === 'loss'
        ? 'pay_interest_amount_loss'
        : ($cash ? 'pay_interest_amount_cash' : 'pay_interest_amount'),
      DocumentJournal::PAY_MOTHER_AMOUNT,
      DocumentJournal::PAY_MOTHER_AMOUNT_CASH => $cash
        ? 'pay_mother_amount_cash'
        : 'pay_mother_amount',
      DocumentJournal::PAY_PENALTY_AMOUNT => $classification === 'loss'
        ? 'pay_penalty_amount_loss'
        : ($cash ? 'pay_penalty_amount_cash' : 'pay_penalty_amount'),
      DocumentJournal::RECOVERY_PRINCIPAL => 'recovery_principal',
      DocumentJournal::RECOVERY_INTEREST => 'recovery_interest',
      default => null,
    };

    if (!$eventFilter) {
      return null;
    }

    return PostingRule::where('business_event_filter', $eventFilter)->first();
  }

  private function resolveContractClassificationName(?int $contractId): ?string
  {
    if (!$contractId) {
      return null;
    }

    return Contract::query()
      ->where('id', $contractId)
      ->with('client.classification:id,name')
      ->first()
      ?->client
      ?->classification
      ?->name;
  }

  private function journalAmountForType(?string $documentType, float $interest, float $principal, float $penalty, float $totalAmount): ?float
  {
    return match ($documentType) {
      DocumentJournal::PAY_INTEREST_AMOUNT => $interest,
      DocumentJournal::PAY_MOTHER_AMOUNT => $principal,
      DocumentJournal::PAY_PENALTY_AMOUNT => $penalty,
      default => $totalAmount,
    };
  }

  private function syncContract(
    Deal $deal,
    float $oldInterest,
    float $oldPenalty,
    float $oldPrincipal,
    float $newInterest,
    float $newPenalty,
    float $newPrincipal
  ): void {
    if (!$deal->contract_id) {
      return;
    }

    $contract = Contract::find($deal->contract_id);
    if (!$contract) {
      return;
    }

    $deltaInterest = $newInterest - $oldInterest;
    $deltaPenalty = $newPenalty - $oldPenalty;
    $deltaPrincipal = $newPrincipal - $oldPrincipal;

    if ($deltaInterest != 0) {
      $contract->collected = max(0, (float) ($contract->collected ?? 0) + $deltaInterest);
    }

    if ($deltaPrincipal != 0) {
      $contract->left = max(0, (float) ($contract->left ?? 0) - $deltaPrincipal);
      if ($contract->payment_type === 'amortized') {
        $contract->provided_amount = max(0, (float) ($contract->provided_amount ?? 0) - $deltaPrincipal);
      }
    }

    if ($deltaPenalty != 0) {
      $contract->penalty_amount = max(0, (float) ($contract->penalty_amount ?? 0) - $deltaPenalty);
    }

    $contract->save();
  }

  private function syncDealActions(
    Deal $deal,
    float $amount,
    float $interest,
    float $principal,
    float $penalty,
    string $date
  ): void {
    $regularActions = DealAction::where('deal_id', $deal->id)
      ->where('type', 'regular')
      ->get();
    $oldRegularTotal = (float) $regularActions->sum('amount');

    DealAction::where('deal_id', $deal->id)->each(function (DealAction $action) use (
      $amount,
      $interest,
      $principal,
      $penalty,
      $date,
      $regularActions,
      $oldRegularTotal
    ) {
      $updates = ['date' => $date];

      if ($action->type === 'penalty') {
        $updates['amount'] = $penalty;
      } elseif ($action->type === 'regular') {
        if ($oldRegularTotal > 0) {
          $updates['amount'] = $principal * ((float) $action->amount / $oldRegularTotal);
        } elseif ($regularActions->count() > 0) {
          $updates['amount'] = $principal / $regularActions->count();
        } else {
          $updates['amount'] = max(0, $amount - $interest - $penalty);
        }
      } elseif ($action->type === 'partial') {
        $updates['amount'] = $principal;
      }

      $action->update($updates);
    });
  }


  private function syncPayment(
    Deal $deal,
    float $amount,
    float $interest,
    float $principal,
    float $penalty,
    string $date,
    bool $cash
  ): void {
    if ($deal->payment_id) {
      Payment::whereKey($deal->payment_id)->update([
        'interest_payment' => $interest,
        'principal_payment' => $principal,
        'paid' => $amount,
        'amount' => $amount,
        'date' => $date,
        'cash' => $cash,
      ]);
    }

    $regularActions = DealAction::where('deal_id', $deal->id)->where('type', 'regular')->get();
    $oldRegularTotal = (float) $regularActions->sum('amount');

    DealAction::where('deal_id', $deal->id)->with('actionable')->each(function (DealAction $action) use (
      $amount,
      $interest,
      $principal,
      $penalty,
      $date,
      $cash,
      $regularActions,
      $oldRegularTotal
    ) {
      if (!($action->actionable instanceof Payment)) {
        return;
      }

      $updates = ['date' => $date, 'cash' => $cash];

      if ($action->type === 'penalty') {
        $updates['paid'] = $penalty;
        $updates['penalty'] = $penalty;
      } elseif ($action->type === 'regular') {
        $share = $oldRegularTotal > 0
          ? $principal * ((float) $action->amount / $oldRegularTotal)
          : ($regularActions->count() > 0 ? $principal / $regularActions->count() : $principal);
        $updates['principal_payment'] = $share;
        $updates['paid'] = $share;
        $interestShare = $regularActions->count() > 0 ? $interest / $regularActions->count() : $interest;
        $updates['interest_payment'] = $interestShare;
      } elseif ($action->type === 'partial') {
        $updates['principal_payment'] = $principal;
        $updates['interest_payment'] = $interest;
        $updates['paid'] = $amount;
      }

      $action->actionable->update($updates);
    });
  }

  public function previewUpdate(int $dealId, array $proposed): array
  {
    $deal = Deal::with(['contract.client.classification'])->findOrFail($dealId);

    $oldAmount = (float) ($deal->amount ?? 0);
    $oldInterest = (float) ($deal->interest_amount ?? 0);
    $oldPenalty = (float) ($deal->penalty ?? 0);
    $oldCash = (bool) $deal->cash;
    $oldDate = (string) $deal->date;

    $newAmount = (float) ($proposed['amount'] ?? $oldAmount);
    $newInterest = (float) ($proposed['interest_amount'] ?? $oldInterest);
    $newPenalty = (float) ($proposed['penalty'] ?? $oldPenalty);
    $newCash = array_key_exists('cash', $proposed) ? (bool) $proposed['cash'] : $oldCash;
    $newDate = (string) ($proposed['date'] ?? $oldDate);

    $oldPrincipal = $this->resolvePrincipal($deal, $oldAmount, $oldInterest, $oldPenalty);
    $newPrincipal = array_key_exists('principal_amount', $proposed) && $proposed['principal_amount'] !== null
      ? (float) $proposed['principal_amount']
      : $this->resolvePrincipal($deal, $newAmount, $newInterest, $newPenalty);

    $availableScopes = [];
    $diffs = [
      'deal' => $this->diffRow([
        'amount' => $oldAmount,
        'interest_amount' => $oldInterest,
        'penalty' => $oldPenalty,
        'principal_amount' => $oldPrincipal,
        'cash' => $oldCash,
        'date' => $oldDate,
      ], [
        'amount' => $newAmount,
        'interest_amount' => $newInterest,
        'penalty' => $newPenalty,
        'principal_amount' => $newPrincipal,
        'cash' => $newCash,
        'date' => $newDate,
      ]),
    ];

    if ($deal->pawnshop_id && ($oldAmount != $newAmount || $oldCash !== $newCash)) {
      $availableScopes[] = 'pawnshop';
      $pawnshop = Pawnshop::find($deal->pawnshop_id);
      if ($pawnshop) {
        $diffs['pawnshop'] = $this->diffRow(
          ['cashbox' => $pawnshop->cashbox, 'bank_cashbox' => $pawnshop->bank_cashbox],
          ['cashbox' => $pawnshop->cashbox, 'bank_cashbox' => $pawnshop->bank_cashbox, 'note' => 'Cashbox adjusted on save']
        );
      }
    }

    if ($deal->order_id || $deal->history_id) {
      $availableScopes[] = 'order_history';
      if ($deal->order_id) {
        $order = Order::find($deal->order_id);
        if ($order) {
          $diffs['order'] = $this->diffRow(
            ['amount' => $order->amount, 'cash' => $order->cash, 'date' => $order->date],
            ['amount' => $newAmount, 'cash' => $newCash, 'date' => $newDate]
          );
        }
      }
      if ($deal->history_id) {
        $history = History::find($deal->history_id);
        if ($history) {
          $diffs['history'] = $this->diffRow(
            ['amount' => $history->amount, 'interest_amount' => $history->interest_amount, 'penalty' => $history->penalty, 'date' => $history->date],
            ['amount' => $newAmount, 'interest_amount' => $newInterest, 'penalty' => $newPenalty, 'date' => $newDate]
          );
        }
      }
    }

    if ($deal->contract_id && in_array($deal->filter_type, self::PAYMENT_FILTER_TYPES, true)) {
      $availableScopes[] = 'contract';
      $contract = Contract::find($deal->contract_id);
      if ($contract) {
        $after = [
          'left' => max(0, (float) ($contract->left ?? 0) - ($newPrincipal - $oldPrincipal)),
          'collected' => max(0, (float) ($contract->collected ?? 0) + ($newInterest - $oldInterest)),
          'penalty_amount' => max(0, (float) ($contract->penalty_amount ?? 0) - ($newPenalty - $oldPenalty)),
        ];
        if ($contract->payment_type === 'amortized') {
          $after['provided_amount'] = max(0, (float) ($contract->provided_amount ?? 0) - ($newPrincipal - $oldPrincipal));
        }
        $diffs['contract'] = $this->diffRow([
          'left' => $contract->left,
          'collected' => $contract->collected,
          'penalty_amount' => $contract->penalty_amount,
          'provided_amount' => $contract->provided_amount,
        ], $after);
      }
    }

    $journals = DocumentJournal::where('deal_id', $deal->id)->get();
    if ($journals->isNotEmpty()) {
      $availableScopes[] = 'documents_journal';
      $availableScopes[] = 'transactions';
      $classification = $this->resolveContractClassificationName($deal->contract_id);
      $journalDiffs = [];
      $transactionDiffs = [];
      $splitTargets = [
        DocumentJournal::PAY_INTEREST_AMOUNT => $newInterest,
        DocumentJournal::PAY_MOTHER_AMOUNT => $newPrincipal,
        DocumentJournal::PAY_MOTHER_AMOUNT_CASH => $newPrincipal,
        DocumentJournal::PAY_PENALTY_AMOUNT => $newPenalty,
        DocumentJournal::RECOVERY_INTEREST => $newInterest,
        DocumentJournal::RECOVERY_PRINCIPAL => $newPrincipal,
      ];
      $handledTypes = array_keys($splitTargets);

      foreach ($splitTargets as $documentType => $targetTotal) {
        $group = $journals->where('document_type', $documentType)->sortBy('id')->values();
        if ($group->isEmpty()) {
          continue;
        }
        $amounts = $this->sequentialJournalAmounts($group, (float) $targetTotal);
        foreach ($group as $index => $journal) {
          $newJournalAmount = $amounts[$index] ?? 0.0;
          $rule = $this->resolvePostingRuleForJournal($deal, $journal->document_type, $newCash, $classification);
          $journalDiffs[] = [
            'id' => $journal->id,
            'document_type' => $journal->document_type,
            'changes' => $this->diffRow(
              ['amount_amd' => $journal->amount_amd, 'cash' => $journal->cash, 'date' => $journal->date],
              [
                'amount_amd' => $newJournalAmount,
                'cash' => $newCash,
                'date' => $newDate,
                'debit_account_id' => $rule?->debit_account_id ?? $journal->debit_account_id,
                'credit_account_id' => $rule?->credit_account_id ?? $journal->credit_account_id,
              ]
            ),
          ];
          $txn = Transaction::where('transactionable_type', DocumentJournal::class)
            ->where('transactionable_id', $journal->id)->first();
          if ($txn) {
            $transactionDiffs[] = [
              'id' => $txn->id,
              'journal_id' => $journal->id,
              'changes' => $this->diffRow(
                ['amount_amd' => $txn->amount_amd, 'date' => $txn->date, 'debit_account_id' => $txn->debit_account_id, 'credit_account_id' => $txn->credit_account_id],
                [
                  'amount_amd' => $newJournalAmount,
                  'date' => $newDate,
                  'debit_account_id' => $rule?->debit_account_id ?? $txn->debit_account_id,
                  'credit_account_id' => $rule?->credit_account_id ?? $txn->credit_account_id,
                ]
              ),
            ];
          }
        }
      }

      foreach ($journals as $journal) {
        if (in_array($journal->document_type, $handledTypes, true)) {
          continue;
        }
        $rule = $this->resolvePostingRuleForJournal($deal, $journal->document_type, $newCash, $classification);
        $journalDiffs[] = [
          'id' => $journal->id,
          'document_type' => $journal->document_type,
          'changes' => $this->diffRow(
            ['cash' => $journal->cash, 'date' => $journal->date],
            [
              'cash' => $newCash,
              'date' => $newDate,
              'debit_account_id' => $rule?->debit_account_id ?? $journal->debit_account_id,
              'credit_account_id' => $rule?->credit_account_id ?? $journal->credit_account_id,
            ]
          ),
        ];
      }

      $diffs['documents_journal'] = $journalDiffs;
      $diffs['transactions'] = $transactionDiffs;
    }

    if (DealAction::where('deal_id', $deal->id)->exists()) {
      $availableScopes[] = 'deal_actions';
    }

    if ($deal->payment_id || DealAction::where('deal_id', $deal->id)->where('actionable_type', Payment::class)->exists()) {
      $availableScopes[] = 'payments';
      $paymentDiffs = [];
      if ($deal->payment_id) {
        $p = Payment::find($deal->payment_id);
        if ($p) {
          $paymentDiffs[] = [
            'id' => $p->id,
            'changes' => $this->diffRow(
              ['interest_payment' => $p->interest_payment, 'principal_payment' => $p->principal_payment, 'paid' => $p->paid, 'date' => $p->date],
              ['interest_payment' => $newInterest, 'principal_payment' => $newPrincipal, 'paid' => $newAmount, 'date' => $newDate]
            ),
          ];
        }
      }
      $diffs['payments'] = $paymentDiffs;
    }

    return [
      'deal_id' => $deal->id,
      'available_scopes' => array_values(array_unique($availableScopes)),
      'default_scopes' => $this->defaultScopesForDeal($deal, $availableScopes),
      'diffs' => $diffs,
    ];
  }

  private function defaultScopesForDeal(Deal $deal, array $availableScopes): array
  {
    $defaults = [];
    foreach ($availableScopes as $scope) {
      if ($scope === 'pawnshop') {
        continue;
      }
      $defaults[$scope] = true;
    }
    if (in_array('documents_journal', $availableScopes, true)) {
      $defaults['documents_journal'] = true;
      $defaults['transactions'] = true;
    }
    if (in_array($deal->filter_type, self::PAYMENT_FILTER_TYPES, true)) {
      if (in_array('payments', $availableScopes, true)) {
        $defaults['payments'] = true;
      }
      if (in_array('contract', $availableScopes, true)) {
        $defaults['contract'] = true;
      }
      if (in_array('deal_actions', $availableScopes, true)) {
        $defaults['deal_actions'] = true;
      }
    }
    return $defaults;
  }

  private function diffRow(array $before, array $after): array
  {
    $fields = [];
    foreach ($after as $key => $newVal) {
      $oldVal = $before[$key] ?? null;
      if ($oldVal != $newVal) {
        $fields[] = ['field' => $key, 'before' => $oldVal, 'after' => $newVal];
      }
    }
    return ['before' => $before, 'after' => $after, 'fields' => $fields];
  }


}
