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
            // The admin's own figure; the UI pre-fills it with the average of the estimates.
            'final_amount'      => ['required', 'numeric', 'gt:0'],
            // Optional: the estimate the figure is based on.
            'final_estimate_id' => ['nullable', 'exists:loan_application_estimates,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'final_amount.required' => 'Նշեք վերջնական գնահատականը։',
        ];
    }
}
