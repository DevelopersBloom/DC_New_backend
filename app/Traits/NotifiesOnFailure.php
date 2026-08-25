<?php

namespace App\Traits;

use App\Models\User;
use App\Notifications\JobFailedNotification;
use App\Notifications\JobSucceededNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
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

        if ($chatId = config('services.telegram.chat_id')) {
            Notification::route('telegram', $chatId)->notify($notification);
        }
    }

    public function notifySuccess(string $summary = ''): void
    {
        $notification = new JobSucceededNotification(
            jobClass: class_basename(static::class),
            summary: $summary,
            finishedAt: Carbon::now()->toDateTimeString(),
        );

        if ($chatId = config('services.telegram.chat_id')) {
            Notification::route('telegram', $chatId)->notify($notification);
        }
    }
}
