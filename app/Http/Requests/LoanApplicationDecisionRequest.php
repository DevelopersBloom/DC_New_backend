<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The loan-review decision: approve with the amount to disburse, or reject.
 */
class LoanApplicationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'               => ['required', Rule::in(['approved', 'rejected'])],
            // No ceiling: the provided amount may exceed the collateral estimate.
            'provided_amount'      => ['required_if:status,approved', 'nullable', 'numeric', 'gt:0'],
            'provided_currency_id' => ['nullable', 'exists:currencies,id'],
            'provided_note'        => ['nullable', 'string', 'max:1000'],
            'rejected_reason'      => ['required_if:status,rejected', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required'              => 'Որոշումը պարտադիր է։',
            'status.in'                    => 'Որոշումը պետք է լինի approved կամ rejected։',
            'provided_amount.required_if'  => 'Հաստատելու համար նշեք տրամադրվող գումարը։',
            'rejected_reason.required_if'  => 'Մերժման պատճառը պարտադիր է։',
        ];
    }
}
