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
            'contract_amount' => 'nullable|numeric',
            'deadline' => 'required|integer',
            'contract_created_date' => 'nullable|date',
            'description' => 'nullable|string',
            'interest_rate' => 'required|numeric',
            'effective_rate' => 'nullable|numeric',
            'fee_annual_rate' => 'nullable|numeric',
            'penalty' => 'required|numeric',
            'lump_rate' => 'required|numeric',
            'payment_type' => 'required|string',
            'kasko_amount' => 'nullable|numeric',
            'guarantors' => 'nullable|array',
            'guarantors.*.id' => 'nullable|exists:clients,id',
            'seller_id' => 'nullable|exists:clients,id',
            'category_id' => 'required|exists:categories,id',
            'currency_id' => 'nullable|exists:currencies,id',
            'contract_kind'      => 'nullable|integer',
            'loan_type'          => 'nullable|integer',
            'interest_rate_type' => 'nullable|integer',
            'security_type'      => 'nullable|integer',
            'loan_use_field' => 'nullable|string',
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
            'effective_rate.required' => 'The interest rate is required.',
            'effective_rate.numeric' => 'The interest rate must be a numeric value.',
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
