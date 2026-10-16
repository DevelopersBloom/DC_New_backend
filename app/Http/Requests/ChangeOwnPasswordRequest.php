<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeOwnPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:api'],
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                'different:current_password',
                Password::min(10)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Մուտքագրեք ընթացիկ գաղտնաբառը։',
            'current_password.current_password' => 'Ընթացիկ գաղտնաբառը սխալ է։',
            'password.required' => 'Մուտքագրեք նոր գաղտնաբառը։',
            'password.confirmed' => 'Գաղտնաբառերը չեն համընկնում։',
            'password.different' => 'Նոր գաղտնաբառը պետք է տարբերվի ընթացիկից։',
            'password.min' => 'Գաղտնաբառը պետք է լինի առնվազն 10 նիշ։',
            'password.mixed' => 'Գաղտնաբառը պետք է պարունակի մեծ և փոքր տառեր։',
            'password.letters' => 'Գաղտնաբառը պետք է պարունակի տառեր։',
            'password.numbers' => 'Գաղտնաբառը պետք է պարունակի թվանշան։',
            'password.symbols' => 'Գաղտնաբառը պետք է պարունակի սիմվոլ։',
        ];
    }
}
