<?php

namespace App\Services;

use App\Models\ClassificationHistory;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Traits\ContractTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ContractDailyRateService
{
    use ContractTrait;

    public function processDay(Contract $contract, string $date): void
    {
        $journal = $this->getJournal($contract);
        if (!$journal) return;

        $rules = $this->getPostingRules();

        $openingAmount = $this->calculateOpeningBalance($contract, $date);
        $dailyRate     = $contract->effective_daily_rate ?? 0;

        if ($openingAmount <= 0 || $dailyRate <= 0) return;

        DB::transaction(function () use ($contract, $date, $journal, $rules, $openingAmount, $dailyRate) {

            $existingIds = DocumentJournal::where('journalable_id', $journal->id)
                ->where('journalable_type', DocumentJournal::class)
                ->where('date', $date)
                ->whereIn('document_type', [
                    DocumentJournal::EFFECTIVE_RATE_AMOUNT,
                    DocumentJournal::INTEREST_RATE_AMOUNT,
                    DocumentJournal::RESERVE_GENERAL_AMOUNT,
                    DocumentJournal::RESERVE_SPECIAL_AMOUNT,
                    DocumentJournal::PENALTY_RATE_AMOUNT,
                ])
                // Daily accruals always carry contract_id; client-level reserve
                // corrections (postCorrectionEntry) don't and must survive.
                ->whereNotNull('contract_id')
                ->pluck('id');

            if ($existingIds->isNotEmpty()) {
                Transaction::where('transactionable_type', DocumentJournal::class)
                    ->whereIn('transactionable_id', $existingIds)
                    ->delete();
                DocumentJournal::whereIn('id', $existingIds)->delete();
            }

            $effectiveAmount = $openingAmount * $dailyRate / 100;

            if ($effectiveAmount > 0) {
                $this->createEntry(
                    $contract,
                    $journal,
                    $rules['effective_rate_amount'],
                    $effectiveAmount,
                    $date,
                    DocumentJournal::EFFECTIVE_RATE_AMOUNT,
                    'Daily effective interest'
                );
            }

            $interest = $contract->provided_amount * $contract->interest_rate / 100;

            if ($interest > 0) {
                $this->createEntry(
                    $contract,
                    $journal,
                    $rules['interest_rate_amount'],
                    $interest,
                    $date,
                    DocumentJournal::INTEREST_RATE_AMOUNT,
                    'Daily interest'
                );
            }

            $classification = $this->classificationAsOf($contract, $date);
            $this->createReserve($contract, $journal, $rules, $classification, $effectiveAmount, $date, 'Reserve');

            $penalty = $this->dailyPenalty($contract, $date);

            if ($penalty > 0 && isset($rules['penalty_rate_amount'])) {
                $this->createEntry(
                    $contract,
                    $journal,
                    $rules['penalty_rate_amount'],
                    $penalty,
                    $date,
                    DocumentJournal::PENALTY_RATE_AMOUNT,
                    'Daily penalty'
                );

                // Accrued penalty (Dr 16201NI) grows the reserve base too
                $this->createReserve($contract, $journal, $rules, $classification, $penalty, $date, 'Penalty reserve');
            }
        });
    }

    private function createReserve($contract, $journal, array $rules, $classification, float $baseAmount, string $date, string $comment): void
    {
        if (!$classification || $classification->reserve_percent <= 0) return;

        $reserve = $baseAmount * $classification->reserve_percent / 100;
        if ($reserve <= 0) return;

        $isStandard = $classification->name === 'standard';

        $this->createEntry(
            $contract,
            $journal,
            $rules[$isStandard ? 'reserve_general_amount' : 'reserve_special_amount'],
            $reserve,
            $date,
            $isStandard
                ? DocumentJournal::RESERVE_GENERAL_AMOUNT
                : DocumentJournal::RESERVE_SPECIAL_AMOUNT,
            $comment
        );
    }

    /**
     * Penalty accrued on $date: the cumulative penalty that day minus the day
     * before. countPenalty() persists penalty_amount on the contract as a side
     * effect, so the live value is restored — a back-dated day must not
     * overwrite it.
     */
    private function dailyPenalty(Contract $contract, string $date): float
    {
        $penaltyAmountBefore = Contract::whereKey($contract->id)->value('penalty_amount');

        $yesterday = (float) ($this->countPenalty($contract->id, Carbon::parse($date)->subDay()->toDateString())['penalty_amount'] ?? 0);
        $today     = (float) ($this->countPenalty($contract->id, $date)['penalty_amount'] ?? 0);

        Contract::whereKey($contract->id)->update(['penalty_amount' => $penaltyAmountBefore]);

        return max(0, $today - $yesterday);
    }

    /**
     * The client's classification on $date (latest classification_histories row
     * up to that day), not the live clients.classification_id — a back-dated
     * re-accrual must use the class that applied on each day.
     */
    private function classificationAsOf(Contract $contract, string $date)
    {
        $history = ClassificationHistory::where('client_id', $contract->client_id)
            ->where('date', '<=', Carbon::parse($date)->endOfDay())
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->with('classification')
            ->first();

        return $history?->classification ?? $contract->client->classification;
    }

    private function createEntry($contract, $journal, $rule, $amount, $date, $type, $comment): void
    {
        $doc = DocumentJournal::create([
            'date'             => $date,
            'document_number'  => $this->nextDocNumber(),
            'document_type'    => $type,
            'amount_amd'       => $amount,
            'debit_partner_id' => $rule->resolveDebitPartnerId($contract),
            'credit_partner_id'=> $rule->resolveCreditPartnerId($contract),
            'debit_account_id' => $rule->debit_account_id,
            'credit_account_id'=> $rule->credit_account_id,
            'comment'          => $comment . ' for contract #' . $contract->id,
            'user_id'          => 1,
            'journalable_type' => DocumentJournal::class,
            'journalable_id'   => $journal->id,
            'contract_id'      => $contract->id,
        ]);

        Transaction::create([
            'date'                 => $date,
            'document_number'      => $doc->document_number,
            'document_type'        => $type,
            'amount_amd'           => $amount,
            'debit_partner_id'     => $rule->resolveDebitPartnerId($contract),
            'credit_partner_id'    => $rule->resolveCreditPartnerId($contract),
            'debit_account_id'     => $rule->debit_account_id,
            'credit_account_id'    => $rule->credit_account_id,
            'comment'              => $comment . ' for contract #' . $contract->id,
            'user_id'              => 1,
            'is_system'            => true,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id'   => $doc->id,
            'contract_id'          => $contract->id,
        ]);
    }

    private function getPostingRules(): array
    {
        return PostingRule::whereIn('business_event_filter', [
            'effective_rate_amount',
            'interest_rate_amount',
            'reserve_general_amount',
            'reserve_special_amount',
            'penalty_rate_amount',
        ])->get()->keyBy('business_event_filter')->all();
    }

    private function nextDocNumber(): int
    {
        return Transaction::getNextDocumentNumber();
    }

    private function getJournal(Contract $contract): ?DocumentJournal
    {
        return DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();
    }

    private function calculateOpeningBalance(Contract $contract, string $date): float
    {
        return $this->calculateCurrentAmortizedBalance($contract, $date);
    }
}
