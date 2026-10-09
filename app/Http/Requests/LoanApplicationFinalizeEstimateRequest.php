<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoanApplicationFinalizeEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'final_estimate_id' => ['required', 'exists:loan_application_estimates,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'final_estimate_id.required' => 'Ընտրեք վերջնական գնահատականը։',
        ];
    }
}
