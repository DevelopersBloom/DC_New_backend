<?php

namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Contract;
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
        })
            ->with('contracts.payments')
            ->chunkById(200, function ($clients) use ($service) {
                foreach ($clients as $client) {

                    try {
                        Log::info('Processing client', ['id' => $client->id]);

                        $maxOverdue = $service->maxOverdueDaysForClient($client);
                        $classification = $service->classificationByOverdue($maxOverdue);

                        if ($client->classification_id !== $classification->id) {
                            $client->classification_id = $classification->id;
                            $client->save();
//                            $reserverPercent = $client->classification->reserve_percent ?? 0;
//                            $acc16200NV = ChartOfAccount::idByCode('16200NV');
//                            $acc10210 = ChartOfAccount::idByCode('10210');
//                            $amount = $contract->provided_amount * $reserverPercent / 100;
//
//                            $creditPartnerId = Client::where('company_name','Diamond Credit')->first()->id ?? 1;
//                            $debetPartnerId = $contract->client_id;
//
//                            if (!$acc16200NV || !$acc10210) return 'One of 16200NV, 10210 not exist';
//
//                            $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;
//                            $document_type = DocumentJournal::PROVIDE_CONTRACT_AMOUNT;
//
//                            $journalDoc = DocumentJournal::create([
//                                'date'               => $contract->date,
//                                'document_number'    => $nextDocNum,
//                                'document_type'      => $document_type,
//                                'amount_amd'         => $contract->provided_amount,
//                                'partner_id'         => $debetPartnerId,
//                                'credit_partner_id'  => $creditPartnerId,
//                                'comment'            => 'contract_payment',
//                                'debit_account_id'   => $acc16200NV,
//                                'credit_account_id'  => $acc10210,
//                                'user_id'            => auth()->id(),
//                                'journalable_type'   => Contract::class,
//                                'journalable_id'     => $contract->id,
//                            ]);
//
//                            Transaction::create([
//                                'date'               => $contract->date,
//                                'document_number'    => $nextDocNum,
//                                'document_type'      => $document_type,
//
//                                'debit_account_id'   => $acc16200NV,
//                                'debit_partner_id'   => $debetPartnerId,
//                                'debit_currency_id'  => 1,
//
//                                'credit_account_id'  => $acc10210,
//                                'credit_currency_id' => 1,
//                                'credit_partner_id'  => $creditPartnerId,
//
//                                'amount_amd'         => $contract->provided_amount,
//
//                                'comment'            => 'contract_payment',
//                                'user_id'            => auth()->id(),
//                                'is_system'          => false,
//
//                                'disbursement_date'    =>  $contract->date,
//                                'transactionable_type' => DocumentJournal::class,
//                                'transactionable_id'   => $journalDoc->id,
//                            ]);


                            Log::info("Client #{$client->id} updated to classification {$classification->name} ({$maxOverdue} days overdue)");
                        }

                    } catch (\Throwable $e) {
                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
                    }
                }
            });

        Log::info('Client classification job finished.');
    }
}
