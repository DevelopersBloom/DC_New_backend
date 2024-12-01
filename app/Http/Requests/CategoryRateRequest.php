<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryRateRequest extends FormRequest
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
            'category_id'   => 'required','exists:categories,id',
            'interest_rate' => 'nullable|numeric|min:0',
            'penalty'       => 'nullable|numeric|min:0',
            'min_amount'    => 'nullable|numeric|min:0',
            'max_amount'    => 'nullable|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'The category ID is required.',
            'category_id.exists'   => 'The selected category does not exist.',
        ];
    }
}
