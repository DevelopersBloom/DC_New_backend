<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One reviewing admin's independent estimated collateral value.
 * The admin identity is taken from the authenticated user in the controller,
 * never from the request body.
 */
class LoanApplicationEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estimated_amount' => ['required', 'numeric', 'min:0'],
            'currency_id'      => ['nullable', 'exists:currencies,id'],
            'note'             => ['nullable', 'string', 'max:1000'],
        ];
    }
}
