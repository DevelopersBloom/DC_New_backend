<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientPawnshop;
use Carbon\Carbon;

class ClientService
{
    /**
     * Store or Update a client
     * @param array $data
     * @return mixed
     */
    public function storeOrUpdate(array $data): Client
    {
        $type = $data['type'] ?? 'individual';

        if ($type === 'individual' && !empty($data['passport_series'])) {
            $client = Client::firstOrNew([
                'type' => 'individual',
                'passport_series' => $data['passport_series'],
            ]);
        } elseif ($type === 'legal') {
            if (!empty($data['tax_number'])) {
                $client = Client::firstOrNew([
                    'type' => 'legal',
                    'tax_number' => $data['tax_number'],
                ]);
            } else {
                $client = Client::firstOrNew([
                    'type' => 'legal',
                    'company_name' => $data['company_name'] ?? null,
                ]);
            }
        } else {
            $client = new Client();
        }

        $client->fill($data);
        $client->save();

        return $client;
    }

    public function storeOrUpdate1(array $data): Client
    {
        // Format phone numbers
        $data['phone'] = $this->formatPhoneNumber($data['phone'] ?? '');
        $data['additional_phone'] = isset($data['additional_phone'])
            ? $this->formatPhoneNumber($data['additional_phone'])
            : null;
        $client = Client::updateOrCreate(
            [
                'passport_series' => $data['passport_series'],
            ],
            [
                'passport_issued' => $data['passport_issued'],
                'name' => $data['name'],
                'surname' => $data['surname'],
                'middle_name' => $data['middle_name'] ?? null,
                'passport_validity' => $data['passport_validity'] ?? null,
                'date_of_birth' => $data['date_of_birth'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'additional_phone' => $data['additional_phone'],
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

    private function formatPhoneNumber(string $phone): string
    {
        // Remove non-numeric characters except '+'
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Match and format Armenian number format
        if (preg_match('/^\+374(\d{2})(\d{2})(\d{2})(\d{2})$/', $cleaned, $matches)) {
            return "(+374) {$matches[1]} {$matches[2]} {$matches[3]} {$matches[4]}";
        }

        return $phone; // fallback if it doesn't match
    }

    public function getClientInfo(int $clientId, string $contractStatus = 'initial')
    {
        $validStatuses = ['initial', 'executed', 'completed'];

        $client = Client::with([
            'contracts' => function ($query) use ($contractStatus, $validStatuses) {
                if (in_array($contractStatus, $validStatuses)) {
                    $query->where('status', $contractStatus);
                } else {
                    $query->where('status', 'initial');
                }

                $query->select(
                    'id',
                    'num',
                    'client_id',
                    'estimated_amount',
                    'provided_amount',
                    'interest_rate',
                    'penalty',
                    'category_id',
                    'deadline',
                    'status'
                )->with('category:id,name');
            }
        ])->findOrFail($clientId, [
            'id',
            'name',
            'surname',
            'middle_name',
            'passport_series',
            'passport_validity',
            'passport_issued',
            'email',
            'phone',
            'country',
            'city',
            'street',
            'phone',
            'additional_phone',
            'date_of_birth',
            'social_card_number',
            'bank_client_id'
        ]);

        // Format date_of_birth after retrieval
        $client->date_of_birth = Carbon::parse($client->date_of_birth)->format('d-m-Y');

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
                        ->orWhere('surname', 'like', '%' . $firstInput . '%')
                        ->orWhere('company_name','like','%'  . $firstInput . '%');
                });
            }

            if ($secondInput) {
                $query->orWhere(function ($subQuery) use ($secondInput) {
                    $subQuery->where('name', 'like', '%' . $secondInput . '%')
                        ->orWhere('surname', 'like', '%' . $secondInput . '%')
                        ->orWhere('company_name','like', '%' . $secondInput . '%');
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

        $client->name = $data['name'] ?? $client->name;
        $client->surname = $data['surname'] ?? $client->surname;
        $client->middle_name = $data['middle_name'] ?? $client->middle_name;
        $client->passport_series = $data['passport_series'] ?? $client->passport_series;
        $client->passport_validity = $data['passport_validity'] ?? $client->passport_validity;
        $client->passport_issued = $data['passport_issued'] ?? $client->passport_issued;
        $client->country = $data['country'] ?? $client->country;
        $client->city = $data['city'] ?? $client->city;
        $client->street = $data['street'] ?? $client->street;
        $client->building = $data['building'] ?? $client->building;
        $client->email = $data['email'] ?? $client->email;
        $client->phone = isset($data['phone'])
            ? $this->formatPhoneNumber($data['phone'])
            : $client->phone;

        $client->additional_phone = isset($data['additional_phone'])
            ? $this->formatPhoneNumber($data['additional_phone'])
            : $client->additional_phone;

        $client->save();

        return $client;
    }

}
