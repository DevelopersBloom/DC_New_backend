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
            'interest_rate' => 'required|numeric',
            'penalty' => 'required|numeric',
            'deadline' => 'required|integer',
            'lump_sum' => 'nullable|numeric',
            'description' => 'nullable|string',
            'pawnshop_id' => 'required|exists:pawnshops,id',
            'files' => 'nullable|array',
            'files.*' => 'file',
            'file_type_id' => 'required|integer|exists:file_types,id'
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
            'lump_sum.numeric' => 'The lump sum must be a numeric value.',
            'description.string' => 'The description must be a string.',
            'pawnshop_id.required' => 'The pawnshop ID is required.',
            'pawnshop_id.exists' => 'The pawnshop ID must exist in the pawnshops table.',
            'files.array' => 'The files must be an array.',
            'files.*.file' => 'Each uploaded file must be a valid file.',
            'file_type_id.required' => 'The file type ID is required.',
            'file_type_id.integer' => 'The file type ID must be an integer.',
            'file_type_id.exists' => 'The selected file type ID does not exist in the file_types table.',
        ];
    }
}
