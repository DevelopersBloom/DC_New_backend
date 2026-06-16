<?php

namespace App\Jobs;

use App\Models\Prepayment;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\NotifiesOnFailure;

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

    public function handle(): void
    {
        Log::info("MarkDuePrepaymentsPaid started for {$this->forDate}");

        $unpaid = Prepayment::where('status', 'unpaid')
            ->whereDate('due_date', '<=', $this->forDate)
            ->get();

        if ($unpaid->isEmpty()) {
            Log::info("MarkDuePrepaymentsPaid: no due unpaid prepayments for {$this->forDate}");
            return;
        }

        Log::info("MarkDuePrepaymentsPaid: found {$unpaid->count()} prepayments to mark paid");

        DB::beginTransaction();
        try {
            Prepayment::where('status', 'unpaid')
                ->whereDate('due_date', '<=', $this->forDate)
                ->update([
                    'status'  => 'paid',
                    'paid_at' => Carbon::parse($this->forDate)->startOfDay(),
                ]);

            DB::commit();
            Log::info("MarkDuePrepaymentsPaid: successfully marked {$unpaid->count()} prepayments as paid");
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("MarkDuePrepaymentsPaid failed: " . $e->getMessage());
            $this->notifyAdmins($e);
            throw $e;
        }
    }
}
