<?php

namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\ClassificationHistory;
use App\Models\Contract;
use App\Models\ContractModification;
use App\Models\ContractReserveHistory;
use App\Models\DocumentJournal;
use App\Models\Modification;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateClientClassifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct() {}
    public function handle(ClientClassificationService $service): void
    {
        Log::info('Client classification job started...');

        Client::whereHas('contracts', function (Builder $q) {
            $q->where('status', 'initial');
        })->with(['contracts' => function ($q) {
            $q->where('status', 'initial');
        }, 'classification'])
            ->chunkById(200, function ($clients) use ($service) {

                $acc73015 = ChartOfAccount::idByCode('73015');
                $acc16605PC = ChartOfAccount::idByCode('16605PC');
                $acc16605PS = ChartOfAccount::idByCode('16605PS');
                $acc16200NV = ChartOfAccount::idByCode('16200NV');
                $acc16200 = ChartOfAccount::idByCode('16200');
                $acc16201NI = ChartOfAccount::idByCode('16201NI');
                $acc63015 = ChartOfAccount::idByCode('63015');
                $acc86000 = ChartOfAccount::idByCode('86000');
                $acc86001 = ChartOfAccount::idByCode('86001');

                $diamondId = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;

                $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;

                foreach ($clients as $client) {

                    try {
                        Log::info('Processing client', ['id' => $client->id]);

                        DB::beginTransaction();

                        $maxOverdue = $service->maxOverdueDaysForClient($client);
                        $classification = $service->classificationByOverdue($maxOverdue);

                        Log::info("calculated classification is {$classification->id} ");
                        Log::info("calculated maxOverdue is {$maxOverdue} ");

                        if ($client->classification_id === $classification->id) {
                            DB::commit();
                            continue;
                        }

                        // Save old values
                        $oldClassificationName = $client->classification?->name;
                        $oldClassificationId = $client->classification?->id;
                        $oldClassificationOrder = $client->classification?->order;
                        $oldReservePercent = $client->classification?->reserve_percent ?? 0;
                        Log::info("old reserve percent is {$oldReservePercent} ");

                        // Update client classification
                        $client->classification_id = $classification->id;
                        $client->save();
                        $client->load('classification');

                        $newClassificationName = $client->classification?->name;
                        $newReservePercent = $client->classification?->reserve_percent ?? 0;
                        $newRiskWeight = $client->classification?->risk_weight ?? 0;
                        $newClassificationOrder = $client->classification?->order;
                        $clientId = $client->id;

                        // If new classification is not 'standard' the controller subtracts oldReservePercent:
                        if ($newClassificationName !== 'standard') {
                            $newReservePercent -= $oldReservePercent;
                        }
                        $oldRisk = $oldClassificationOrder !== null ? max(0, min(7, (int)$oldClassificationOrder)) : null;
                        $newRisk = $newClassificationOrder !== null ? max(0, min(7, (int)$newClassificationOrder)) : 0;

                        Modification::create([
                            'subject_type' => Client::class,
                            'subject_id' => $clientId->id,
                            'modification_type'  => 'Modificator',
                            'field_code' => 'RISK',
                            'element_code' => 'Risk',
                            'old_value' => $oldRisk !== null ? (string)$oldRisk : null,
                            'new_value' => (string)$newRisk,
                            'effective_date' => now()->toDateString(),
                        ]);
                        // Loop contracts
                        foreach ($client->contracts as $contract) {

                            $journal = DocumentJournal::where('journalable_type', Contract::class)
                                ->where('journalable_id', $contract->id)
                                ->first();

                            // compute existing reserve amounts tied to that journal (if any)
                            $reserveAmount = 0;
                            $amount16605PC = 0;
                            $amount16605PS = 0;

                            if ($journal) {
                                // sum previous reserve special/general amounts (some code paths used different fields)
                                $reserveAmount = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where(function ($q) {
                                        $q->where('document_type', DocumentJournal::RESERVE_SPECIAL_AMOUNT)
                                            ->orWhere('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT)
                                            ->orWhere('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT);
                                    })
                                    ->sum('amount_amd');

                                // sum old 16605PC credits (used later for reversing old 'standard' classification)
                                $amount16605PC = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT)
                                    ->where('credit_account_id', $acc16605PC)
                                    ->sum('amount_amd');

                                $amount16605PS = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16605PS)
                                    ->sum('amount_amd');
                            }

                            // controller logic: amount is (provided + reserveAmount) * newReservePercent/100
                            $amount = ($contract->provided_amount + $reserveAmount) * ($newReservePercent / 100);
                            $oldReserveAmount = ($contract->provided_amount + $reserveAmount) * ($oldReservePercent / 100);

                            // record reserve history (similar to controller)
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
                                    'old_reserve_amount' => $oldReserveAmount,
                                ],
                            ]);

                            Log::info("calculated reserve amount for contract {$contract->id}: {$amount}");

                            if ($amount <= 0 && $client->classification->name !== 'loss') {
                                // nothing to post (controller continued)
                                continue;
                            }

                            // accounts and document types depending on classification name
                            if ($client->classification->name === 'standard') {

                                $ruleReserve = PostingRule::where('business_event_filter', 'reserve_general_amount')
                                    ->first();

                                if (!$ruleReserve) {
                                    throw new \RuntimeException('Posting rule for reserve_general_amount not found');
                                }

                                $debitReserve = $ruleReserve->debit_account_id;
                                $creditReserve = $ruleReserve->credit_account_id;
                            } else {
                                $ruleReserve = PostingRule::where('business_event_filter', 'reserve_special_amount')
                                    ->first();

                                if (!$ruleReserve) {
                                    throw new \RuntimeException('Posting rule for reserve_special_amount not found');
                                }

                                $debitReserve = $ruleReserve->debit_account_id;
                                $creditReserve = $ruleReserve->credit_account_id;
                            }
                            if ($oldClassificationName === 'standard') {
                                $ruleClassification = PostingRule::where('business_event_filter', 'classification_general_to_special')->firstOrFail();
                            } else {
                                $ruleClassification = PostingRule::where('business_event_filter', 'classification_special_to_general')->firstOrFail();
                            }
                            $debitClassification  = $ruleClassification->debit_account_id;
                            $creditClassification = $ruleClassification->credit_account_id;

                            $documentType = $client->classification->name === 'standard'
                                ? DocumentJournal::RESERVE_GENERAL_AMOUNT
                                : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                            // require a base journal to attach DocumentJournal -> if none, skip (controller did similar)
                            if (!$journal) {
                                continue;
                            }

                            // create reserve document journal
                            if ($amount > 0) {
                                $docJournal = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => $documentType,
                                    'amount_amd' => $amount,
                                    'debit_partner_id' => $diamondId,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Reserve for contract #{$contract->id} due to classification change",
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
                                    'comment' => "Reserve for contract #{$contract->id}",
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
                                        'old_reserve_amount' => $oldReserveAmount,
                                    ],
                                    'date' => now(),
                                ]);

                                $nextDocNum++;
                            }

                            // If new classification is 'loss' — write off all outstanding balances
                            if ($client->classification->name === 'loss') {

                                // Net 16200 (effective interest receivable): debit minus credit
                                $debit16200 = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('debit_account_id', $acc16200)
                                    ->sum('amount_amd');
                                $credit16200 = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16200)
                                    ->sum('amount_amd');
                                $net16200 = $debit16200 - $credit16200;

                                // Net 16200NV (nominal principal): debit from contract minus credits already posted
                                $debit16200NV = DocumentJournal::where('journalable_type', Contract::class)
                                    ->where('journalable_id', $contract->id)
                                    ->where('debit_account_id', $acc16200NV)
                                    ->sum('amount_amd');
                                $credit16200NV = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16200NV)
                                    ->sum('amount_amd');
                                $net16200NV = $debit16200NV - $credit16200NV;

                                // Net 16201NI (accrued nominal interest): debit minus credit
                                $debit16201NI = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('debit_account_id', $acc16201NI)
                                    ->sum('amount_amd');
                                $credit16201NI = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16201NI)
                                    ->sum('amount_amd');
                                $net16201NI = $debit16201NI - $credit16201NI;

                                // Entry 1: Dr 16605PS / Cr 16200
                                if ($net16200 != 0) {
                                    $lossEff16200Doc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                                        'amount_amd' => $net16200,
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Write-off 16200 for contract #{$contract->id} - loss classification",
                                        'debit_account_id' => $acc16605PS,
                                        'credit_account_id' => $acc16200,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                                        'debit_account_id' => $acc16605PS,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $acc16200,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => $net16200,
                                        'comment' => "Write-off 16200 for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $lossEff16200Doc->id,
                                    ]);
                                    $nextDocNum++;
                                }

                                // Entry 2: Dr 16605PS / Cr 16200NV
                                if ($net16200NV != 0) {
                                    $lossNVDoc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                        'amount_amd' => $net16200NV,
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Write-off 16200NV for contract #{$contract->id} - loss classification",
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
                                        'amount_amd' => $net16200NV,
                                        'comment' => "Write-off 16200NV for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $lossNVDoc->id,
                                    ]);
                                    $nextDocNum++;
                                }

                                // Entry 3: Dr 86000 — combined net of 16200 + 16200NV
                                $amount86000 = $net16200 + $net16200NV;
                                if ($amount86000 != 0) {
                                    $rule86000 = PostingRule::where('business_event_filter', 'loss_writeoff_principal')->first();
                                    if (!$rule86000) {
                                        throw new \RuntimeException('Posting rule for loss_writeoff_principal not found');
                                    }
                                    $loss86000Doc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                        'amount_amd' => $amount86000,
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Loss write-off expense 86000 for contract #{$contract->id}",
                                        'debit_account_id' => $acc86000,
                                        'credit_account_id' => $rule86000->credit_account_id,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                        'debit_account_id' => $acc86000,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $rule86000->credit_account_id,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => $amount86000,
                                        'comment' => "Loss write-off expense 86000 for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $loss86000Doc->id,
                                    ]);
                                    $nextDocNum++;
                                }

                                // Entry 4: Dr 16605PS / Cr 16201NI
                                if ($net16201NI != 0) {
                                    $lossNIDoc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'amount_amd' => $net16201NI,
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Write-off 16201NI for contract #{$contract->id} - loss classification",
                                        'debit_account_id' => $acc16605PS,
                                        'credit_account_id' => $acc16201NI,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'debit_account_id' => $acc16605PS,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $acc16201NI,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => $net16201NI,
                                        'comment' => "Write-off 16201NI for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $lossNIDoc->id,
                                    ]);
                                    $nextDocNum++;
                                }

                                // Entry 5: Dr 86001 — same amount as 16201NI net
                                if ($net16201NI != 0) {
                                    $rule86001 = PostingRule::where('business_event_filter', 'loss_writeoff_interest')->first();
                                    if (!$rule86001) {
                                        throw new \RuntimeException('Posting rule for loss_writeoff_interest not found');
                                    }
                                    $loss86001Doc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'amount_amd' => $net16201NI,
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Loss write-off expense 86001 for contract #{$contract->id}",
                                        'debit_account_id' => $acc86001,
                                        'credit_account_id' => $rule86001->credit_account_id,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'debit_account_id' => $acc86001,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $rule86001->credit_account_id,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => $net16201NI,
                                        'comment' => "Loss write-off expense 86001 for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $loss86001Doc->id,
                                    ]);
                                    $nextDocNum++;
                                }
                            } // end if loss

                            // If old reserve existed and old classification was 'standard' — create classification reversal doc
                            if (!empty($amount16605PC) && $oldClassificationName === 'standard') {
                                $classificationType = DocumentJournal::CLASSIFICATION;

                                $classificationDoc = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => $classificationType,
                                    'amount_amd' => $amount16605PC,
                                    'debit_partner_id' => $clientId,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Old reserve for contract #{$contract->id} due to classification change",
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
                                    'amount_amd' => $amount16605PC,
                                    'comment' => "Old reserve for contract #{$contract->id}",
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'is_system' => true,
                                    'disbursement_date' => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $classificationDoc->id,
                                ]);

                                $nextDocNum++;
                            }

                            Log::info("Finished processing contract {$contract->id} for client {$client->id}.");
                        } // end foreach contracts

                        DB::commit();
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
                        // continue to next client (do not rethrow)
                    }
                }
            });

        Log::info('Client classification job finished.');
    }



