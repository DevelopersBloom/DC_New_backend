<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReminderOrderRequest extends FormRequest
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
            'order_date'       => 'nullable|date',
            'amount'           => 'nullable|numeric|min:0',
            'currency_id'      => 'nullable|exists:currencies,id',
            'comment'          => 'nullable|string|max:1000',
            'debit_account_id' => 'nullable|exists:chart_of_accounts,id',
            'credit_account_id'=> 'nullable|exists:chart_of_accounts,id',
            'is_draft'         => 'boolean',
        ];
    }
    public function messages(): array
    {
        return [
            'order_date.date'            => 'Օրդերի ամսաթիվը պետք է լինի ճիշտ ձևաչափով (ՏՏՏՏ-ԱԱ-ՕՕ)։',
            'amount.numeric'             => 'Գումարը պետք է լինի թիվ։',
            'amount.min'                 => 'Գումարը չի կարող լինել բացասական։',
            'currency_id.exists'         => 'Ընտրված արժույթը գոյություն չունի։',
            'comment.string'             => 'Մեկնաբանությունը պետք է լինի տեքստ։',
            'comment.max'                => 'Մեկնաբանությունը չի կարող գերազանցել 1000 նիշ։',
            'debit_account_id.exists'    => 'Դեբետ հաշիվը սխալ է։',
            'credit_account_id.exists'   => 'Կրեդիտ հաշիվը սխալ է։',
            'is_draft.boolean'           => 'Սևագրի դաշտը պետք է լինի true կամ false։',
        ];
    }
}
