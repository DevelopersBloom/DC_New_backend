<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

class JobSucceededNotification extends Notification
{
    public function __construct(
        private string $jobClass,
        private string $summary,
        private string $finishedAt,
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
            'summary' => $this->summary,
            'finished_at' => $this->finishedAt,
        ];
    }

    public function toTelegram(object $notifiable): string
    {
        $summary = $this->summary !== '' ? "\n{$this->summary}" : '';

        return "✅ <b>Job Succeeded</b>\n"
            ."Job: {$this->jobClass}\n"
            ."At: {$this->finishedAt}"
            .$summary;
    }
}
