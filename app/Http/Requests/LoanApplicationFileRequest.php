<?php

namespace App\Http\Requests;

use App\Support\LoanApplicationDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Adds named, typed documents to an existing application. */
class LoanApplicationFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files'               => ['required', 'array', 'min:1'],
            'files.*'             => ['file', 'max:10240'],
            'file_types'          => ['nullable', 'array'],
            'file_types.*'        => [Rule::in(LoanApplicationDocument::TYPES)],
            'file_titles'         => ['nullable', 'array'],
            'file_titles.*'       => ['nullable', 'string', 'max:255'],
            'file_visibilities'   => ['nullable', 'array'],
            'file_visibilities.*' => [Rule::in(['public', 'admin_only'])],
        ];
    }
}
