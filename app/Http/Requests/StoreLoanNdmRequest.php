<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanNdmRequest extends FormRequest
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
            'contract_number' => ['required', 'string', 'max:100'],
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:255'],
            'currency_id' => ['required', 'exists:currencies,id'],
            'account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'interest_account_id' => ['nullable', 'exists:chart_of_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'calculate_first_day' => ['boolean'],
            'contract_date' => ['required', 'date'],
            'disbursement_date' => ['required', 'date'],
            'maturity_date' => ['nullable', 'date'],
            'comment' => ['nullable', 'string'],
            'pawnshop_id' => ['required', 'exists:pawnshops,id'],

            'interest_schedule_mode' => ['nullable', Rule::in(['fixed_day_of_month', 'periodicity', 'last_date'])],
            'repayment_start_date' => ['nullable', 'date'],
            'repayment_end_date' => ['nullable', 'date', 'after:repayment_start_date'],

            'day_count_convention' => ['required', Rule::in(['calendar_year', 'days_360', 'fixed_day'])],
            'access_type' => ['nullable',Rule::in(['loan', 'exchange', 'overdraft'])],
            'interest_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'effective_interest_rate' => ['nullable', 'numeric', 'between:0,100'],
            'actual_interest_rate' => ['nullable', 'numeric', 'between:0,100'],
            'effective_interest_amount' => ['nullable', 'numeric', 'min:0'],
            'calculate_effective_amount' => ['boolean'],
        ];
    }
}
