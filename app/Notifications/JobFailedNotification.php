<?php

namespace App\Notifications;

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
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'job' => $this->jobClass,
            'error' => $this->errorMessage,
            'failed_at' => $this->failedAt,
        ];
    }
}
