<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A single endpoint handles both branches from the flowchart:
 *  - an existing client        -> `client_id`
 *  - a brand-new applicant     -> `new_client: { name, surname, phone }` (quick intake)
 *
 * Per-item rules mirror ClientRequest's individual/legal branching, keyed off
 * `loan_type` (car / gold / property).
 *
 * Files: `files[]` plus a parallel `file_visibilities[]` (same index as `files`)
 * carrying a per-file `public` / `admin_only` flag — NOT a single global toggle.
 */
class LoanApplicationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $loanType = $this->input('loan_type');

        $rules = [
            'client_id'         => ['required_without:new_client', 'nullable', 'exists:clients,id'],
            'new_client'        => ['required_without:client_id', 'array'],
            'new_client.name'   => ['required_with:new_client', 'string', 'max:255'],
            'new_client.surname' => ['required_with:new_client', 'string', 'max:255'],
            'new_client.phone'  => ['required_with:new_client', 'string', 'max:20'],

            'loan_type' => ['required', Rule::in(['car', 'gold', 'property'])],
            'comments'  => ['nullable', 'string'],

            'items'                 => ['required', 'array', 'min:1'],
            'items.*.category_id'   => ['required', 'exists:categories,id'],
            'items.*.description'   => ['nullable', 'string'],

            'files'                 => ['nullable', 'array'],
            'files.*'               => ['file', 'max:10240'],
            'file_visibilities'     => ['nullable', 'array'],
            'file_visibilities.*'   => [Rule::in(['public', 'admin_only'])],
        ];

        if ($loanType === 'gold') {
            $rules = array_merge($rules, [
                'items.*.subcategory' => ['required', 'string', 'max:255'],
                'items.*.weight'      => ['required', 'numeric', 'min:0'],
                'items.*.clear_weight' => ['nullable', 'numeric', 'min:0'],
                'items.*.hallmark'    => ['required', 'string', 'max:255'],
                'items.*.model'       => ['nullable', 'string', 'max:255'],
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
                'items.*.real_estate'                     => ['required', 'array'],
                'items.*.real_estate.cadastral_code'      => ['required', 'string', 'max:255'],
                'items.*.real_estate.certificate_number'  => ['nullable', 'string', 'max:255'],
                'items.*.real_estate.certificate_password' => ['nullable', 'string', 'max:255'],
                'items.*.real_estate.area_sqm'            => ['nullable', 'numeric', 'min:0'],
                'items.*.real_estate.is_joint'            => ['nullable', 'boolean'],
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'client_id.required_without'  => 'Ընտրեք հաճախորդ կամ լրացրեք նոր դիմողի տվյալները։',
            'new_client.required_without' => 'Ընտրեք հաճախորդ կամ լրացրեք նոր դիմողի տվյալները։',
            'loan_type.required'          => 'Վարկի տեսակը պարտադիր է։',
            'loan_type.in'                => 'Վարկի տեսակը պետք է լինի car, gold կամ property։',
            'items.required'              => 'Առնվազն մեկ գրավ պարտադիր է։',
            'items.min'                   => 'Առնվազն մեկ գրավ պարտադիր է։',
        ];
    }
}
