<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
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
            'contract_id' => 'required|exists:contracts,id',
            'amount' => 'required|numeric|min:0.01',
            'penalty' => 'nullable|numeric',
            'payment_date' => 'nullable|date',
            'payments' => 'nullable|array',
            'payments.*' => 'integer|exists:payments,id',
            'payment_ids' => 'nullable|array',
            'payment_ids.*' => 'integer|exists:payments,id',
            'payer' => 'nullable|array',
            'payer.name' => 'nullable|string',
            'payer.surname' => 'nullable|string',
            'payer.phone' => 'nullable|string',
            'cash' => 'required|boolean'
        ];
    }
}
