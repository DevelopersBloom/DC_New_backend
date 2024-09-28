<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $passportSeries = $this->passport_series;
        return [
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'passport_series' => 'required|string|max:50',
            'passport_validity' => 'required|date',
            'passport_issued' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'email' => [
                'required',
                'email',
                Rule::unique('clients')->where(function ($query) use ($passportSeries) {
                    return $query->where('passport_series', '!=', $passportSeries);
                }),
            ],
            'phone' => 'required|string|max:20',
            'additional_phone' => 'nullable|string|max:20',
            'country' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'street' => 'required|string|max:255',
            'building' => 'required|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'card_number' => 'nullable|string|max:50',
            'iban' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The name is required.',
            'name.string' => 'The name must be a string.',
            'name.max' => 'The name must not exceed 255 characters.',

            'surname.required' => 'The surname is required.',
            'surname.string' => 'The surname must be a string.',
            'surname.max' => 'The surname must not exceed 255 characters.',

            'middle_name.string' => 'The middle name must be a string.',
            'middle_name.max' => 'The middle name must not exceed 255 characters.',

            'passport_series.required' => 'The passport series is required.',
            'passport_series.string' => 'The passport series must be a string.',
            'passport_series.max' => 'The passport series must not exceed 50 characters.',

            'passport_validity.required' => 'The passport validity date is required.',
            'passport_validity.date' => 'The passport validity must be a valid date.',

            'passport_issued.required' => 'The passport issued field is required.',
            'passport_issued.string' => 'The passport issued field must be a string.',
            'passport_issued.max' => 'The passport issued field must not exceed 255 characters.',

            'date_of_birth.required' => 'The date of birth is required.',
            'date_of_birth.date' => 'The date of birth must be a valid date.',

            'email.required' => 'The email address is required.',
            'email.email' => 'The email address must be a valid email format.',
            'email.unique' => 'The email address is already in use by another client.',

            'phone.required' => 'The phone number is required.',
            'phone.string' => 'The phone number must be a string.',
            'phone.max' => 'The phone number must not exceed 20 characters.',

            'additional_phone.string' => 'The additional phone number must be a string.',
            'additional_phone.max' => 'The additional phone number must not exceed 20 characters.',

            'country.required' => 'The country is required.',
            'country.string' => 'The country must be a string.',
            'country.max' => 'The country must not exceed 255 characters.',

            'city.required' => 'The city is required.',
            'city.string' => 'The city must be a string.',
            'city.max' => 'The city must not exceed 255 characters.',

            'street.required' => 'The street is required.',
            'street.string' => 'The street must be a string.',
            'street.max' => 'The street must not exceed 255 characters.',

            'building.required' => 'The building number is required.',
            'building.string' => 'The building number must be a string.',
            'building.max' => 'The building number must not exceed 50 characters.',

            'bank_name.string' => 'The bank name must be a string.',
            'bank_name.max' => 'The bank name must not exceed 255 characters.',

            'account_number.string' => 'The account number must be a string.',
            'account_number.max' => 'The account number must not exceed 50 characters.',

            'card_number.string' => 'The card number must be a string.',
            'card_number.max' => 'The card number must not exceed 50 characters.',

            'iban.string' => 'The IBAN must be a string.',
            'iban.max' => 'The IBAN must not exceed 50 characters.',
        ];
    }

}
