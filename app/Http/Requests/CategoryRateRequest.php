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
            'rates' => ['required', 'array'],
            'rates.*.id' => ['nullable', 'integer'],
            'rates.*.category_id' => ['required', 'integer'],
            'rates.*.interest_rate' => ['nullable', 'numeric', 'between:0,100'],
            'rates.*.penalty' => ['nullable', 'numeric', 'between:0,100'],
            'rates.*.min_amount' => ['nullable', 'numeric', 'min:0'],
            'rates.*.max_amount' => ['nullable', 'numeric', 'min:0', 'gte:rates.*.min_amount'],
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
