<?php

namespace App\Traits;

use App\Models\ChartOfAccount;
use App\Models\ClassificationHistory;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\PostingRule;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait CorrectReserveTrait
{
    use CalculatesAccountBalancesTrait;

    /**
     * Zero both 16605PC and 16605PS when the client has no active (initial) contracts.
     */
    protected function zeroClientReserveBalances(
        int    $clientId,
        int    $acc16605PC,
        int    $acc16605PS,
        ?int   $journalId,
        string $date,
    ): void {
        $balance16605PC = $this->getClientReserveBalance($clientId, $acc16605PC, $date);
        $balance16605PS = $this->getClientReserveBalance($clientId, $acc16605PS, $date);

        Log::info("zeroClientReserveBalances client#{$clientId}", [
            'balance16605PC' => $balance16605PC,
            'balance16605PS' => $balance16605PS,
            'date'           => $date,
        ]);

        $this->zeroReserveAccount($clientId, $balance16605PC, true, "Zero 16605PC (closed contract) for client #{$clientId}", $journalId, $date);
        $this->zeroReserveAccount($clientId, $balance16605PS, false, "Zero 16605PS (closed contract) for client #{$clientId}", $journalId, $date);
    }


    private function zeroReserveAccount(
        int    $clientId,
        float  $balance,
        bool   $general,
        string $comment,
        ?int   $journalId,
        string $date,
    ): ?int {
        if (abs($balance) < 0.01) {
            return null;
        }

        $rule = PostingRule::where(
            'business_event_filter',
            $general ? 'provide_general_amount_change' : 'provide_special_amount_change'
        )->first();

        $isRelease = $balance < 0;
        [$debit, $credit] = $isRelease
            ? [$rule->debit_account_id, $rule->credit_account_id]
            : [$rule->credit_account_id, $rule->debit_account_id];

        $documentType = $general
            ? ($isRelease ? DocumentJournal::PROVIDED_AMOUNT_CHANGE : DocumentJournal::RESERVE_GENERAL_AMOUNT)
            : ($isRelease ? DocumentJournal::PROVIDE_SPECIAL_AMOUNT_CHANGE : DocumentJournal::RESERVE_SPECIAL_AMOUNT);

        return $this->postCorrectionEntry(
            clientId:        $clientId,
            debitPartnerId:  $isRelease ? $clientId : null,
            creditPartnerId: $isRelease ? null : $clientId,
            debitAccountId:  $debit,
            creditAccountId: $credit,
            amount:          abs($balance),
            documentType:    $documentType,
            comment:         $comment,
            journalId:       $journalId,
            now:             $date,
        );
    }


    protected function syncClientReserveToTarget(int $clientId, string $date, ?int $contractId = null): void
    {
        $hasActiveContracts = Contract::where('client_id', $clientId)
            ->where('status', 'initial')
            ->exists();

        if (!$hasActiveContracts) {
            $this->releaseReserveBalancesIfClientFullyClosed($clientId, $contractId, $date);
            return;
        }

        [$classificationName, $reservePercent] = $this->resolveClientClassificationAsOf($clientId, $date);

        if ($classificationName === null || $classificationName === 'loss') {
            return;
        }

        $firstActiveContractId = Contract::where('client_id', $clientId)
            ->where('status', 'initial')
            ->orderBy('id')
            ->value('id');

        $journalId = $this->resolveReserveJournalIdForClient($clientId, $firstActiveContractId);

        if (!$journalId) {
            Log::warning("Reserve sync skipped for client {$clientId}: no contract journal found");
            return;
        }

        $this->correctClientReserveBalance(
            clientId:           $clientId,
            acc16605PC:         ChartOfAccount::idByCode('16605PC'),
            acc16605PS:         ChartOfAccount::idByCode('16605PS'),
            targetAccountIds:   array_filter([
                ChartOfAccount::idByCode('16200NV'),
                ChartOfAccount::idByCode('16201NI'),
                ChartOfAccount::idByCode('16200'),
            ]),
            reservePercent:     $reservePercent,
            classificationName: $classificationName,
            journalId:          $journalId,
            now:                $date,
        );
    }

    /**
     * @return array{0: ?string, 1: float} [classification name, reserve percent] as of $date
     */
    protected function resolveClientClassificationAsOf(int $clientId, string $date): array
    {
        $history = ClassificationHistory::where('client_id', $clientId)
            ->where('date', '<=', Carbon::parse($date)->endOfDay())
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->with('classification')
            ->first();

        if ($history && $history->classification) {
            return [
                $history->classification->name,
                (float) ($history->reserve_percent ?? $history->classification->reserve_percent ?? 0),
            ];
        }

        $classification = Client::with('classification')->find($clientId)?->classification;

        return [$classification?->name, (float) ($classification?->reserve_percent ?? 0)];
    }

    protected function resolveReserveJournalIdForClient(int $clientId, ?int $contractId = null): ?int
    {
        if ($contractId) {
            $journal = DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $contractId)
                ->first();
            if ($journal) {
                return $journal->id;
            }
        }

        $contractIds = Contract::where('client_id', $clientId)
            ->orderByDesc('id')
            ->pluck('id');

        if ($contractIds->isEmpty()) {
            return null;
        }

        return DocumentJournal::where('journalable_type', Contract::class)
            ->whereIn('journalable_id', $contractIds)
            ->orderByDesc('journalable_id')
            ->value('id');
    }

    protected function releaseReserveBalancesIfClientFullyClosed(
        int    $clientId,
        ?int   $contractId = null,
        ?string $date = null,
    ): void {
        $hasActiveContracts = Contract::where('client_id', $clientId)
            ->where('status', 'initial')
            ->exists();

        if ($hasActiveContracts) {
            return;
        }

        $date = $date ?? now()->format('Y-m-d');
        $acc16605PC = ChartOfAccount::idByCode('16605PC');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');
        $journalId = $this->resolveReserveJournalIdForClient($clientId, $contractId);

        if (!$journalId) {
            Log::warning("Reserve release skipped for client {$clientId}: no contract journal found");
            return;
        }

        $this->zeroClientReserveBalances(
            clientId:    $clientId,
            acc16605PC:  $acc16605PC,
            acc16605PS:  $acc16605PS,
            journalId:   $journalId,
            date:        $date,
        );
    }

    /**
     * Sets the client's reserve to the rule target as of $now, from the actual
     * account balances (not sums of past credits):
     *   - standard:     16605PS → 0, 16605PC → target
     *   - non-standard: 16605PC → 0, 16605PS → target
     * where target = round(reservePercent % × (16200NV + 16201NI + 16200), 2).
     *
     * A classification change is therefore exactly: release the old account to
     * zero, then set the new account to the target — no transfer between
     * 16605PC and 16605PS.
     *
     * @return array{net: float, target: float, old_16605PC: float, old_16605PS: float, document_ids: int[]}
     *         old_* are reserve amounts (credit balances, positive) before the correction.
     */
    private function correctClientReserveBalance(
        int    $clientId,
        int    $acc16605PC,
        int    $acc16605PS,
        array  $targetAccountIds,
        float  $reservePercent,
        string $classificationName,
        ?int   $journalId,
        string $now,
    ): array {
        $accountsSum = $this->getClientAccountsBalance($clientId, $targetAccountIds, $now);
        $targetAmount = round($accountsSum * ($reservePercent / 100), 2);

        // debit − credit: a normal (credit) reserve balance is negative
        $balance16605PC = $this->getClientReserveBalance($clientId, $acc16605PC, $now);
        $balance16605PS = $this->getClientReserveBalance($clientId, $acc16605PS, $now);

        Log::info("correctClientReserveBalance client#{$clientId}", [
            'accountsSum'     => $accountsSum,
            'targetAmount'    => $targetAmount,
            'balance16605PC'  => $balance16605PC,
            'balance16605PS'  => $balance16605PS,
            'classification'  => $classificationName,
        ]);

        $isStandard = $classificationName === 'standard';
        $documentIds = [];

        // Release the account that must be empty for this classification
        $documentIds[] = $isStandard
            ? $this->zeroReserveAccount($clientId, $balance16605PS, false, "Zero 16605PS (standard correction) for client #{$clientId}", $journalId, $now)
            : $this->zeroReserveAccount($clientId, $balance16605PC, true, "Zero 16605PC (non-standard correction) for client #{$clientId}", $journalId, $now);

        // Set the other account to the target
        $currentBalance = $isStandard ? $balance16605PC : $balance16605PS;
        $diff = round((-$targetAmount) - $currentBalance, 2);

        if (abs($diff) >= 0.01) {
            // diff > 0 → reserve is above target → release (Dr 16605xx / Cr 63015)
            // diff < 0 → reserve is below target → add     (Dr 73015  / Cr 16605xx)
            $release = $diff > 0;
            $ruleKey = $isStandard
                ? ($release ? 'provide_general_amount_change' : 'reserve_general_amount')
                : ($release ? 'provide_special_amount_change' : 'reserve_special_amount');
            $documentType = $isStandard
                ? ($release ? DocumentJournal::PROVIDED_AMOUNT_CHANGE : DocumentJournal::RESERVE_GENERAL_AMOUNT)
                : ($release ? DocumentJournal::PROVIDE_SPECIAL_AMOUNT_CHANGE : DocumentJournal::RESERVE_SPECIAL_AMOUNT);

            $rule = PostingRule::where('business_event_filter', $ruleKey)->first();

            $documentIds[] = $this->postCorrectionEntry(
                clientId:        $clientId,
                debitPartnerId:  $release ? $clientId : null,
                creditPartnerId: $release ? null : $clientId,
                debitAccountId:  $rule->debit_account_id,
                creditAccountId: $rule->credit_account_id,
                amount:          abs($diff),
                documentType:    $documentType,
                comment:         $isStandard
                    ? "Reserve correction (standard) for client #{$clientId}"
                    : "Reserve correction (non-standard) for client #{$clientId}",
                journalId:       $journalId,
                now:             $now,
            );
        }

        return [
            'net'          => round($accountsSum, 2),
            'target'       => $targetAmount,
            'old_16605PC'  => round(-$balance16605PC, 2),
            'old_16605PS'  => round(-$balance16605PS, 2),
            'document_ids' => array_values(array_filter($documentIds)),
        ];
    }


    private function postCorrectionEntry(
        int    $clientId,
        ?int    $debitPartnerId,
        ?int    $creditPartnerId,
        int    $debitAccountId,
        int   $creditAccountId,
        float  $amount,
        string $documentType,
        string $comment,
        ?int   $journalId,
        string $now,
    ): ?int {
        if ($amount < 0.01 || !$journalId) return null;

        $nextDocNum = Transaction::getNextDocumentNumber();

        $doc = DocumentJournal::create([
            'date'              => $now,
            'document_number'   => $nextDocNum,
            'document_type'     => $documentType,
            'amount_amd'        => $amount,
            'debit_partner_id'  => $debitPartnerId,
            'credit_partner_id' => $creditPartnerId,
            'debit_account_id'  => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'comment'           => $comment,
            'user_id'           => auth()->id() ?? 1,
            'journalable_type'  => DocumentJournal::class,
            'journalable_id'    => $journalId,
        ]);

        Transaction::create([
            'date'                 => $now,
            'document_number'      => $nextDocNum,
            'document_type'        => $documentType,
            'debit_account_id'     => $debitAccountId,
            'debit_partner_id'     => $debitPartnerId,
            'debit_currency_id'    => 1,
            'credit_account_id'    => $creditAccountId,
            'credit_currency_id'   => 1,
            'credit_partner_id'    => $creditPartnerId,
            'amount_amd'           => $amount,
            'comment'              => $comment,
            'user_id'              => auth()->id() ?? 1,
            'is_system'            => true,
            'disbursement_date'    => $now,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id'   => $doc->id,
        ]);

        Log::info("Correction posted: {$comment}, amount: {$amount}");

        return $doc->id;
    }
}
