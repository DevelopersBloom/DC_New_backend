<?php

namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use App\Traits\CorrectReserveTrait;
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
    public function handle(): void
    {
        $acc16605PC = ChartOfAccount::idByCode('16605PC');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');

        $targetAccountIds = array_filter([
            ChartOfAccount::idByCode('16200NV'),
            ChartOfAccount::idByCode('16201NI'),
            ChartOfAccount::idByCode('16200'),
        ]);

        $diamondId  = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;
        $now        = now()->format('Y-m-d');

        $processed = 0;
        $failed    = [];
        $nextDocNum = (int)(Transaction::max('document_number') ?? 0);

        Client::with(['classification'])
            ->whereHas('contracts', fn($q) => $q->where('status', 'initial'))
            ->whereHas('classification')
            ->chunkById(200, function ($clients) use (
                $acc16605PC, $acc16605PS, $targetAccountIds,
                $diamondId, $now, &$processed, &$failed, &$nextDocNum
            ) {
                foreach ($clients as $client) {
                    $nextDocNum++;

                    if (!$client->classification) {
                        continue;
                    }

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
                        $this->correctClientReserveBalance(
                            clientId:           $client->id,
                            acc16605PC:         $acc16605PC,
                            acc16605PS:         $acc16605PS,
                            targetAccountIds:   $targetAccountIds,
                            reservePercent:     $client->classification->reserve_percent,
                            classificationName: $client->classification->name,
                            diamondId:          $diamondId,
                            nextDocNum:         $nextDocNum,
                            journalId:          $journal->id,
                            now:                $now,
                        );

                        DB::commit();
                        $processed++;

                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error("correctAllClientReserves failed for client #{$client->id}: " . $e->getMessage());
                        $failed[] = [
                            'client_id' => $client->id,
                            'error'     => $e->getMessage(),
                        ];
                    }
                }
            });

    }
}
