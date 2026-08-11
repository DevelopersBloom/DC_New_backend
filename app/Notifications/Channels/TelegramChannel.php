<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $token = config('services.telegram.bot_token');

        if (! $token || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $chatId = $notifiable->routeNotificationFor('telegram', $notification)
            ?? config('services.telegram.chat_id');

        if (! $chatId) {
            return;
        }

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $notification->toTelegram($notifiable),
            'parse_mode' => 'HTML',
        ]);

        if ($response->failed()) {
            Log::warning('Failed to send Telegram notification', [
                'response' => $response->body(),
            ]);
        }
    }
}
