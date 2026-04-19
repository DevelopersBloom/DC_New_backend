<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecalculateContractRangeJob implements ShouldQueue
{
    public function __construct(
        public int $contractId,
        public string $from,
        public string $to
    ) {}

    public function handle()
    {
        Cache::lock("recalc_{$this->contractId}", 60)->block(10, function () {

            DB::transaction(function () {

                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $this->contractId)
                    ->first();

                if (!$journal) return;

                $docIds = DocumentJournal::where('journalable_id', $journal->id)
                    ->whereBetween('date', [$this->from, $this->to])
                    ->whereIn('document_type',[DocumentJournal::EFFECTIVE_RATE_AMOUNT,DocumentJournal::INTEREST_RATE_AMOUNT,DocumentJournal::PENALTY_RATE_AMOUNT])
                    ->where('is_system',true)
                    ->pluck('id');

                Transaction::whereIn('transactionable_id', $docIds)->delete();
                DocumentJournal::whereIn('id', $docIds)->delete();

                $date = \Carbon\Carbon::parse($this->from);

                while ($date <= $this->to) {
                    ProcessSingleDayJob::dispatch(
                        $this->contractId,
                        $date->toDateString()
                    );
                    $date->addDay();
                }
            });
        });
    }
}
