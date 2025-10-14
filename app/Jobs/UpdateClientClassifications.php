<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\ClientClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateClientClassifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct() {}

    public function handle(ClientClassificationService $service): void
    {
        Log::info('Client classification job started...');

        Client::whereHas('contracts', function (Builder $q) {
            $q->where('status', 'initial');
        })
            ->with('contracts.payments')
            ->chunkById(200, function ($clients) use ($service) {
                foreach ($clients as $client) {
                    try {
                        $maxOverdue = $service->maxOverdueDaysForClient($client);

                        $classification = $service->classificationByOverdue($maxOverdue);

                        if ($client->classification_id !== $classification->id) {
                            $client->classification_id = $classification->id;
                            $client->save();

                            Log::info("Client #{$client->id} updated to classification {$classification->name} ({$maxOverdue} days overdue)");
                        }

                    } catch (\Throwable $e) {
                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
                    }
                }
            });

        Log::info('Client classification job finished.');
    }
}
