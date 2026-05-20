<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContractAmountHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0',
            'type' => 'required|in:in,out',
            'date' => 'required|date',
            'contract_id' => 'nullable|integer|exists:contracts,id',
            'deal_id' => 'nullable|integer|exists:deals,id',
            'contract_num' => 'nullable|string|max:255',
        ];
    }
}
