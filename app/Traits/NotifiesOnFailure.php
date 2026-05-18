<?php

namespace App\Traits;

use App\Models\User;
use App\Notifications\JobFailedNotification;
use Carbon\Carbon;
use Throwable;

trait NotifiesOnFailure
{
    public function failed(Throwable $exception): void
    {
        $this->notifyAdmins($exception);
    }

    public function notifyAdmins(Throwable $exception): void
    {
        $notification = new JobFailedNotification(
            jobClass: class_basename(static::class),
            errorMessage: $exception->getMessage(),
            failedAt: Carbon::now()->toDateTimeString(),
        );

        User::role('admin')->get()->each->notify($notification);
    }
}
