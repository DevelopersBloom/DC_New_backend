<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
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
        return [
            'name'        => 'nullable|string|max:255',
            'surname'     => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'email'       => 'nullable|email|max:255|unique:users,email,' . auth()->id(),
            'tel'         => 'nullable|string|max:15',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'The email has already been taken.',
            'tel.max' => 'The telephone number must not exceed 15 characters.',
        ];
    }
}
