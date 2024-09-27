<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateContract extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'estimated_amount' => 'required|numeric',
            'provided_amount' => 'required|numeric',
            'category_id' => 'required|exists:categories,id',
            'subcategory' => 'required|string|max:255',
            'model' => 'nullable|string|max:255',
            'interest_rate' => 'required|numeric',
            'penalty' => 'required|numeric',
            'deadline' => 'required|integer',
            'lump_sum' => 'nullable|numeric',
            'description' => 'nullable|string',
            'status' => 'in:initial,completed,executed',
            'bank_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'card_number' => 'nullable|string|max:255',
            'iban' => 'nullable|string|max:255',
        ];
    }
}
