<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Quick "ոչ հաճախորդ" (non-client) intake.
 *
 * Individuals only need name, surname and phone; legal entities only need the
 * company name. Every other client-profile field is optional, but is validated
 * and saved when the user fills it in (unlike ClientRequest, which requires
 * passport, gender, document type and residency status).
 */
class NonClientQuickIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->input('type', 'individual');

        $rules = [
            'type' => ['nullable', Rule::in(['individual', 'legal'])],

            'email' => ['nullable', 'email', 'max:255'],
            'additional_phone' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],

            'legal_country' => ['nullable', 'string', 'max:255'],
            'legal_province' => ['nullable', 'string', 'max:255'],
            'legal_community' => ['nullable', 'string', 'max:255'],
            'legal_settlement' => ['nullable', 'string', 'max:255'],
            'legal_street_building' => ['nullable', 'string', 'max:255'],
            'legal_zip_code' => ['nullable', 'string', 'max:20'],

            'actual_country' => ['nullable', 'string', 'max:255'],
            'actual_province' => ['nullable', 'string', 'max:255'],
            'actual_community' => ['nullable', 'string', 'max:255'],
            'actual_settlement' => ['nullable', 'string', 'max:255'],
            'actual_street_building' => ['nullable', 'string', 'max:255'],
            'actual_zip_code' => ['nullable', 'string', 'max:20'],

            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'card_number' => ['nullable', 'string', 'max:50'],
            'iban' => ['nullable', 'string', 'max:50'],
            'swift_code' => ['nullable', 'string', 'max:50'],

            'date' => ['nullable', 'date'],
            'is_linked_to_company' => ['sometimes', 'nullable', 'boolean'],
            'is_company_employee' => ['sometimes', 'nullable', 'boolean'],
            'region_code' => ['nullable', 'string'],

            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];

        if ($type === 'legal') {
            return array_merge($rules, [
                'phone' => ['nullable', 'string', 'max:20'],
                'company_name' => ['required', 'string', 'max:255'],
                'legal_form' => ['nullable', 'string', 'max:50'],
                'tax_number' => ['nullable', 'string', 'max:50'],
                'state_register_number' => ['nullable', 'string', 'max:50'],
                'activity_field' => ['nullable', 'string', 'max:255'],
                'director_name' => ['nullable', 'string', 'max:255'],
                'accountant_info' => ['nullable', 'string', 'max:255'],
                'internal_code' => ['nullable', 'string', 'max:50'],
            ]);
        }

        return array_merge($rules, [
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['MALE', 'FEMALE'])],
            'document_type' => ['nullable', Rule::in([
                'NON_BIOMETRIC_PASSPORT', 'ID_CARD', 'BIOMETRIC_PASSPORT',
                'BIRTH_CERTIFICATE', 'RESIDENCE_CARD', 'TRAVEL_DOCUMENT',
                'FOREIGN_PASSPORT', 'OTHER',
            ])],
            'is_married' => ['nullable', 'boolean'],
            'social_card_number' => ['nullable', 'string', 'max:255'],
            'bank_client_id' => ['nullable', 'string', 'max:255'],
            'passport_series' => ['nullable', 'string', 'max:50'],
            'passport_validity' => ['nullable', 'date'],
            'passport_issued' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'residency_status' => ['nullable', 'in:resident,non_resident'],
            'residency_country' => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Անունը պարտադիր է։',
            'surname.required' => 'Ազգանունը պարտադիր է։',
            'phone.required' => 'Հեռախոսահամարը պարտադիր է։',
            'company_name.required' => 'Ընկերության անվանումը պարտադիր է։',
        ];
    }
}
