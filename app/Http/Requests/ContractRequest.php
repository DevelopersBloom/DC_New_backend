<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContractRequest extends FormRequest
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
            'deadline' => 'required|integer',
            'description' => 'nullable|string',
            'interest_rate' => 'required|numeric',
            'penalty' => 'required|numeric',
            'lump_rate' => 'required|numeric',
//            'files' => 'nullable|array',
//            'files.*.file' => 'required|file',
//            'files.*.file_type' => 'required|string',
        ];
    }
    public function messages()
    {
        return [
            'estimated_amount.required' => 'The estimated amount is required.',
            'estimated_amount.numeric' => 'The estimated amount must be a numeric value.',
            'provided_amount.required' => 'The provided amount is required.',
            'provided_amount.numeric' => 'The provided amount must be a numeric value.',
            'interest_rate.required' => 'The interest rate is required.',
            'interest_rate.numeric' => 'The interest rate must be a numeric value.',
            'penalty.required' => 'The penalty amount is required.',
            'penalty.numeric' => 'The penalty must be a numeric value.',
            'deadline.required' => 'The deadline is required.',
            'deadline.integer' => 'The deadline must be an integer.',
            'lump_rate.numeric' => 'The lump rate must be a numeric value.',
            'description.string' => 'The description must be a string.',
            'pawnshop_id.required' => 'The pawnshop ID is required.',
            'pawnshop_id.exists' => 'The pawnshop ID must exist in the pawnshops table.',
//            'files.array' => 'The files must be an array.',
//            'file_type.required' => 'The file type  is required.',
        ];
    }
}
