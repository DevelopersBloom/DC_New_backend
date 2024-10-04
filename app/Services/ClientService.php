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
            if ($firstInput) {
                $query->where(function ($subQuery) use ($firstInput) {
                    $subQuery->where('name', 'like', '%' . $firstInput . '%')
                        ->orWhere('surname', 'like', '%' . $firstInput . '%');
                });
            }

            if ($secondInput) {
                $query->orWhere(function ($subQuery) use ($secondInput) {
                    $subQuery->where('name', 'like', '%' . $secondInput . '%')
                        ->orWhere('surname', 'like', '%' . $secondInput . '%');
                });
            }
        })->get();
    }

    public function updateBankInfo(int $client_id,string $bank_name,string $card_number,?string $account_number, ?string $iban)
    {
        $client = Client::findOrFail($client_id);
        $client->bank_name = $bank_name;
        $client->card_number = $card_number;
        $client->account_number =$account_number;
        $client->iban = $iban;
        $client->save();

        return $client;
    }
}
