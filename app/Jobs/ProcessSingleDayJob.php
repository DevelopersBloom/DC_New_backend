<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Services\ContractDailyRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSingleDayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(
        public int $contractId,
        public string $date
    ) {}

    public function handle(ContractDailyRateService $service)
    {
        $contract = Contract::with('client.classification')->find($this->contractId);
        if (!$contract) return;

        $service->processDay($contract, $this->date);
    }
}
