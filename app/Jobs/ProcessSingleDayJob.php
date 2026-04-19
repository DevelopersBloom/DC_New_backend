<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Services\ContractDailyRateService;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessSingleDayJob implements ShouldQueue
{
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
