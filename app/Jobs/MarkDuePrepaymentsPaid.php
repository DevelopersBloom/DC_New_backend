<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\Prepayment;
use App\Services\Payments\PrepaymentApplicationService;
use App\Traits\NotifiesOnFailure;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * B3: for every contract with a Prepayment bucket entry due today (or earlier),
 * applies it via PrepaymentApplicationService — one DB transaction with row locks
 * per contract, so a failure on one contract doesn't affect the others. Prepayments
 * are only picked up while status='unpaid', so re-running the job is a no-op for
 * anything already applied (idempotent).
 */
class MarkDuePrepaymentsPaid implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use NotifiesOnFailure;

    public $tries = 3;

    private string $forDate;

    public function __construct(?string $forDate = null)
    {
        $this->forDate = $forDate ?? Carbon::today()->toDateString();
    }

    public function handle(PrepaymentApplicationService $applicationService): void
    {
        Log::info("MarkDuePrepaymentsPaid started for {$this->forDate}");

        $contractIds = Prepayment::where('status', 'unpaid')
            ->whereDate('due_date', '<=', $this->forDate)
            ->distinct()
            ->pluck('contract_id');

        if ($contractIds->isEmpty()) {
            Log::info("MarkDuePrepaymentsPaid: no due unpaid prepayments for {$this->forDate}");
            $this->notifySuccess("No due unpaid prepayments for {$this->forDate}.");
            return;
        }

        Log::info("MarkDuePrepaymentsPaid: found {$contractIds->count()} contract(s) with due prepayments");

        $errors = [];

        foreach ($contractIds as $contractId) {
            try {
                DB::transaction(function () use ($contractId, $applicationService) {
                    $contract = Contract::where('id', $contractId)->lockForUpdate()->first();
                    if (!$contract) {
                        return;
                    }

                    $duePrepayments = Prepayment::where('contract_id', $contractId)
                        ->where('status', 'unpaid')
                        ->whereDate('due_date', '<=', $this->forDate)
                        ->orderBy('due_date')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($duePrepayments as $prepayment) {
                        $applicationService->apply($contract, $prepayment, $this->forDate);
                    }
                });
            } catch (\Throwable $e) {
                Log::error("MarkDuePrepaymentsPaid: failed for contract #{$contractId}: " . $e->getMessage());
                $errors[] = "Contract #{$contractId}: " . $e->getMessage();
            }
        }

        Log::info("MarkDuePrepaymentsPaid: finished for {$this->forDate}");

        if (empty($errors)) {
            $this->notifySuccess("{$contractIds->count()} contract(s) with due prepayments processed successfully for {$this->forDate}.");
        } else {
            $this->notifyAdmins(new \RuntimeException(
                count($errors) . " of {$contractIds->count()} contract(s) failed:\n" . implode("\n", $errors)
            ));
        }
    }
}
