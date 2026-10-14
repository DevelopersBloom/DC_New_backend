<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReminderOrderRequest extends FormRequest
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
    public function rules()
    {
        return [
            'order_date'       => 'sometimes|nullable|date',
            'amount'           => 'sometimes|required|numeric|min:0',
            'currency_id'      => 'sometimes|nullable|exists:currencies,id',
            'comment'          => 'sometimes|nullable|string|max:1000',
            'debit_account_id' => 'sometimes|nullable|exists:chart_of_accounts,id',
            'credit_account_id'=> 'sometimes|nullable|exists:chart_of_accounts,id',
            'debit_partner_id' => 'sometimes|nullable|exists:clients,id',
            'credit_partner_id'=> 'sometimes|nullable|exists:clients,id',
            'is_draft'         => 'sometimes|nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'order_date.date'          => 'Օրաթվի ձևաչափը պետք է լինի ՏՏՏՏ-ԱԱ-ՕՕ։',
            'amount.numeric'           => 'Գումարը պետք է լինի թիվ։',
            'amount.min'               => 'Գումարը չի կարող լինել բացասական։',
            'currency_id.exists'       => 'Արժույթը գտնվեց սխալ։',
            'comment.string'           => 'Մեկնաբանությունը պետք է լինի տեքստ։',
            'comment.max'              => 'Մեկնաբանությունը չի կարող անցնել 1000 նիշը։',
            'debit_account_id.exists'  => 'Դեբետ հաշիվը սխալ է։',
            'credit_account_id.exists' => 'Կրեդիտ հաշիվը սխալ է։',
            'debit_partner_id.exists'  => 'Դեբետ գործընկերը սխալ է։',
            'credit_partner_id.exists' => 'Կրեդիտ գործընկերը սխալ է։',
            'is_draft.boolean'         => 'Սևագրի դաշտը պետք է լինի true կամ false։',
        ];
    }
}
