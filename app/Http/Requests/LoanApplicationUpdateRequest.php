<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin edit of the applicant-entered inputs. The loan type, the client's
 * identity and every estimate are deliberately not editable here.
 * Items are matched by `id`; items without an id are added, existing items
 * missing from the payload are left alone.
 */
class LoanApplicationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $loanType = $this->route('id')
            ? optional(\App\Models\LoanApplication::find($this->route('id')))->loan_type
            : null;

        $rules = [
            'comments'              => ['nullable', 'string'],
            'client_age'            => ['nullable', 'integer', 'min:14', 'max:120'],
            'client_income'         => ['nullable', 'numeric', 'min:0'],
            'client'                => ['nullable', 'array'],
            'client.name'           => ['sometimes', 'required', 'string', 'max:255'],
            'client.surname'        => ['sometimes', 'required', 'string', 'max:255'],
            'client.phone'          => ['sometimes', 'required', 'string', 'max:20'],

            // Loan-review inputs; an unanswered yes/no (key absent or null) is left as is.
            'is_employed'              => ['nullable', 'boolean'],
            'employer_name'            => ['nullable', 'string', 'max:255'],
            'employer_position'        => ['nullable', 'string', 'max:255'],
            'employment_tenure_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'employment_salary'        => ['nullable', 'numeric', 'min:0'],
            'owns_property'            => ['nullable', 'boolean'],
            'owned_property_info'      => ['nullable', 'string', 'max:255'],
            'loan_purpose'             => ['nullable', 'string', 'max:1000'],
            'requested_amount'         => ['nullable', 'numeric', 'gt:0'],
            'acra_score'               => ['nullable', 'string', 'max:50'],

            'items'                 => ['nullable', 'array'],
            'items.*.id'            => ['nullable', 'integer'],
            'items.*.category_id'   => ['required', 'exists:categories,id'],
            'items.*.description'   => ['nullable', 'string'],
        ];

        if ($loanType === 'gold') {
            $rules = array_merge($rules, [
                'items.*.subcategory'  => ['required', 'string', 'max:255'],
                'items.*.weight'       => ['required', 'numeric', 'min:0'],
                'items.*.clear_weight' => ['nullable', 'numeric', 'min:0'],
                'items.*.hallmark'     => ['required', 'string', 'max:255'],
                'items.*.model'        => ['nullable', 'string', 'max:255'],
            ]);
        }

        if ($loanType === 'car') {
            $rules = array_merge($rules, [
                'items.*.car_make'       => ['required', 'string', 'max:255'],
                'items.*.manufacture'    => ['required', 'integer'],
                'items.*.license_plate'  => ['required', 'string', 'max:255'],
                'items.*.power'          => ['nullable', 'string', 'max:255'],
                'items.*.color'          => ['nullable', 'string', 'max:255'],
                'items.*.registration'   => ['nullable', 'string', 'max:255'],
                'items.*.identification' => ['nullable', 'string', 'max:255'],
                'items.*.ownership'      => ['nullable', 'string', 'max:255'],
            ]);
        }

        if ($loanType === 'property') {
            $rules = array_merge($rules, [
                'items.*.real_estate'                      => ['required', 'array'],
                'items.*.real_estate.cadastral_code'       => ['required', 'string', 'max:255'],
                'items.*.real_estate.certificate_number'   => ['nullable', 'string', 'max:255'],
                'items.*.real_estate.certificate_password' => ['nullable', 'string', 'max:255'],
                'items.*.real_estate.area_sqm'             => ['nullable', 'numeric', 'min:0'],
                'items.*.real_estate.is_joint'             => ['nullable', 'boolean'],
            ]);
        }

        return $rules;
    }
}
