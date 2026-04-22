<?php




namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\ClassificationHistory;
use App\Models\Contract;
use App\Models\ContractReserveHistory;
use App\Models\DocumentJournal;
use App\Models\Modification;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use App\Traits\CalculatesAccountBalancesTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateClientClassificationsNew implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use CalculatesAccountBalancesTrait;

    public int $timeout = 1800;

    public function __construct()
    {
    }

    public function handle(ClientClassificationService $service): void
    {
        Log::info('Client classification job started...');

        Client::whereHas('contracts', function (Builder $q) {
            $q->where('status', 'initial');
        })->with(['contracts' => function ($q) {
            $q->where('status', 'initial');
        }, 'classification'])
            ->chunkById(200, function ($clients) use ($service) {
                $now = now()->format('Y-m-d');

                $acc16605PC = ChartOfAccount::idByCode('16605PC');
                $acc16605PS = ChartOfAccount::idByCode('16605PS');
                $acc16200NV = ChartOfAccount::idByCode('16200NV');
                $acc16200 = ChartOfAccount::idByCode('16200');
                $acc16201NI = ChartOfAccount::idByCode('16201NI');

                $targetAccountIds = array_filter([
                    ChartOfAccount::idByCode('16200NV'),
                    ChartOfAccount::idByCode('16201NI'),
                    ChartOfAccount::idByCode('16200'),
                ]);

                $diamondId = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;
                $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;

                foreach ($clients as $client) {
                    try {
                        Log::info('Processing client', ['id' => $client->id]);

                        DB::beginTransaction();

                        $maxOverdue = $service->maxOverdueDaysForClient($client);
                        $classification = $service->classificationByOverdue($maxOverdue);

                        Log::info("Calculated classification: {$classification->id}, maxOverdue: {$maxOverdue}");

                        if ($client->classification_id === $classification->id) {
                            DB::commit();
                            continue;
                        }

                        // Save old values
                        $oldClassificationName = $client->classification?->name;
                        $oldClassificationId = $client->classification?->id;
                        $oldClassificationOrder = $client->classification?->order;
                        $oldReservePercent = $client->classification?->reserve_percent ?? 0;

                        $client->classification_id = $classification->id;
                        $client->save();
                        $client->load('classification');

                        $newClassificationName = $client->classification?->name;
                        $newReservePercent = $client->classification?->reserve_percent ?? 0;
                        $newRiskWeight = $client->classification?->risk_weight ?? 0;
                        $newClassificationOrder = $client->classification?->order;
                        $clientId = $client->id;

                        $oldRisk = $oldClassificationOrder !== null ? max(0, min(7, (int)$oldClassificationOrder)) : null;
                        $newRisk = $newClassificationOrder !== null ? max(0, min(7, (int)$newClassificationOrder)) : 0;

                        Modification::create([
                            'subject_type' => Client::class,
                            'subject_id' => $clientId,
                            'modification_type' => 'Modificator',
                            'field_code' => 'RISK',
                            'element_code' => 'Risk',
                            'old_value' => $oldRisk !== null ? (string)$oldRisk : null,
                            'new_value' => (string)$newRisk,
                            'effective_date' => now()->toDateString(),
                        ]);


                        $accountsSum = $this->getClientAccountsBalance($clientId, $targetAccountIds,$now);

                        Log::info("Client #{$clientId} accounts sum (16200NV+16201NI+16200): {$accountsSum}");

                        $existingGeneralReserve = $this->getClientReserveBalance($clientId, $acc16605PC,$now);

                        // Get existing special reserve (16605PS) credited for this client
                        $existingSpecialReserve = $this->getClientReserveBalance($clientId, $acc16605PS,$now);

                        $existingReserve = $oldClassificationName === 'standard'
                            ? $existingGeneralReserve
                            : $existingSpecialReserve;

                        Log::info("Client #{$clientId} existingReserve: {$existingReserve}");

                        // Amount to post = (accountsSum * newReservePercent%) - existingReserve
                        $amount = ($accountsSum * ($newReservePercent / 100)) - $existingReserve;

                        Log::info("Client #{$clientId} calculated amount: {$amount}");

                        // Record reserve history for each contract (for reporting)
                        foreach ($client->contracts as $contract) {
                            ContractReserveHistory::create([
                                'client_id' => $client->id,
                                'classification_id' => $classification->id,
                                'contract_id' => $contract->id,
                                'risk_weight' => $newRiskWeight,
                                'reserve_percent' => $newReservePercent,
                                'reserve_amount' => $amount,
                                'total_reserve_amount' => $amount,
                                'provided_amount' => $contract->provided_amount,
                                'date' => now()->toDateString(),
                                'user_id' => auth()->check() ? auth()->id() : 1,
                                'meta' => [
                                    'old_reserve_percent' => $oldReservePercent,
                                    'old_reserve_amount' => $existingReserve,
                                    'accounts_sum' => $accountsSum,
                                ],
                            ]);
                        }

                        if ($amount <= 0 && $newClassificationName !== 'loss') {
                            DB::commit();
                            continue;
                        }

                        // Determine posting rules
                        if ($newClassificationName === 'standard') {
                            $ruleReserve = PostingRule::where('business_event_filter', 'reserve_general_amount')->first();
                        } else {
                            $ruleReserve = PostingRule::where('business_event_filter', 'reserve_special_amount')->first();
                        }

                        if (!$ruleReserve) {
                            throw new \RuntimeException('Posting rule for reserve not found');
                        }

                        $debitReserve = $ruleReserve->debit_account_id;
                        $creditReserve = $ruleReserve->credit_account_id;

                        if ($oldClassificationName === 'standard') {
                            $ruleClassification = PostingRule::where('business_event_filter', 'classification_general_to_special')->firstOrFail();
                        } else {
                            $ruleClassification = PostingRule::where('business_event_filter', 'classification_special_to_general')->firstOrFail();
                        }
                        $debitClassification = $ruleClassification->debit_account_id;
                        $creditClassification = $ruleClassification->credit_account_id;

                        $documentType = $newClassificationName === 'standard'
                            ? DocumentJournal::RESERVE_GENERAL_AMOUNT
                            : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                        $firstContract = $client->contracts->first();
                        $journal = $firstContract
                            ? DocumentJournal::where('journalable_type', Contract::class)
                                ->where('journalable_id', $firstContract->id)
                                ->first()
                            : null;

                        if ($amount > 0 && $journal) {
                            $docJournal = DocumentJournal::create([
                                'date' => now()->toDateString(),
                                'document_number' => $nextDocNum,
                                'document_type' => $documentType,
                                'amount_amd' => $amount,
                                'debit_partner_id' => $diamondId,
                                'credit_partner_id' => $clientId,
                                'comment' => "Reserve adjustment for client #{$clientId} due to classification change",
                                'debit_account_id' => $debitReserve,
                                'credit_account_id' => $creditReserve,
                                'user_id' => auth()->check() ? auth()->id() : 1,
                                'journalable_type' => DocumentJournal::class,
                                'journalable_id' => $journal->id,
                            ]);

                            Transaction::create([
                                'date' => now()->toDateString(),
                                'document_number' => $nextDocNum,
                                'document_type' => $documentType,
                                'debit_account_id' => $debitReserve,
                                'debit_partner_id' => $diamondId,
                                'debit_currency_id' => 1,
                                'credit_account_id' => $creditReserve,
                                'credit_currency_id' => 1,
                                'credit_partner_id' => $clientId,
                                'amount_amd' => $amount,
                                'comment' => "Reserve adjustment for client #{$clientId}",
                                'user_id' => auth()->check() ? auth()->id() : 1,
                                'is_system' => true,
                                'disbursement_date' => now()->toDateString(),
                                'transactionable_type' => DocumentJournal::class,
                                'transactionable_id' => $docJournal->id,
                            ]);

                            ClassificationHistory::create([
                                'client_id' => $client->id,
                                'classification_id' => $classification->id,
                                'risk_weight' => $newRiskWeight,
                                'reserve_percent' => $client->classification?->reserve_percent ?? 0,
                                'comment' => 'Automatic client classification update based on overdue days',
                                'actionable_type' => DocumentJournal::class,
                                'actionable_id' => $docJournal->id,
                                'user_id' => auth()->id() ?? 1,
                                'meta' => [
                                    'old_classification_id' => $oldClassificationId,
                                    'old_classification_name' => $oldClassificationName,
                                    'old_reserve_percent' => $oldReservePercent,
                                    'old_reserve_amount' => $existingReserve,
                                    'accounts_sum' => $accountsSum,
                                ],
                                'date' => now(),
                            ]);

                            $nextDocNum++;
                        }

                        // Transfer existing general reserve to special (when moving from standard)
                        if ($existingGeneralReserve > 0 && $oldClassificationName === 'standard' && $journal) {
                            $classificationType = DocumentJournal::CLASSIFICATION;

                            $classificationDoc = DocumentJournal::create([
                                'date' => now()->toDateString(),
                                'document_number' => $nextDocNum,
                                'document_type' => $classificationType,
                                'amount_amd' => $existingGeneralReserve,
                                'debit_partner_id' => $clientId,
                                'credit_partner_id' => $clientId,
                                'comment' => "Transfer general to special reserve for client #{$clientId}",
                                'debit_account_id' => $debitClassification,
                                'credit_account_id' => $creditClassification,
                                'user_id' => auth()->check() ? auth()->id() : 1,
                                'journalable_type' => DocumentJournal::class,
                                'journalable_id' => $journal->id,
                            ]);

                            Transaction::create([
                                'date' => now()->toDateString(),
                                'document_number' => $nextDocNum,
                                'document_type' => $classificationType,
                                'debit_account_id' => $debitClassification,
                                'debit_partner_id' => $clientId,
                                'debit_currency_id' => 1,
                                'credit_account_id' => $creditClassification,
                                'credit_currency_id' => 1,
                                'credit_partner_id' => $clientId,
                                'amount_amd' => $existingGeneralReserve,
                                'comment' => "Transfer general to special reserve for client #{$clientId}",
                                'user_id' => auth()->check() ? auth()->id() : 1,
                                'is_system' => true,
                                'disbursement_date' => now()->toDateString(),
                                'transactionable_type' => DocumentJournal::class,
                                'transactionable_id' => $classificationDoc->id,
                            ]);

                            $nextDocNum++;
                        }

                        // If new classification is 'loss' — handle zeroing of special reserve and accounts
                        if ($newClassificationName === 'loss' && $journal) {

                            // Zero out special reserve (16605PS)
                            if ($existingSpecialReserve > 0) {
                                $ruleLossReserve = PostingRule::where('business_event_filter', 'loss_reserve_amount')->first();
                                if (!$ruleLossReserve) {
                                    throw new \RuntimeException('Posting rule for loss_reserve_amount not found');
                                }

                                $lossDoc = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                    'amount_amd' => $existingSpecialReserve,
                                    'debit_partner_id' => $clientId,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Loss: zero special reserve for client #{$clientId}",
                                    'debit_account_id' => $ruleLossReserve->debit_account_id,
                                    'credit_account_id' => $ruleLossReserve->credit_account_id,
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'journalable_type' => DocumentJournal::class,
                                    'journalable_id' => $journal->id,
                                ]);

                                Transaction::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                    'debit_account_id' => $ruleLossReserve->debit_account_id,
                                    'debit_partner_id' => $clientId,
                                    'debit_currency_id' => 1,
                                    'credit_account_id' => $ruleLossReserve->credit_account_id,
                                    'credit_currency_id' => 1,
                                    'credit_partner_id' => $clientId,
                                    'amount_amd' => $existingSpecialReserve,
                                    'comment' => "Loss: zero special reserve for client #{$clientId}",
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'is_system' => true,
                                    'disbursement_date' => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $lossDoc->id,
                                ]);

                                $nextDocNum++;
                            }

                            // Zero out 16200 (effective interest) balance for this client
                            $balance16200 = $this->getClientAccountBalance($clientId, $acc16200,$now);
                            if ($balance16200 > 0) {
                                $ruleLossEffective = PostingRule::where('business_event_filter', 'loss_reserve_effective')->first();
                                if (!$ruleLossEffective) {
                                    throw new \RuntimeException('Posting rule for loss_reserve_effective not found');
                                }

                                $lossEffDoc = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                                    'amount_amd' => $balance16200,
                                    'debit_partner_id' => $clientId,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Loss: zero 16200 for client #{$clientId}",
                                    'debit_account_id' => $ruleLossEffective->debit_account_id,
                                    'credit_account_id' => $ruleLossEffective->credit_account_id,
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'journalable_type' => DocumentJournal::class,
                                    'journalable_id' => $journal->id,
                                ]);

                                Transaction::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                                    'debit_account_id' => $ruleLossEffective->debit_account_id,
                                    'debit_partner_id' => $clientId,
                                    'debit_currency_id' => 1,
                                    'credit_account_id' => $ruleLossEffective->credit_account_id,
                                    'credit_currency_id' => 1,
                                    'credit_partner_id' => $clientId,
                                    'amount_amd' => $balance16200,
                                    'comment' => "Loss: zero 16200 for client #{$clientId}",
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'is_system' => true,
                                    'disbursement_date' => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $lossEffDoc->id,
                                ]);

                                $nextDocNum++;
                            }

                            // Zero out 16200NV (nominal interest) balance for this client
                            $balance16200NV = $this->getClientAccountBalance($clientId, $acc16200NV);
                            if ($balance16200NV > 0) {
                                $lossNVDoc = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                    'amount_amd' => $balance16200NV,
                                    'debit_partner_id' => $clientId,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Loss: zero 16200NV for client #{$clientId}",
                                    'debit_account_id' => $acc16605PS,
                                    'credit_account_id' => $acc16200NV,
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'journalable_type' => DocumentJournal::class,
                                    'journalable_id' => $journal->id,
                                ]);

                                Transaction::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                    'debit_account_id' => $acc16605PS,
                                    'debit_partner_id' => $clientId,
                                    'debit_currency_id' => 1,
                                    'credit_account_id' => $acc16200NV,
                                    'credit_currency_id' => 1,
                                    'credit_partner_id' => $clientId,
                                    'amount_amd' => $balance16200NV,
                                    'comment' => "Loss: zero 16200NV for client #{$clientId}",
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'is_system' => true,
                                    'disbursement_date' => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $lossNVDoc->id,
                                ]);

                                $nextDocNum++;
                            }

                            // Zero out 16201NI (nominal interest receivable) balance for this client
                            $balance16201NI = $this->getClientAccountBalance($clientId, $acc16201NI);
                            if ($balance16201NI > 0) {
                                $ruleLoss = PostingRule::where('business_event_filter', 'loss_reserve')->first();
                                if (!$ruleLoss) {
                                    throw new \RuntimeException('Posting rule for loss_reserve not found');
                                }

                                $lossNIDoc = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE,
                                    'amount_amd' => $balance16201NI,
                                    'debit_partner_id' => $clientId,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Loss: zero 16201NI for client #{$clientId}",
                                    'debit_account_id' => $ruleLoss->debit_account_id,
                                    'credit_account_id' => $ruleLoss->credit_account_id,
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'journalable_type' => DocumentJournal::class,
                                    'journalable_id' => $journal->id,
                                ]);

                                Transaction::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => DocumentJournal::LOSS_RESERVE,
                                    'debit_account_id' => $ruleLoss->debit_account_id,
                                    'debit_partner_id' => $clientId,
                                    'debit_currency_id' => 1,
                                    'credit_account_id' => $ruleLoss->credit_account_id,
                                    'credit_currency_id' => 1,
                                    'credit_partner_id' => $clientId,
                                    'amount_amd' => $balance16201NI,
                                    'comment' => "Loss: zero 16201NI for client #{$clientId}",
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'is_system' => true,
                                    'disbursement_date' => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $lossNIDoc->id,
                                ]);

                                $nextDocNum++;
                            }
                        }

                        // If old reserve existed and old classification was 'standard' — create classification reversal doc

                        Log::info("Finished processing client #{$client->id}.");
                        DB::commit();

                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
                    }
                }
            });

        Log::info('Client classification job finished.');
    }


    private function getClientAccountsBalance(int $clientId, array $accountIds, ?string $date = null): float
    {
        if (empty($accountIds)) {
            return 0.0;
        }

        $debit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $clientId)
            ->whereIn('debit_account_id', $accountIds)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        $credit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $clientId)
            ->whereIn('credit_account_id', $accountIds)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        return (float)$debit - (float)$credit;
    }

    /**
     * Get balance of a single account for a given partner.
     */
    private function getClientAccountBalance(int $clientId, ?int $accountId, $date=null): float
    {
        if (!$accountId) {
            return 0.0;
        }

        $debit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $clientId)
            ->where('debit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        $credit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $clientId)
            ->where('credit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        return (float)$debit - (float)$credit;
    }

    private function getClientReserveBalance(int $clientId, ?int $accountId, $date=null): float
    {
        if (!$accountId) {
            return 0.0;
        }

        $credit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $clientId)
            ->where('credit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        $debit = DB::table('transactions')
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $clientId)
            ->where('debit_account_id', $accountId)
            ->when($date, fn($q) => $q->where('date', '<=', $date))
            ->sum('amount_amd');

        return (float)$credit - (float)$debit;
    }
}
