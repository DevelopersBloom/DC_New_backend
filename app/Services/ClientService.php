<?php

namespace App\Services;

use App\Models\Client;

class ClientService
{
    /**
     * Store or Update a client
     * @param array $data
     * @return mixed
     */

    public function storeOrUpdate(array $data): Client
    {
        $client = Client::where('passport_series', $data['passport_series'])
            ->where('passport_validity', $data['passport_validity'])
            ->first();
        if ($client) {
            $client->name = $data['name'];
            $client->surname = $data['surname'];
            $client->middle_name = $data['middle_name'] ?? null;
            $client->passport_issued = $data['passport_issued'];
            $client->date_of_birth = $data['date_of_birth'];
            $client->email = $data['email'];
            $client->phone = $data['phone'];
            $client->additional_phone = $data['additional_phone'] ?? null;
            $client->country = $data['country'];
            $client->city = $data['city'];
            $client->street = $data['street'];
            $client->building = $data['building'] ?? null;
            $client->bank_name = $data['bank_name'] ?? null;
            $client->account_number = $data['account_number'] ?? null;
            $client->card_number = $data['card_number'] ?? null;
            $client->iban = $data['iban'] ?? null;
            $client->save();
        } else {
            $client = new Client();
            $client->name = $data['name'];
            $client->surname = $data['surname'];
            $client->middle_name = $data['middle_name'] ?? null;
            $client->passport_series = $data['passport_series'];
            $client->passport_validity = $data['passport_validity'];
            $client->passport_issued = $data['passport_issued'];
            $client->date_of_birth = $data['date_of_birth'];
            $client->email = $data['email'];
            $client->phone = $data['phone'];
            $client->additional_phone = $data['additional_phone'] ?? null;
            $client->country = $data['country'];
            $client->city = $data['city'];
            $client->street = $data['street'];
            $client->building = $data['building'] ?? null;
            $client->bank_name = $data['bank_name'] ?? null;
            $client->account_number = $data['account_number'] ?? null;
            $client->card_number = $data['card_number'] ?? null;
            $client->iban = $data['iban'] ?? null;
            $client->save();
        }
        return $client;
    }

    /**
     * @param string|null $firstInput
     * @param string|null $secondInput
     * @return mixed
     */
    public function search(?string $firstInput, ?string $secondInput)
    {
        return Client::where(function ($query) use ($firstInput, $secondInput) {
            $query->where('name', 'like', '%' . ($firstInput ?? '') . '%')
                ->Where('surname', 'like', '%' . ($firstInput ?? '') . '%')
                ->orWhere('name', 'like', '%' . ($secondInput ?? '') . '%')
                ->orWhere('surname', 'like', '%' . ($secondInput ?? '') . '%');
        })->get();
    }
}
