<?php

namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\ClassificationHistory;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use App\Traits\CorrectReserveTrait;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CorrectAllClientReservesJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;
    use CorrectReserveTrait;

    private string $forDate;

    public function __construct(?string $forDate = null)
    {
        $this->forDate = $forDate ?? now()->format('Y-m-d');
    }

    public function handle(): void
    {
        $date      = Carbon::parse($this->forDate)->endOfDay();
        $dateStr   = $date->format('Y-m-d');

        Log::info("Client reserve started for {$dateStr}");

        $acc16605PC = ChartOfAccount::idByCode('16605PC');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');

        $targetAccountIds = array_filter([
            ChartOfAccount::idByCode('16200NV'),
            ChartOfAccount::idByCode('16201NI'),
            ChartOfAccount::idByCode('16200'),
        ]);

        $diamondId  = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;

        $processed = 0;
        $failed    = [];
        $nextDocNum = (int)(Transaction::max('document_number') ?? 0);

        Client::with(['classification'])
            ->whereHas('contracts', fn($q) => $q->where('status', 'initial'))
            ->whereHas('classification')
            ->chunkById(200, function ($clients) use (
                $acc16605PC, $acc16605PS, $targetAccountIds,
                $diamondId, $date, $dateStr, &$processed, &$failed, &$nextDocNum
            ) {
                foreach ($clients as $client) {

                    if (!$client->classification) {
                        continue;
                    }
                    $clientClassification = ClassificationHistory::where('client_id',$client->id)
                        ->where('date', '<=', $date)
                        ->orderBy('date','desc')
                        ->first();

                    $firstContract = $client->contracts()
                        ->where('status', 'initial')
                        ->first();

                    $journal = $firstContract
                        ? DocumentJournal::where('journalable_type', Contract::class)
                            ->where('journalable_id', $firstContract->id)
                            ->first()
                        : null;

                    if (!$journal) {
                        continue;
                    }

                    DB::beginTransaction();

                    try {
                        $nextDocNum++;

                        $this->correctClientReserveBalance(
                            clientId:           $client->id,
                            acc16605PC:         $acc16605PC,
                            acc16605PS:         $acc16605PS,
                            targetAccountIds:   $targetAccountIds,
                            reservePercent:     $clientClassification->reserve_percent,
                            classificationName: $clientClassification->classification->name,
                            diamondId:          $diamondId,
                            nextDocNum:         $nextDocNum,
                            journalId:          $journal->id,
                            now:                $dateStr,
                        );

                        DB::commit();
                        $processed++;

                    } catch (\Throwable $e) {
                        DB::rollBack();

                        Log::error("Client {$client->id} failed for {$dateStr}: " . $e->getMessage());

                        $failed[] = [
                            'client_id' => $client->id,
                            'date'      => $dateStr,
                            'error'     => $e->getMessage(),
                        ];
                    }
                }
            });

        Log::info("Client reserve finished for {$dateStr}");
    }
}
