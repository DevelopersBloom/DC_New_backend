<?php

namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\ClassificationHistory;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DocumentJournal;
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

        $processed = 0;
        $failed    = [];

        Client::with(['classification'])
            ->whereHas('contracts', fn($q) => $q->whereIn('status', ['initial', 'completed']))
            ->whereHas('classification')
            ->chunkById(200, function ($clients) use (
                $acc16605PC, $acc16605PS, $targetAccountIds,
                $date, $dateStr, &$processed, &$failed
            ) {
                foreach ($clients as $client) {

                    if (!$client->classification) {
                        continue;
                    }
                    $clientClassification = ClassificationHistory::where('client_id',$client->id)
                        ->where('date', '<=', $date)
                        ->orderBy('date','desc')
                        ->first();

                    if (!$clientClassification) {
                        Log::error("Client {$client->id} classification does not exist for {$dateStr}");
                        continue;
                    }

                    $hasActiveContracts = $client->contracts()
                        ->where('status', 'initial')
                        ->exists();

                    $contractIds = $client->contracts()
                        ->when($hasActiveContracts, fn($q) => $q->where('status', 'initial'))
                        ->orderBy('id')
                        ->pluck('id');

                    if ($contractIds->isEmpty()) {
                        continue;
                    }

                    $journalId = DocumentJournal::where('journalable_type', Contract::class)
                        ->whereIn('journalable_id', $contractIds)
                        ->orderBy('journalable_id')
                        ->value('id');

                    if (!$journalId) {
                        Log::warning("Client {$client->id} reserve skipped for {$dateStr}: no DocumentJournal for contracts", [
                            'contract_ids' => $contractIds->all(),
                        ]);
                        continue;
                    }

                    DB::beginTransaction();

                    try {
                        if (!$hasActiveContracts) {
                            $this->zeroClientReserveBalances(
                                clientId:    $client->id,
                                acc16605PC:  $acc16605PC,
                                acc16605PS:  $acc16605PS,
                                journalId:   $journalId,
                                date:        $dateStr,
                            );
                        } else {
                            $this->correctClientReserveBalance(
                                clientId:           $client->id,
                                acc16605PC:         $acc16605PC,
                                acc16605PS:         $acc16605PS,
                                targetAccountIds:   $targetAccountIds,
                                reservePercent:     $clientClassification->reserve_percent,
                                classificationName: $clientClassification->classification->name,
                                journalId:          $journalId,
                                now:                $dateStr,
                            );
                        }

                        DB::commit();
                        $processed++;

                    } catch (\Throwable $e) {
                        DB::rollBack();

                        Log::error("Client {$client->id} failed for {$dateStr}: " . $e->getMessage());
                        $this->notifyAdmins($e);

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
