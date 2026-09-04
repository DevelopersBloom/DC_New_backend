<?php

namespace App\Traits;

use App\Models\Client;
use App\Models\Deal;

trait ResolvesPayerClient
{
    /**
     * The payer client id already recorded on a deal, if any.
     */
    public function payerClientIdForDeal($dealId): ?int
    {
        return $dealId ? Deal::whereKey($dealId)->value('payer_client_id') : null;
    }

    /**
     * Resolve an "another payer" ({name, surname, phone}) to a Client id: match an
     * existing individual by name + surname + phone, otherwise create a new client.
     * Returns null when the payer data is missing or incomplete.
     */
    public function resolvePayerClientId($payer): ?int
    {
        if (!is_array($payer)) {
            return null;
        }

        $name    = trim((string) ($payer['name'] ?? ''));
        $surname = trim((string) ($payer['surname'] ?? ''));
        $phone   = trim((string) ($payer['phone'] ?? ''));

        if ($name === '' || $surname === '' || $phone === '') {
            return null;
        }

        $client = Client::where('type', 'individual')
            ->where('name', $name)
            ->where('surname', $surname)
            ->where('phone', $phone)
            ->first();

        if (!$client) {
            $client = Client::create([
                'type'    => 'individual',
                'name'    => $name,
                'surname' => $surname,
                'phone'   => $phone,
            ]);
        }

        return $client->id;
    }
}
