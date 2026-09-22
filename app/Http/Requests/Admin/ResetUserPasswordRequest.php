<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Այս դաշտը պարտադիր է։',
            'password.min' => 'Գաղտնաբառը պետք է լինի առնվազն 8 նիշ։',
        ];
    }
}
