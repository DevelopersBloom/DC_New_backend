<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChartOfAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
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
            'code'      => 'required|string|max:20|unique:chart_of_accounts,code',
            'name'      => 'required|string|max:255',
            'type'      => 'required|in:active,passive,active-passive,off-balance',
            'parent_id' => 'nullable|exists:chart_of_accounts,id',
        ];
    }
    public function messages(): array
    {
        return [
            'code.required'   => 'Հաշվը պարտադիր է։',
            'code.string'     => 'Հաշվը պետք է լինի տեքստ։',
            'code.max'        => 'Հաշիվը չի կարող անցնել 20 նիշը։',
            'code.unique'     => 'Այս հաշիվը արդեն գոյություն ունի։',

            'name.required'   => 'Հաշվի անվանումը պարտադիր է։',
            'name.string'     => 'Հաշվի անվանումը պետք է լինի տեքստ։',
            'name.max'        => 'Հաշվի անվանումը չի կարող անցնել 255 նիշը։',

            'type.required'   => 'Հաշվի տեսակը պարտադիր է։',
            'type.in'         => 'Հաշվի տեսակը պետք է լինի՝ active, passive, active-passive կամ off-balance։',

            'parent_id.exists' => 'Ընտրված ծնող հաշիվը գոյություն չունի։',
        ];
    }
}
