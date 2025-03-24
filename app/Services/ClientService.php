<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPawnshop;

class ClientService
{
    /**
     * Store or Update a client
     * @param array $data
     * @return mixed
     */
    public function storeOrUpdate(array $data): Client
    {
        $client =  Client::updateOrCreate(
            [
                'passport_series' => $data['passport_series'],
                'passport_issued' => $data['passport_issued']
            ],
            [
                'name' => $data['name'],
                'surname' => $data['surname'],
                'middle_name' => $data['middle_name'] ?? null,
                'passport_validity' => $data['passport_validity'] ?? null,
                'date_of_birth' => $data['date_of_birth'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'additional_phone' => $data['additional_phone'] ?? null,
                'country' => $data['country'],
                'city' => $data['city'],
                'street' => $data['street'],
                'building' => $data['building'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'card_number' => $data['card_number'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'iban' => $data['iban'] ?? null,
                'has_contract' => $data['has_contract'] ?? true,
                'date' => $data['date'] ?? now()->format('Y-m-d'),
            ]
        );
        return $client;


    }

//    public function storeOrUpdate(array $data): Client
//    {
//
//        $client = Client::where('passport_series', $data['passport_series'])
//            ->where('passport_issued', $data['passport_issued'])
//            ->first();
//        if ($client) {
//            $client->name = $data['name'];
//            $client->surname = $data['surname'];
//            $client->middle_name = $data['middle_name'] ?? null;
//            $client->passport_issued = $data['passport_issued'];
//            $client->date_of_birth = $data['date_of_birth'];
//            $client->email = $data['email'];
//            $client->phone = $data['phone'];
//            $client->additional_phone = $data['additional_phone'] ?? null;
//            $client->country = $data['country'];
//            $client->city = $data['city'];
//            $client->street = $data['street'];
//            $client->building = $data['building'] ?? null;
//            $client->bank_name = $data['bank_name'] ?? null;
//            $client->card_number = $data['card_number'] ?? null;
//            $client->account_number = $data['account_number'] ?? null;
//            $client->iban = $data['iban'] ?? null;
//            $client->has_contract = $data['has_contract'] ?? true;
//            $client->save();
//        } else {
//            $client = new Client();
//            $client->name = $data['name'];
//            $client->surname = $data['surname'];
//            $client->middle_name = $data['middle_name'] ?? null;
//            $client->passport_series = $data['passport_series'];
//            $client->passport_validity = $data['passport_validity'] ?? null;
//            $client->passport_issued = $data['passport_issued'];
//            $client->date_of_birth = $data['date_of_birth'];
//            $client->email = $data['email'];
//            $client->phone = $data['phone'];
//            $client->additional_phone = $data['additional_phone'] ?? null;
//            $client->country = $data['country'];
//            $client->city = $data['city'];
//            $client->street = $data['street'];
//            $client->building = $data['building'] ?? null;
//            $client->bank_name = $data['bank_name'] ?? null;
//            $client->card_number = $data['card_number'] ?? null;
//            $client->account_number = $data['account_number'] ?? null;
//            $client->iban = $data['iban'] ?? null;
//            $client->has_contract = $data['has_contract'] ?? null;
//
//            $client->save();
//        }
//        return $client;
//    }

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
    public function updateClientData(int $client_id, array $data)
    {
        $client = Client::findOrFail($client_id);

        $client->passport_series = $data['passport_series'] ?? $client->passport_series;
        $client->passport_validity = $data['passport_validity'] ?? $client->passport_validity;
        $client->passport_issued = $data['passport_issued'] ?? $client->passport_issued;
        $client->country = $data['country'] ?? $client->country;
        $client->city = $data['city'] ?? $client->city;
        $client->street = $data['street'] ?? $client->street;
        $client->building = $data['building'] ?? $client->building;
        $client->email = $data['email'] ?? $client->email;
        $client->phone = $data['phone'] ?? $client->phone;
        $client->additional_phone = $data['additional_phone'] ?? $client->additional_phone;

        $client->save();

        return $client;
    }

}
