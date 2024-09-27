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
            'email.unique' => 'The email address is already in use by another client.',
        ];
    }

}
