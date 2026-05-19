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
    $this->syncPayments($deal, $newAmount, $newInterest, $newPenalty, $newPrincipal, $newCash);
    $this->syncDealActions($deal, $newAmount, $newInterest, $newPrincipal, $newPenalty, $newDate);
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

    foreach ($journals as $journal) {
      $newAmount = $this->journalAmountForType($journal->document_type, $interest, $principal, $penalty, $totalAmount);

      if ($newAmount === null) {
        continue;
      }

      $journal->update([
        'amount_amd' => $newAmount,
        'cash' => $cash,
        'date' => $deal->date,
      ]);

      Transaction::where('transactionable_type', DocumentJournal::class)
        ->where('transactionable_id', $journal->id)
        ->update([
          'amount_amd' => $newAmount,
          'date' => $deal->date,
        ]);
    }
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

  private function syncPayments(
    Deal $deal,
    float $amount,
    float $interest,
    float $penalty,
    float $principal,
    bool $cash
  ): void {
    if ($deal->payment_id) {
      Payment::where('id', $deal->payment_id)->update(['cash' => $cash]);
    }

    $paymentIds = DealAction::where('deal_id', $deal->id)
      ->where('actionable_type', Payment::class)
      ->pluck('actionable_id')
      ->filter()
      ->unique();

    if ($paymentIds->isEmpty()) {
      return;
    }

    Payment::whereIn('id', $paymentIds)->update(['cash' => $cash]);

    $actions = DealAction::where('deal_id', $deal->id)
      ->where('actionable_type', Payment::class)
      ->orderBy('id')
      ->get();

    if ($actions->count() === 1) {
      $action = $actions->first();
      $payment = Payment::find($action->actionable_id);
      if ($payment) {
        $payment->paid = max(0, $amount);
        $payment->cash = $cash;
        if ($action->type === 'penalty') {
          $payment->penalty = $penalty;
        }
        $payment->save();
      }
      return;
    }

    foreach ($actions as $action) {
      if ($action->type === 'penalty' && $action->actionable_id) {
        Payment::where('id', $action->actionable_id)->update([
          'paid' => $penalty,
          'penalty' => $penalty,
          'cash' => $cash,
        ]);
      }
    }
  }

  private function syncDealActions(
    Deal $deal,
    float $amount,
    float $interest,
    float $principal,
    float $penalty,
    string $date
  ): void {
    DealAction::where('deal_id', $deal->id)->each(function (DealAction $action) use ($amount, $interest, $principal, $penalty, $date) {
      $updates = ['date' => $date];

      if ($action->type === 'penalty') {
        $updates['amount'] = $penalty;
      } elseif ($action->type === 'regular') {
        $updates['amount'] = $amount - $interest - $penalty;
      } elseif ($action->type === 'partial') {
        $updates['amount'] = $principal;
      }

      $action->update($updates);
    });
  }
}
