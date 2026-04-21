<?php

namespace App\Console;

use App\Jobs\ProcessContractDailyRate;
use App\Jobs\ProcessDailyBankProvision;
use App\Jobs\ProcessDailyNdmInterest;
use App\Jobs\UpdateClientClassifications;
use App\Jobs\UpdateClientClassificationsOld;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->job(new UpdateClientClassifications)
            ->dailyAt('00:00')
            ->timezone('Asia/Yerevan')
            ->withoutOverlapping(10)
            ->appendOutputTo(storage_path('logs/schedule.log'));

        $schedule->job(new ProcessContractDailyRate)
            ->dailyAt('00:05')
            ->timezone('Asia/Yerevan')
            ->withoutOverlapping(10)
            ->appendOutputTo(storage_path('logs/schedule.log'));

        $schedule->job(new ProcessDailyNdmInterest)
            ->dailyAt('00:10')
            ->timezone('Asia/Yerevan')
            ->withoutOverlapping(10)
            ->appendOutputTo(storage_path('logs/schedule.log'));

//        $schedule->job(new ProcessDailyBankProvision)
//            ->dailyAt('23:46')
//            ->timezone('Asia/Yerevan')
//            ->withoutOverlapping(10)
//            ->appendOutputTo(storage_path('logs/laravel.log'));
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
