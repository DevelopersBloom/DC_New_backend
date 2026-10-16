<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Այս դաշտը պարտադիր է։',
            'password.min' => 'Գաղտնաբառը պետք է լինի առնվազն 10 նիշ։',
            'password.mixed' => 'Գաղտնաբառը պետք է պարունակի մեծ և փոքր տառեր։',
            'password.letters' => 'Գաղտնաբառը պետք է պարունակի տառեր։',
            'password.numbers' => 'Գաղտնաբառը պետք է պարունակի թվանշան։',
            'password.symbols' => 'Գաղտնաբառը պետք է պարունակի սիմվոլ։',
        ];
    }
}
