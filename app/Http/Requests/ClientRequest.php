<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->input('type', 'individual');

        $rules = [
            'type' => ['required', Rule::in(['individual', 'legal'])],

            'email' => ['nullable','email','max:255'],
            'phone' => ['nullable','string','max:20'],
            'additional_phone' => ['nullable','string','max:20'],
            'country' => ['nullable','string','max:255'],
            'city' => ['nullable','string','max:255'],
            'street' => ['nullable','string','max:255'],
            'building' => ['nullable','string','max:50'],
            'website' => ['nullable','string','max:255'],
            'residency_status'  => ['required','in:resident,non_resident'],
            'residency_country' => ['nullable','string','max:255'],

            'bank_name' => ['nullable','string','max:255'],
            'account_number' => ['nullable','string','max:50'],
            'card_number' => ['nullable','string','max:50'],
            'iban' => ['nullable','string','max:50'],
            'swift_code' => ['nullable','string','max:50'],

            'date' => ['nullable','date'],
            'has_contract' => ['sometimes','boolean'],
        ];

        if ($type === 'individual') {
            $rules = array_merge($rules, [
                'name' => ['required','string','max:255'],
                'surname' => ['required','string','max:255'],
                'middle_name' => ['nullable','string','max:255'],
                'social_card_number' => ['nullable','string','max:255'],
                'bank_client_id' => ['nullable','string','max:255'],

                'passport_series' => ['required','string','max:50'],
                'passport_validity' => ['required','date'],
                'passport_issued' => ['required','string','max:255'],
                'date_of_birth' => ['required','date'],
            ]);
        } else {
            $rules = array_merge($rules, [
                'company_name' => ['required','string','max:255'],
                'legal_form' => ['required','string','max:50'],
                'tax_number' => ['required','string','max:50'],
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
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Տեսակը պարտադիր է։',
            'type.in' => 'Տեսակը պետք է լինի individual կամ legal։',

            'name.required' => 'Անունը պարտադիր է (ֆիզիկական անձի դեպքում)։',
            'surname.required' => 'Ազգանունը պարտադիր է (ֆիզիկական անձի դեպքում)։',
            'passport_series.required' => 'Անձնագրի սերիան պարտադիր է (ֆիզիկական անձի դեպքում)։',
            'passport_validity.required' => 'Անձնագրի վավերականության վերջնաժամկետը պարտադիր է (ֆիզիկական անձի դեպքում)։',
            'passport_issued.required' => 'Տրված է դաշտը պարտադիր է (ֆիզիկական անձի դեպքում)։',
            'date_of_birth.required' => 'Ծննդյան օրը պարտադիր է (ֆիզիկական անձի դեպքում)։',
            'residency_status' => 'Ռեզիդենտության տեսակը պարտադիր է',
            'residency_status.in' => 'Տեսակը պետք է լինի resident,non_resident',
            'company_name.required' => 'Ընկերության անվանումը պարտադիր է (իրավաբանական անձի դեպքում)։',
            'legal_form.required' => 'Իրավական ձևը պարտադիր է (իրավաբանական անձի դեպքում)։',
            'tax_number.required' => 'ՀՎՀՀ/ՀՎՔ-ը պարտադիր է (իրավաբանական անձի դեպքում)։',
        ];
    }
}
