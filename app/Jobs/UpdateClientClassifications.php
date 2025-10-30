<?php

namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractReserveHistory;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

                $acc73015 = ChartOfAccount::idByCode('73015') ?? 1;
                $acc16605PC = ChartOfAccount::idByCode('16605PC') ?? 1;
                $acc16605PS = ChartOfAccount::idByCode('16605PS') ?? 1;
                $acc63015 = ChartOfAccount::idByCode('63015') ?? 1;

               $creditPartnerId = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;

                $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;

                foreach ($clients as $client) {

                    try {
                        Log::info('Processing client', ['id' => $client->id]);

                        $maxOverdue = $service->maxOverdueDaysForClient($client);
                        $classification = $service->classificationByOverdue($maxOverdue);
                        Log::info("calculated classification is {$classification} ");

                        Log::info("calculated maxOverdue is {$maxOverdue} ");

                        if ($client->classification_id !== $classification->id) {

                            $oldReservePercent = $client->classification?->reserve_percent ?? 0;
                            Log::info("old reserve percent is {$oldReservePercent} ");

//                            $oldClassificationId = $client->classification_id;
                            $client->classification_id = $classification->id;
                            $client->save();
                            $client->load('classification');

                            $reserverPercent = $client->classification?->reserve_percent ?? 0;
                            $debetPartnerId = $client->id;

                            $document_type = $client->classification?->name == 'standard' ?
                                DocumentJournal::RESERVE_GENERAL_AMOUNT : DocumentJournal::RESERVE_SPECIAL_AMOUNT;
                            Log::info("doc type is { $document_type} ");

//                            Log::info("Client #{$client->id} classification updated from {$oldClassificationId} to {$classification->id} ({$classification->name}) with reserve percent {$reserverPercent}%");

                            /** @var Contract $contract */
                            foreach ($client->contracts as $contract) {

                                $reserveAmount = $contract->provided_amount * $reserverPercent / 100;
                                $oldReserveAmount = $contract->provided_amount * $oldReservePercent / 100;

                                $amount = $reserveAmount - $oldReserveAmount;

                                ContractReserveHistory::create([
                                    'client_id' => $client->id,
                                    'classification_id' => $classification->id,
                                    'contract_id' => $contract->id,
                                    'risk_weight' => $client->classification?->risk_weight ?? 0,
                                    'reserve_percent' => $reserverPercent,
                                    'reserve_amount' => $reserveAmount,
                                    'total_reserve_amount' => $amount,
                                    'provided_amount' => $contract->provided_amount,
                                    'date' => now()->toDateString(),
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'meta' => [
                                        'old_reserve_percent' => $oldReservePercent,
                                        'old_reserve_amount' => $oldReserveAmount,
                                    ],
                                ]);
                                Log::info("amount is { $amount} ");

                                if ($amount <= 0) {
                                    continue;
                                }
                                $debetAllocation = $acc73015;
                                $creditAllocation = $client->classification->name == 'standard' ? $acc16605PC : $acc16605PS;

                                $debetClassification = $client->classification->name == 'standard' ? $acc16605PS : $acc16605PC;
                                $creditClassification = $client->classification->name == 'standard' ? $acc16605PC : $acc16605PS;

                                $journal = DocumentJournal::where('journalable_type', Contract::class)
                                    ->where('journalable_id', $contract->id)
                                    ->first();

                                $journalDoc = DocumentJournal::create([
                                    'date'               => now()->toDateString(),
                                    'document_number'    => $nextDocNum,
                                    'document_type'      => $document_type,
                                    'amount_amd'         => $amount,
                                    'partner_id'         => $debetPartnerId,
                                    'credit_partner_id'  => $creditPartnerId,
                                    'comment'            => "Reserve for contract #{$contract->id} due to classification change",
                                    'debit_account_id'   => $debetAllocation,
                                    'credit_account_id'  => $creditAllocation,
                                    'user_id'            => auth()->check() ? auth()->id() : 1,
                                    'journalable_type'   => DocumentJournal::class,
                                    'journalable_id'     => $journal->id,
                                ]);
                                Transaction::create([
                                    'date'               => now()->toDateString(),
                                    'document_number'    => $nextDocNum,
                                    'document_type'      => $document_type,

                                    'debit_account_id'   => $debetAllocation,
                                    'debit_partner_id'   => $debetPartnerId,
                                    'debit_currency_id'  => 1,

                                    'credit_account_id'  => $creditAllocation,
                                    'credit_currency_id' => 1,
                                    'credit_partner_id'  => $creditPartnerId,

                                    'amount_amd'         => $amount,

                                    'comment'            => "Reserve for contract #{$contract->id}",
                                    'user_id'            => auth()->check() ? auth()->id() : 1,
                                    'is_system'          => true,

                                    'disbursement_date'    => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id'   => $journal->id,
                                ]);
                                $nextDocNum++;
                                if ($client->classification->name == 'loss')
                                {
                                    $lossType = DocumentJournal::LOSS_RESERVE_AMOUNT;
                                    $acc16200NV = ChartOfAccount::idByCode('16200NV') ?? 1;

                                    $journalDocoss = DocumentJournal::create([
                                        'date'               => now()->toDateString(),
                                        'document_number'    => $nextDocNum,
                                        'document_type'      => $lossType,
                                        'amount_amd'         => $contract->provided_amount,
                                        'partner_id'         => $debetPartnerId,
                                        'credit_partner_id'  => $creditPartnerId,
                                        'comment'            => "Loss client, reserve for contract #{$contract->id} due to classification change",
                                        'debit_account_id'   => $acc16605PS,
                                        'credit_account_id'  => $acc16200NV,
                                        'user_id'            => auth()->check() ? auth()->id() : 1,
                                        'journalable_type'   => DocumentJournal::class,
                                        'journalable_id'     => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date'               => now()->toDateString(),
                                        'document_number'    => $nextDocNum,
                                        'document_type'      => $lossType,

                                        'debit_account_id'   => $acc16605PS,
                                        'debit_partner_id'   => $debetPartnerId,
                                        'debit_currency_id'  => 1,

                                        'credit_account_id'  => $acc16200NV,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id'  => $creditPartnerId,

                                        'amount_amd'         => $contract->provided_amount,

                                        'comment'            => "Loss client,reserve for contract #{$contract->id}",
                                        'user_id'            => auth()->check() ? auth()->id() : 1,
                                        'is_system'          => true,

                                        'disbursement_date'    => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id'   => $journal->id,
                                    ]);
                                    $nextDocNum++;
                                }

                                if ($oldReserveAmount > 0) {
                                    $classificationType = DocumentJournal::CLASSIFICATION;

                                    $journalDoc = DocumentJournal::create([
                                        'date'               => now()->toDateString(),
                                        'document_number'    => $nextDocNum,
                                        'document_type'      => $classificationType,
                                        'amount_amd'         => $oldReserveAmount,
                                        'partner_id'         => $debetPartnerId,
                                        'credit_partner_id'  => $creditPartnerId,
                                        'comment'            => "Reserve for contract #{$contract->id} due to classification change",
                                        'debit_account_id'   => $debetClassification,
                                        'credit_account_id'  => $creditClassification,
                                        'user_id'            => auth()->check() ? auth()->id() : 1,
                                        'journalable_type'   => DocumentJournal::class,
                                        'journalable_id'     => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date'               => now()->toDateString(),
                                        'document_number'    => $nextDocNum,
                                        'document_type'      => $classificationType,

                                        'debit_account_id'   => $debetClassification,
                                        'debit_partner_id'   => $debetPartnerId,
                                        'debit_currency_id'  => 1,

                                        'credit_account_id'  => $creditClassification,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id'  => $creditPartnerId,

                                        'amount_amd'         => $oldReserveAmount,

                                        'comment'            => "Reserve for contract #{$contract->id}",
                                        'user_id'            => auth()->check() ? auth()->id() : 1,
                                        'is_system'          => true,

                                        'disbursement_date'    => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id'   => $journal->id,
                                    ]);
                                }


                                Log::info("Created reserve transaction for contract #{$contract->id} with amount {$reserveAmount} AMD.");
                            }
                            $nextDocNum++;
                        }

                    } catch (\Throwable $e) {
                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
                    }
                }
            });

        Log::info('Client classification job finished.');
    }
}
