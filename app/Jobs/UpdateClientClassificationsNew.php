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
use App\Traits\NotifiesOnFailure;

class UpdateClientClassificationsNew implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use NotifiesOnFailure;
    public int $timeout = 1800;

    public function __construct()
    {
    }

    public function handle(ClientClassificationService $service): void
    {
        Log::info('Client classification job started...');

        Client::whereHas('contracts', function (Builder $q) {
            $q->where('status', 'initial');
        })->with(['contracts' => function ($q) {
            $q->where('status', 'initial');
        }, 'classification'])
            ->chunkById(200, function ($clients) use ($service) {

                foreach ($clients as $client) {

                    try {
                        Log::info('Processing client', ['id' => $client->id]);

                        $maxOverdue = $service->maxOverdueDaysForClient($client);
                        $classification = $service->classificationByOverdue($maxOverdue);

                        Log::info("calculated classification is {$classification->id} ");
                        Log::info("calculated maxOverdue is {$maxOverdue} ");

                        $service->applyClassificationIfWorse(
                            $client,
                            $classification,
                            'Automatic client classification update based on overdue days',
                        );
                    } catch (\Throwable $e) {
                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
                        $this->notifyAdmins($e);
                    }
                }
            });

        Log::info('Client classification job finished.');
    }
}
