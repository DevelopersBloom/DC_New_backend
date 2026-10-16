<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoanApplicationLoanReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_employed'              => ['required', 'boolean'],
            'employer_name'            => ['required_if:is_employed,true', 'nullable', 'string', 'max:255'],
            'employer_position'        => ['required_if:is_employed,true', 'nullable', 'string', 'max:255'],
            'employment_tenure_months' => ['required_if:is_employed,true', 'nullable', 'integer', 'min:0', 'max:1200'],
            'employment_salary'        => ['required_if:is_employed,true', 'nullable', 'numeric', 'min:0'],

            'owns_property'            => ['required', 'boolean'],
            'owned_property_info'      => ['required_if:owns_property,true', 'nullable', 'string', 'max:255'],

            'loan_purpose'             => ['nullable', 'string', 'max:1000'],
            'requested_amount'         => ['nullable', 'numeric', 'gt:0'],
            'acra_score'               => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_employed.required'    => 'Նշեք՝ հաճախորդն աշխատում է, թե ոչ։',
            'owns_property.required'  => 'Նշեք՝ հաճախորդն ունի գույք, թե ոչ։',
            'employer_name.required_if'       => 'Նշեք կազմակերպությունը։',
            'employer_position.required_if'   => 'Նշեք հաստիքը։',
            'employment_tenure_months.required_if' => 'Նշեք ստաժը։',
            'employment_salary.required_if'   => 'Նշեք աշխատավարձը։',
            'owned_property_info.required_if' => 'Նշեք գույքի անվանումը, հասցեն կամ պետհամարանիշը։',
        ];
    }
}
