<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Client;


class UpdateClientRequest extends FormRequest
{
    public function rules(): array
    {
        $clientId = (int) $this->route('id');
        $current  = Client::find($clientId);
        $effectiveType = $this->input('type', $current?->type ?? 'individual');

        $common = [
            'type' => ['sometimes', Rule::in(['individual','legal'])],
            'email' => ['nullable','email','max:255'],
            'phone' => ['nullable','string','max:20'],
            'additional_phone' => ['nullable','string','max:20'],
            'country' => ['nullable','string','max:255'],
            'city' => ['nullable','string','max:255'],
            'street' => ['nullable','string','max:255'],
            'building' => ['nullable','string','max:50'],
            'website' => ['nullable','string','max:255'],
            'bank_name' => ['nullable','string','max:255'],
            'account_number' => ['nullable','string','max:50'],
            'card_number' => ['nullable','string','max:50'],
            'iban' => ['nullable','string','max:50'],
            'swift_code' => ['nullable','string','max:50'],
            'has_contract' => ['sometimes','boolean'],
            'date' => ['nullable','date'],

            'gender' => 'nullable|string|in:MALE,FEMALE',
            'document_type' => 'nullable|string|in:NON_BIOMETRIC_PASSPORT,ID_CARD,BIOMETRIC_PASSPORT,BIRTH_CERTIFICATE,RESIDENCE_CARD,TRAVEL_DOCUMENT,FOREIGN_PASSPORT,OTHER',
            'is_married' => 'nullable|boolean',
            'legal_country' => 'nullable|string',
            'legal_province' => 'nullable|string',
            'legal_community' => 'nullable|string',
            'legal_settlement' => 'nullable|string',
            'legal_street_building' => 'nullable|string',
            'legal_zip_code' => 'nullable|string',
            'actual_country' => 'nullable|string',
            'actual_province' => 'nullable|string',
            'actual_community' => 'nullable|string',
            'actual_settlement' => 'nullable|string',
            'actual_street_building' => 'nullable|string',
            'actual_zip_code' => 'nullable|string',
        ];

        if ($effectiveType === 'legal') {
            $legal = [
                'company_name' => [
                    'nullable','string','max:255',
                    Rule::unique('clients','company_name')
                        ->ignore($clientId)
                        ->where(fn($q) => $q->where('type','legal'))
                ],
                'legal_form' => ['nullable','string','max:50'],
                'tax_number' => ['nullable','string','max:50'],
                'state_register_number' => ['nullable','string','max:50'],
                'activity_field' => ['nullable','string','max:255'],
                'director_name' => ['nullable','string','max:255'],
                'accountant_info' => ['nullable','string','max:255'],
                'internal_code' => ['nullable','string','max:50'],

                'name' => ['nullable','string','max:255'],
                'surname' => ['nullable','string','max:255'],
                'middle_name' => ['nullable','string','max:255'],
                'passport_series' => ['nullable','string','max:50'],
                'passport_validity' => ['nullable','date'],
                'passport_issued' => ['nullable','string','max:255'],
                'date_of_birth' => ['nullable','date'],
            ];

            return array_merge($common, $legal);
        }

        $individual = [
            'name' => ['nullable','string','max:255'],
            'surname' => ['nullable','string','max:255'],
            'middle_name' => ['nullable','string','max:255'],
            'passport_series' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('clients')
                    ->ignore($clientId)
                    ->where('type', 'individual'),
            ],

            'passport_validity' => ['nullable','date'],
            'passport_issued' => ['nullable','string','max:255'],
            'date_of_birth' => ['nullable','date'],

            'company_name' => ['nullable','string','max:255'],
            'legal_form' => ['nullable','string','max:50'],
            'tax_number' => ['nullable','string','max:50'],
            'state_register_number' => ['nullable','string','max:50'],
            'activity_field' => ['nullable','string','max:255'],
            'director_name' => ['nullable','string','max:255'],
            'accountant_info' => ['nullable','string','max:255'],
            'internal_code' => ['nullable','string','max:50'],
        ];

        return array_merge($common, $individual);
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Տեսակը պետք է լինի individual կամ legal։',
            'passport_series.unique' => 'Այս անձնագրի սերիայով ֆիզիկական հաճախորդ արդեն գոյություն ունի։',
            'company_name.unique' => 'Այս անվանումով իրավաբանական հաճախորդ արդեն գոյություն ունի։',
        ];
    }
}
