<?php

namespace App\Jobs;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use App\Services\ContractDailyRateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Traits\NotifiesOnFailure;
use Carbon\Carbon;

class RecalculateContractRangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use NotifiesOnFailure;

    public int $tries = 3;
    public int $backoff = 30;
    public  $afterCommit = true;

    public function __construct(
        public int $contractId,
        public string $from,
        public string $to
    ) {}

    public function handle(ContractDailyRateService $service): void
    {
        $dayCount = Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) + 1;
        $lockTtl  = max(120, $dayCount * 5);

        Log::info('RecalculateContractRangeJob start', [
            'contract_id' => $this->contractId,
            'from'        => $this->from,
            'to'          => $this->to,
        ]);

        Cache::lock("recalc_{$this->contractId}", $lockTtl)->block(10, function () use ($service) {

            $contract = Contract::with('client.classification')->find($this->contractId);

            if (!$contract) {
                $this->notifyAdmins(new \RuntimeException("Contract #{$this->contractId} not found."));
                return;
            }

            DB::transaction(function () use ($service, $contract) {

                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $this->contractId)
                    ->first();

                if (!$journal) {
                    throw new \RuntimeException("DocumentJournal not found for contract #{$this->contractId}.");
                }

                $docIds = DocumentJournal::where('journalable_id', $journal->id)
                    ->where('journalable_type', DocumentJournal::class)
                    ->whereBetween('date', [$this->from, $this->to])
                    ->whereIn('document_type', [
                        DocumentJournal::EFFECTIVE_RATE_AMOUNT,
                        DocumentJournal::INTEREST_RATE_AMOUNT,
                        DocumentJournal::PENALTY_RATE_AMOUNT,
                        DocumentJournal::RESERVE_GENERAL_AMOUNT,
                        DocumentJournal::RESERVE_SPECIAL_AMOUNT,
                    ])
                    ->pluck('id');

                Transaction::where('transactionable_type', DocumentJournal::class)
                    ->whereIn('transactionable_id', $docIds)
                    ->delete();

                DocumentJournal::whereIn('id', $docIds)->delete();

                $current      = Carbon::parse($this->from);
                $end          = Carbon::parse($this->to);
                $daysRecreated = 0;

                while ($current->lte($end)) {
                    $service->processDay($contract, $current->toDateString());
                    $current->addDay();
                    $daysRecreated++;
                }

                Log::info('RecalculateContractRangeJob done', [
                    'contract_id'   => $this->contractId,
                    'deleted_count' => $docIds->count(),
                    'days_recreated' => $daysRecreated,
                ]);
            });
        });
    }
}
