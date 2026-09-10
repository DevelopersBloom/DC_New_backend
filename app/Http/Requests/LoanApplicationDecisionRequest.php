<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The designated approver's final call on a submitted application.
 *
 * `final_estimate_id` must reference an estimate; the controller additionally
 * verifies the estimate actually belongs to THIS application (not just any).
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
            'status'            => ['required', Rule::in(['approved', 'rejected'])],
            'final_estimate_id' => ['required_if:status,approved', 'nullable', 'exists:loan_application_estimates,id'],
            'rejected_reason'   => ['required_if:status,rejected', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required'              => 'Որոշումը պարտադիր է։',
            'status.in'                    => 'Որոշումը պետք է լինի approved կամ rejected։',
            'final_estimate_id.required_if' => 'Հաստատելու համար ընտրեք վերջնական գնահատականը։',
            'rejected_reason.required_if'  => 'Մերժման պատճառը պարտադիր է։',
        ];
    }
}
