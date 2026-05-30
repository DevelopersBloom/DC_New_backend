<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class MissingClassificationHistoryNotification extends Notification
{
    public function __construct(
        private int    $clientId,
        private string $clientName,
        private string $contractNum,
        private string $calcDate,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'missing_classification_history',
            'client_id'     => $this->clientId,
            'client_name'   => $this->clientName,
            'contract_num'  => $this->contractNum,
            'calc_date'     => $this->calcDate,
            'message'       => "Կլիենտ «{$this->clientName}» (ID: {$this->clientId}), պայմ. №{$this->contractNum} — {$this->calcDate} ամ/թ-ի դրությամբ դասակարգման պատմություն չկա։",
        ];
    }
}