//    public function handle(ClientClassificationService $service): void
//    {
//        Log::info('Client classification job started...');
//
//        Client::whereHas('contracts', function (Builder $q) {
//            $q->where('status', 'initial');
//        })->with(['contracts' => function ($q) {
//                $q->where('status', 'initial');
//            }, 'classification'])
//            ->chunkById(200, function ($clients) use ($service) {
//
//                $acc73015 = ChartOfAccount::idByCode('73015') ?? 1;
//                $acc16605PC = ChartOfAccount::idByCode('16605PC') ?? 1;
//                $acc16605PS = ChartOfAccount::idByCode('16605PS') ?? 1;
//                $acc63015 = ChartOfAccount::idByCode('63015') ?? 1;
//
//               $diamondId = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;
//
//                $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;
//
//                foreach ($clients as $client) {
//
//                    try {
//                        Log::info('Processing client', ['id' => $client->id]);
//
//                        $maxOverdue = $service->maxOverdueDaysForClient($client);
//                        $classification = $service->classificationByOverdue($maxOverdue);
//                        Log::info("calculated classification is {$classification} ");
//
//                        Log::info("calculated maxOverdue is {$maxOverdue} ");
//
//                        if ($client->classification_id !== $classification->id) {
//                            $oldClassificationName = $client->classification?->name;
//                            $oldClassificationId = $client->classification?->id;
//                            $oldReservePercent = $client->classification?->reserve_percent ?? 0;
//                            Log::info("old reserve percent is {$oldReservePercent} ");
//
////                            $oldClassificationId = $client->classification_id;
//                            $client->classification_id = $classification->id;
//                            $client->save();
//                            $client->load('classification');
//
//                            $reserverPercent = $client->classification?->reserve_percent ?? 0;
//                            $clientId = $client->id;
//
//                            $document_type = $client->classification?->name == 'standard' ?
//                                DocumentJournal::RESERVE_GENERAL_AMOUNT : DocumentJournal::RESERVE_SPECIAL_AMOUNT;
//                            Log::info("doc type is { $document_type} ");
//
//                            /** @var Contract $contract */
//                            foreach ($client->contracts as $contract) {
//
//                                  $journal = DocumentJournal::where('journalable_type', Contract::class)
//                                      ->where('journalable_id', $contract->id)
//                                      ->first();
//
//                                  $reserveAmount = 0;
//
//                                if ($journal) {
//                                    $reserveAmount = DocumentJournal::where('journalable_type', DocumentJournal::class)
//                                        ->where('journalable_id', $journal->id)
//                                        ->where(function ($q) {
//                                            $q->where('document_type', DocumentJournal::RESERVE_SPECIAL_AMOUNT)
//                                                ->orWhere('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT);
//                                        })
//                                        ->sum('amount');
//                                }
////                                $reserveAmount = $contract->provided_amount * $reserverPercent / 100;
//                                $oldReserveAmount = $contract->provided_amount * $oldReservePercent / 100;
////
////                                $amount = $reserveAmount - $oldReserveAmount;
//
//                                ContractReserveHistory::create([
//                                    'client_id' => $client->id,
//                                    'classification_id' => $classification->id,
//                                    'contract_id' => $contract->id,
//                                    'risk_weight' => $client->classification?->risk_weight ?? 0,
//                                    'reserve_percent' => $reserverPercent,
//                                    'reserve_amount' => $reserveAmount,
//                                    'total_reserve_amount' => $reserveAmount,
//                                    'provided_amount' => $contract->provided_amount,
//                                    'date' => now()->toDateString(),
//                                    'user_id' => auth()->check() ? auth()->id() : 1,
//                                    'meta' => [
//                                        'old_reserve_percent' => $oldReservePercent,
////                                        'old_reserve_amount' => $oldReserveAmount,
//                                    ],
//                                ]);
//
//                                Log::info("amount is { $reserveAmount} ");
//
//                                if ($reserveAmount <= 0) {
//                                    continue;
//                                }
//                                $debetAllocation = $acc73015;
//                                $creditAllocation = $client->classification->name == 'standard' ? $acc16605PC : $acc16605PS;
//
//                                $debetClassification = $client->classification->name == 'standard' ? $acc16605PS : $acc16605PC;
//                                $creditClassification = $client->classification->name == 'standard' ? $acc16605PC : $acc16605PS;
//
//                                $journal = DocumentJournal::where('journalable_type', Contract::class)
//                                    ->where('journalable_id', $contract->id)
//                                    ->first();
//
//                                $journalDoc = DocumentJournal::create([
//                                    'date'               => now()->toDateString(),
//                                    'document_number'    => $nextDocNum,
//                                    'document_type'      => $document_type,
//                                    'amount_amd'         => $reserveAmount,
//                                    'partner_id'         => $diamondId,
//                                    'credit_partner_id'  => $clientId,
//                                    'comment'            => "Reserve for contract #{$contract->id} due to classification change",
//                                    'debit_account_id'   => $debetAllocation,
//                                    'credit_account_id'  => $creditAllocation,
//                                    'user_id'            => auth()->check() ? auth()->id() : 1,
//                                    'journalable_type'   => DocumentJournal::class,
//                                    'journalable_id'     => $journal->id,
//                                ]);
//                                Transaction::create([
//                                    'date'               => now()->toDateString(),
//                                    'document_number'    => $nextDocNum,
//                                    'document_type'      => $document_type,
//
//                                    'debit_account_id'   => $debetAllocation,
//                                    'debit_partner_id'   => $diamondId,
//                                    'debit_currency_id'  => 1,
//
//                                    'credit_account_id'  => $creditAllocation,
//                                    'credit_currency_id' => 1,
//                                    'credit_partner_id'  => $clientId,
//
//                                    'amount_amd'         => $reserveAmount,
//
//                                    'comment'            => "Reserve for contract #{$contract->id}",
//                                    'user_id'            => auth()->check() ? auth()->id() : 1,
//                                    'is_system'          => true,
//
//                                    'disbursement_date'    => now()->toDateString(),
//                                    'transactionable_type' => DocumentJournal::class,
//                                    'transactionable_id'   => $journalDoc->id,
//                                ])
//                                ;
//                                ClassificationHistory::create([
//                                    'client_id' => $client->id,
//                                    'classification_id' => $classification->id,
//                                    'risk_weight' => $client->classification?->risk_weight ?? 0,
//                                    'reserve_percent' => $client->classification?->reserve_percent ?? 0,
//                                    'comment' => 'Automatic client classification update based on overdue days',
//                                    'actionable_type' => DocumentJournal::class,
//                                    'actionable_id' => $journalDoc->id,
//                                    'user_id' => auth()->id() ?? 1,
//                                    'meta' => [
//                                        'old_classification_id' => $oldClassificationId,
//                                        'old_classification_name' => $oldClassificationName,
//                                        'old_reserve_percent' => $oldReservePercent,
////                                        'old_reserve_amount' => $oldReserveAmount,
//                                    ],
//                                    'date' => now(),
//                                ]);
//
//                                $nextDocNum++;
//                                if ($client->classification->name == 'loss')
//                                {
//                                    $lossType = DocumentJournal::LOSS_RESERVE_AMOUNT;
//                                    $acc16200NV = ChartOfAccount::idByCode('16200NV') ?? 1;
//
//                                    $journaLoss = DocumentJournal::create([
//                                        'date'               => now()->toDateString(),
//                                        'document_number'    => $nextDocNum,
//                                        'document_type'      => $lossType,
//                                        'amount_amd'         => $contract->provided_amount,
//                                        'partner_id'         => $clientId,
//                                        'credit_partner_id'  => $clientId,
//                                        'comment'            => "Loss client, reserve for contract #{$contract->id} due to classification change",
//                                        'debit_account_id'   => $acc16605PS,
//                                        'credit_account_id'  => $acc16200NV,
//                                        'user_id'            => auth()->check() ? auth()->id() : 1,
//                                        'journalable_type'   => DocumentJournal::class,
//                                        'journalable_id'     => $journal->id,
//                                    ]);
//                                    Transaction::create([
//                                        'date'               => now()->toDateString(),
//                                        'document_number'    => $nextDocNum,
//                                        'document_type'      => $lossType,
//
//                                        'debit_account_id'   => $acc16605PS,
//                                        'debit_partner_id'   => $clientId,
//                                        'debit_currency_id'  => 1,
//
//                                        'credit_account_id'  => $acc16200NV,
//                                        'credit_currency_id' => 1,
//                                        'credit_partner_id'  => $clientId,
//
//                                        'amount_amd'         => $contract->provided_amount,
//
//                                        'comment'            => "Loss client,reserve for contract #{$contract->id}",
//                                        'user_id'            => auth()->check() ? auth()->id() : 1,
//                                        'is_system'          => true,
//
//                                        'disbursement_date'    => now()->toDateString(),
//                                        'transactionable_type' => DocumentJournal::class,
//                                        'transactionable_id'   => $journaLoss->id,
//                                    ]);
//                                    $nextDocNum++;
//                                }
//
//                                if ($oldReserveAmount > 0 && $oldClassificationName == 'standard') {
//                                    $classificationType = DocumentJournal::CLASSIFICATION;
//
//                                    $journalDoc = DocumentJournal::create([
//                                        'date'               => now()->toDateString(),
//                                        'document_number'    => $nextDocNum,
//                                        'document_type'      => $classificationType,
//                                        'amount_amd'         => $oldReserveAmount,
//                                        'partner_id'         => $clientId,
//                                        'credit_partner_id'  => $clientId,
//                                        'comment'            => "Reserve for contract #{$contract->id} due to classification change",
//                                        'debit_account_id'   => $debetClassification,
//                                        'credit_account_id'  => $creditClassification,
//                                        'user_id'            => auth()->check() ? auth()->id() : 1,
//                                        'journalable_type'   => DocumentJournal::class,
//                                        'journalable_id'     => $journal->id,
//                                    ]);
//                                    Transaction::create([
//                                        'date'               => now()->toDateString(),
//                                        'document_number'    => $nextDocNum,
//                                        'document_type'      => $classificationType,
//
//                                        'debit_account_id'   => $debetClassification,
//                                        'debit_partner_id'   => $clientId,
//                                        'debit_currency_id'  => 1,
//
//                                        'credit_account_id'  => $creditClassification,
//                                        'credit_currency_id' => 1,
//                                        'credit_partner_id'  => $clientId,
//
//                                        'amount_amd'         => $oldReserveAmount,
//
//                                        'comment'            => "Reserve for contract #{$contract->id}",
//                                        'user_id'            => auth()->check() ? auth()->id() : 1,
//                                        'is_system'          => true,
//
//                                        'disbursement_date'    => now()->toDateString(),
//                                        'transactionable_type' => DocumentJournal::class,
//                                        'transactionable_id'   => $journalDoc->id,
//                                    ]);
//                                }
//
//
//                                Log::info("Created reserve transaction for contract #{$contract->id} with amount {$reserveAmount} AMD.");
//                            }
//                            $nextDocNum++;
//                        }
//
//                    } catch (\Throwable $e) {
//                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
//                    }
//                }
//            });
//
//        Log::info('Client classification job finished.');
//    }

}
