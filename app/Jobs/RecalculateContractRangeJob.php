<?php

namespace App\Jobs;
use App\Models\ClassificationHistory;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractReserveHistory;
use App\Models\DocumentJournal;
use App\Models\Modification;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use App\Services\ContractDailyRateService;
use App\Traits\CorrectReserveTrait;
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
    use CorrectReserveTrait;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $contractId,
        public string $from,
        public string $to
    ) {
        $this->afterCommit = true;
    }

    public function handle(ContractDailyRateService $service, ClientClassificationService $classificationService): void
    {
        $dayCount = Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) + 1;
        $lockTtl  = max(120, $dayCount * 5);

        Log::info('RecalculateContractRangeJob start', [
            'contract_id' => $this->contractId,
            'from'        => $this->from,
            'to'          => $this->to,
        ]);

        Cache::lock("recalc_{$this->contractId}", $lockTtl)->block(10, function () use ($service, $classificationService) {

            $contract = Contract::with('client.classification')->find($this->contractId);

            if (!$contract) {
                $this->notifyAdmins(new \RuntimeException("Contract #{$this->contractId} not found."));
                return;
            }

            DB::transaction(function () use ($service, $classificationService, $contract) {

                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $this->contractId)
                    ->first();

                if (!$journal) {
                    throw new \RuntimeException("DocumentJournal not found for contract #{$this->contractId}.");
                }

                // Must run before re-accrual: processDay() reads the classification
                // as of each date from classification_histories.
                $this->rollbackStaleClassificationChanges($contract, $classificationService);

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
                    // Daily accruals always carry contract_id; client-level reserve
                    // corrections (postCorrectionEntry) don't and must survive.
                    ->whereNotNull('contract_id')
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

                // Re-accrual and the back-dated payment both moved net: bring the
                // client's reserve to the rule target as of today.
                $this->syncClientReserveToTarget($contract->client_id, now()->toDateString(), $contract->id);

                Log::info('RecalculateContractRangeJob done', [
                    'contract_id'   => $this->contractId,
                    'deleted_count' => $docIds->count(),
                    'days_recreated' => $daysRecreated,
                ]);
            });
        });
    }

    /**
     * A back-dated payment can clear the overdue that an automatic classification
     * change on/after $from was based on (e.g. payment dated 22 Sep entered after
     * the 23 Sep 00:00 job). In that case the change never should have happened:
     * remove its documents, history rows and RISK modification, restore the
     * previous classification, and let the normal path re-apply whatever the
     * overdue days give today.
     *
     * Manual changes and loss write-offs are never undone here.
     */
    private function rollbackStaleClassificationChanges(Contract $contract, ClientClassificationService $classificationService): void
    {
        $client = Client::with('classification')->find($contract->client_id);
        if (!$client || !$client->classification) {
            return;
        }

        $histories = ClassificationHistory::where('client_id', $client->id)
            ->where('date', '>=', Carbon::parse($this->from)->startOfDay())
            ->with('classification')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        if ($histories->isEmpty()
            || $histories->contains(fn ($h) => $h->comment !== ClientClassificationService::AUTO_COMMENT)
            || $histories->contains(fn ($h) => $h->classification?->name === 'loss')) {
            return;
        }

        $computed = $classificationService->classificationByOverdue(
            $classificationService->maxOverdueDaysForClient($client)
        );

        // The overdue still justifies the current classification — keep it.
        if ($computed->order >= $client->classification->order) {
            return;
        }

        $restoreId = $histories->first()->meta['old_classification_id'] ?? null;
        if (!$restoreId) {
            return;
        }

        $journalIds = DocumentJournal::where('journalable_type', Contract::class)
            ->whereIn('journalable_id', Contract::where('client_id', $client->id)->pluck('id'))
            ->pluck('id');

        $docIds = DocumentJournal::where('journalable_type', DocumentJournal::class)
            ->whereIn('journalable_id', $journalIds)
            ->where('date', '>=', $this->from)
            ->where(function ($q) {
                $q->where('document_type', DocumentJournal::CLASSIFICATION)
                    ->orWhere('comment', 'like', 'Reserve for contract #% due to classification change%');
            })
            ->pluck('id')
            ->merge($histories->flatMap(fn ($h) => $h->meta['reserve_doc_ids'] ?? []))
            ->unique()
            ->values();

        Transaction::where('transactionable_type', DocumentJournal::class)
            ->whereIn('transactionable_id', $docIds)
            ->delete();
        DocumentJournal::whereIn('id', $docIds)->delete();

        ContractReserveHistory::where('client_id', $client->id)
            ->where('date', '>=', $this->from)
            ->delete();

        $riskModifications = Modification::where('subject_type', Client::class)
            ->where('subject_id', $client->id)
            ->where('field_code', 'RISK')
            ->where('effective_date', '>=', $this->from)
            ->get();
        foreach ($riskModifications as $modification) {
            if ($modification->is_sent) {
                Log::warning("RISK modification #{$modification->id} for client #{$client->id} was already sent to the registry; not deleted.");
                continue;
            }
            $modification->delete();
        }

        ClassificationHistory::whereIn('id', $histories->pluck('id'))->delete();

        $client->classification_id = $restoreId;
        $client->save();

        Log::info("Rolled back automatic classification change(s) for client #{$client->id} since {$this->from}", [
            'history_ids'             => $histories->pluck('id')->all(),
            'document_ids'            => $docIds->all(),
            'restored_classification' => $restoreId,
            'computed_classification' => $computed->id,
        ]);

        if ($computed->id !== (int) $restoreId) {
            $classificationService->applyClassificationIfWorse(
                $client->fresh(),
                $computed,
                ClientClassificationService::AUTO_COMMENT,
            );
        }
    }
}
