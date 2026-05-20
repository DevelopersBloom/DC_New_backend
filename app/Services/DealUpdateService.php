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

  public function __construct(
    private readonly PaymentService $paymentService
  ) {
  }

  public function updateMany(array $dealsData): void
  {
    DB::transaction(function () use ($dealsData) {
      foreach ($dealsData as $dealData) {
        $this->updateOne($dealData);
      }
    });
  }

  public function updateOne(array $dealData): void
  {
    $deal = Deal::findOrFail($dealData['id']);

    $oldAmount = (float) ($deal->amount ?? 0);
    $oldInterest = (float) ($deal->interest_amount ?? 0);
    $oldPenalty = (float) ($deal->penalty ?? 0);
    $oldCash = (bool) $deal->cash;
    $oldDate = $deal->date;
    $oldPrincipal = $this->resolvePrincipal($deal, $oldAmount, $oldInterest, $oldPenalty);

    $newAmount = (float) ($dealData['amount'] ?? $oldAmount);
    $newInterest = (float) ($dealData['interest_amount'] ?? $oldInterest);
    $newPenalty = (float) ($dealData['penalty'] ?? $oldPenalty);
    $newCash = array_key_exists('cash', $dealData) ? (bool) $dealData['cash'] : $oldCash;
    $newDate = $dealData['date'] ?? $oldDate;
    $newPrincipal = array_key_exists('principal_payment', $dealData)
      ? (float) $dealData['principal_payment']
      : $this->resolvePrincipal($deal, $newAmount, $newInterest, $newPenalty);

    $this->adjustPawnshopCashbox($deal, $oldAmount, $oldCash, $newAmount, $newCash);

    $pawnshop = $deal->pawnshop_id ? Pawnshop::find($deal->pawnshop_id) : null;

    $deal->update([
      'amount' => $newAmount,
      'interest_amount' => $newInterest,
      'penalty' => $newPenalty,
      'cash' => $newCash,
      'date' => $newDate,
      'cashbox' => $pawnshop?->cashbox,
      'bank_cashbox' => $pawnshop?->bank_cashbox,
    ]);

    $this->syncOrder($deal, $newAmount, $newCash, $newDate);
    $this->syncHistory($deal, $newAmount, $newInterest, $newPenalty, $newDate);
    $this->syncRelatedDates($deal, $newDate);
    $this->syncAccounting($deal, $newInterest, $newPrincipal, $newPenalty, $newCash);
    $this->syncContract($deal, $oldInterest, $oldPenalty, $oldPrincipal, $newInterest, $newPenalty, $newPrincipal);
    $this->syncDealActions($deal, $newAmount, $newInterest, $newPrincipal, $newPenalty, $newDate);
    $this->syncPayments(
      $deal,
      $oldInterest,
      $oldPenalty,
      $oldPrincipal,
      $newInterest,
      $newPenalty,
      $newPrincipal,
      $newCash,
      $newDate
    );
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
      DocumentJournal::PAY_PENALTY_AMOUNT => $penalty,
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

      $newAmount = $this->journalAmountForType($journal->document_type, $interest, $principal, $penalty, $totalAmount);

      if ($newAmount === null) {
        continue;
      }

      $this->applyJournalAmountUpdate($deal, $journal, $newAmount, $cash, $deal->date, $classification);
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
      DocumentJournal::PAY_MOTHER_AMOUNT => $cash
        ? 'pay_mother_amount_cash'
        : 'pay_mother_amount',
      DocumentJournal::PAY_PENALTY_AMOUNT => $classification === 'loss'
        ? 'pay_penalty_amount_loss'
        : ($cash ? 'pay_penalty_amount_cash' : 'pay_penalty_amount'),
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

  private function syncPayments(
    Deal $deal,
    float $oldInterest,
    float $oldPenalty,
    float $oldPrincipal,
    float $newInterest,
    float $newPenalty,
    float $newPrincipal,
    bool $cash,
    string $date
  ): void {
    if ($deal->payment_id) {
      Payment::where('id', $deal->payment_id)->update([
        'cash' => $cash,
        'date' => $date,
        'paid' => $newPrincipal,
        'amount' => $newPrincipal,
      ]);
    }

    if (!$deal->contract_id || !in_array($deal->filter_type, self::PAYMENT_FILTER_TYPES, true)) {
      return;
    }

    $principalScale = $this->scaleFactor($oldPrincipal, $newPrincipal);
    $interestScale = $this->scaleFactor($oldInterest, $newInterest);

    $touchedPaymentIds = [];

    DealAction::where('deal_id', $deal->id)->each(function (DealAction $action) use (
      $principalScale,
      $interestScale,
      $newPenalty,
      $cash,
      $date,
      &$touchedPaymentIds
    ) {
      $history = $action->history;
      if (is_array($history)) {
        foreach ($history['payment_changes'] ?? [] as $change) {
          if (!empty($change['payment_id'])) {
            $this->applyScaledPaymentChange($change, $principalScale, $interestScale, $cash, $date);
            $touchedPaymentIds[] = (int) $change['payment_id'];
          }
        }

        if (!empty($history['mother_amount']['payment_id'])) {
          $this->applyScaledMotherAmount($history['mother_amount'], $principalScale, $cash, $date);
          $touchedPaymentIds[] = (int) $history['mother_amount']['payment_id'];
        }
      }

      if ($action->actionable_type !== Payment::class || !$action->actionable_id) {
        return;
      }

      $paymentId = (int) $action->actionable_id;
      if (in_array($paymentId, $touchedPaymentIds, true)) {
        return;
      }

      $payment = Payment::find($paymentId);
      if (!$payment) {
        return;
      }

      $payment->cash = $cash;
      $payment->date = $date;

      if ($action->type === 'penalty') {
        $payment->paid = $newPenalty;
        $payment->penalty = $newPenalty;
        $payment->save();
        $touchedPaymentIds[] = $paymentId;
        return;
      }

      if ($action->type === 'regular') {
        $this->syncRegularPaymentFromAction($payment, $action, $principalScale, $interestScale);
        $payment->cash = $cash;
        $payment->date = $date;
        $payment->save();
        $touchedPaymentIds[] = $paymentId;
      }
    });

    Payment::whereIn('id', array_unique($touchedPaymentIds))->update(['cash' => $cash]);

    $contract = Contract::find($deal->contract_id);
    if ($contract && $contract->payment_type === 'amortized') {
      $this->paymentService->recalculateAmortizedSchedule($contract, $date);
    }
  }

  private function scaleFactor(float $oldValue, float $newValue): float
  {
    if ($oldValue <= 0) {
      return $newValue > 0 ? 1.0 : 0.0;
    }

    return $newValue / $oldValue;
  }

  /**
   * @param  array<string, mixed>  $change
   */
  private function applyScaledPaymentChange(
    array $change,
    float $principalScale,
    float $interestScale,
    bool $cash,
    string $date
  ): void {
    $payment = Payment::find($change['payment_id'] ?? null);
    if (!$payment) {
      return;
    }

    $oldPaid = (float) ($change['old_paid'] ?? 0);
    $oldAmount = (float) ($change['old_amount'] ?? 0);
    $oldPrincipal = isset($change['old_principal']) ? (float) $change['old_principal'] : null;
    $oldInterest = isset($change['old_interest']) ? (float) $change['old_interest'] : null;

    $payment->cash = $cash;
    $payment->date = $date;

    if (isset($change['reduction'])) {
      $reduction = (float) $change['reduction'] * $principalScale;
      $payment->paid = $oldPaid + $reduction;
      if ($oldPrincipal !== null) {
        $payment->principal_payment = max(0, $oldPrincipal - $reduction);
      }
      $payment->save();
      return;
    }

    $appliedPaid = (float) $payment->paid - $oldPaid;
    $payment->paid = $oldPaid + ($appliedPaid * $principalScale);

    $appliedAmountReduction = max(0, $oldAmount - (float) $payment->amount);
    $payment->amount = max(0, $oldAmount - ($appliedAmountReduction * $principalScale));

    if ($oldPrincipal !== null) {
      $appliedPrincipalReduction = max(0, $oldPrincipal - (float) $payment->principal_payment);
      $payment->principal_payment = max(0, $oldPrincipal - ($appliedPrincipalReduction * $principalScale));
    }

    if ($oldInterest !== null) {
      $appliedInterestReduction = max(0, $oldInterest - (float) $payment->interest_payment);
      $payment->interest_payment = max(0, $oldInterest - ($appliedInterestReduction * $interestScale));
    }

    $payment->save();
  }

  /**
   * @param  array<string, mixed>  $motherChange
   */
  private function applyScaledMotherAmount(array $motherChange, float $principalScale, bool $cash, string $date): void
  {
    $payment = Payment::find($motherChange['payment_id'] ?? null);
    if (!$payment) {
      return;
    }

    $oldMother = (float) ($motherChange['old_mother'] ?? 0);
    $motherAtCreation = (float) ($motherChange['new_mother'] ?? $payment->mother);
    $motherReduction = max(0, $oldMother - $motherAtCreation);

    $payment->mother = max(0, $oldMother - ($motherReduction * $principalScale));
    $payment->cash = $cash;
    $payment->date = $date;
    if ($payment->mother <= 0) {
      $payment->status = 'completed';
    }
    $payment->save();
  }

  private function syncRegularPaymentFromAction(
    Payment $payment,
    DealAction $action,
    float $principalScale,
    float $interestScale
  ): void {
    $history = $action->history ?? [];
    $change = $this->findPaymentChangeInHistory($history, $payment->id);

    if ($change) {
      $this->applyScaledPaymentChange($change, $principalScale, $interestScale, (bool) $payment->cash, (string) $payment->date);
      return;
    }

    $oldPaid = (float) $payment->paid;
    $targetPaid = (float) $action->amount;
    if ($oldPaid > 0 && $principalScale !== 1.0) {
      $payment->paid = $oldPaid * $principalScale;
    } elseif ($targetPaid > 0) {
      $payment->paid = $targetPaid;
    }
  }

  /**
   * @param  array<string, mixed>  $history
   * @return array<string, mixed>|null
   */
  private function findPaymentChangeInHistory(array $history, int $paymentId): ?array
  {
    foreach ($history['payment_changes'] ?? [] as $change) {
      if ((int) ($change['payment_id'] ?? 0) === $paymentId) {
        return $change;
      }
    }

    return null;
  }
}
