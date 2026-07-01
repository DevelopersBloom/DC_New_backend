<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Traits\ContractTrait;
use Illuminate\Support\Facades\DB;

class ContractDailyRateService
{
    use ContractTrait;

    public function processDay(Contract $contract, string $date): void
    {
        $journal = $this->getJournal($contract);
        if (!$journal) return;

        $rules = $this->getPostingRules();

        $openingAmount = $this->calculateOpeningBalance($contract);
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

            $classification = $contract->client->classification;
            if ($classification && $classification->reserve_percent > 0) {
                $reserve = $effectiveAmount * $classification->reserve_percent / 100;

                if ($reserve > 0) {
                    $ruleKey = $classification->name === 'standard'
                        ? 'reserve_general_amount'
                        : 'reserve_special_amount';

                    $this->createEntry(
                        $contract,
                        $journal,
                        $rules[$ruleKey],
                        $reserve,
                        $date,
                        $classification->name === 'standard'
                            ? DocumentJournal::RESERVE_GENERAL_AMOUNT
                            : DocumentJournal::RESERVE_SPECIAL_AMOUNT,
                        'Reserve'
                    );
                }
            }
        });
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

    private function calculateOpeningBalance(Contract $contract): float
    {
        return $this->calculateCurrentAmortizedBalance($contract);
    }
}
