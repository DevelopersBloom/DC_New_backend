<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quick "ոչ հաճախորդ" (non-client) intake — name, surname and phone only.
 * Everything else in the client profile stays optional at this stage, so
 * this deliberately does NOT require passport, gender, document type or
 * residency status the way ClientRequest does.
 */
class NonClientQuickIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'phone'   => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'Անունը պարտադիր է։',
            'surname.required' => 'Ազգանունը պարտադիր է։',
            'phone.required'   => 'Հեռախոսահամարը պարտադիր է։',
        ];
    }
}
