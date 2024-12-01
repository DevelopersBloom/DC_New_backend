<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
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
            'name'        => 'required|string|max:255',
            'surname'     => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'position'    => 'nullable|string|max:255',
            'tel'         => 'nullable|string|max:20',
            'email'       => 'required|email|unique:users,email',
            'password'    => 'required|string|min:8',
            'role'        => 'required|string|max:255',
            'start_work'  => 'nullable|date',
        ];
    }
    public function messages(): array
    {
        return [
            'name.required'     => 'The name is required.',
            'surname.required'  => 'The surname is required.',
            'email.unique'      => 'This email is already taken.',
            'password.required' => 'The password is required.',
            'role.required'     => 'The role is required.',
            'start_work.date'   => 'The start work date must be a valid date.',
        ];
    }

}
