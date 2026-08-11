<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Throwable;

class JobFailedNotification extends Notification
{
    public function __construct(
        private string $jobClass,
        private string $errorMessage,
        private string $failedAt,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable
            ? [TelegramChannel::class]
            : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'job' => $this->jobClass,
            'error' => $this->errorMessage,
            'failed_at' => $this->failedAt,
        ];
    }

    public function toTelegram(object $notifiable): string
    {
        return "🚨 <b>Job Failed</b>\n"
            ."Job: {$this->jobClass}\n"
            ."Error: {$this->errorMessage}\n"
            ."At: {$this->failedAt}";
    }
}
