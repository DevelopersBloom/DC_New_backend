<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared profile rules for creating and updating users.
 * Authorization is handled by the `can:*` route middleware.
 */
abstract class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->input('email')) ? strtolower(trim($this->input('email'))) : $this->input('email'),
            'role' => is_string($this->input('role')) ? strtolower($this->input('role')) : $this->input('role'),
        ]);
    }

    /** The user being edited, excluded from the email uniqueness checks. */
    protected function ignoredUserId(): ?int
    {
        return null;
    }

    /**
     * The DB unique index on email also covers soft-deleted users, so point
     * the admin to restoring them instead of failing on insert/update.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('email')) {
                return;
            }

            $takenByDeletedUser = User::onlyTrashed()
                ->where('email', $this->input('email'))
                ->when($this->ignoredUserId(), fn ($q, $id) => $q->whereKeyNot($id))
                ->exists();

            if ($takenByDeletedUser) {
                $validator->errors()->add(
                    'email',
                    'Այս էլ. հասցեով օգտատերը ջնջված է։ Վերականգնեք այն «Ջնջված» ցանկից։'
                );
            }
        });
    }

    protected function profileRules(): array
    {
        $ignoreUserId = $this->ignoredUserId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($ignoreUserId)->whereNull('deleted_at'),
            ],
            'tel' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:255'],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')->where('guard_name', 'api'),
            ],
            'pawnshop_id' => [
                'required',
                'integer',
                Rule::exists('pawnshops', 'id')->whereNull('deleted_at'),
            ],
            'start_work' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Այս դաշտը պարտադիր է։',
            'email.email' => 'Էլ. հասցեն սխալ է։',
            'email.unique' => 'Այս էլ. հասցեով օգտատեր արդեն կա։',
            'role.exists' => 'Ընտրված դերը գոյություն չունի։',
            'pawnshop_id.exists' => 'Ընտրված մասնաճյուղը գոյություն չունի։',
            'start_work.date' => 'Ամսաթիվը սխալ է։',
            'password.min' => 'Գաղտնաբառը պետք է լինի առնվազն 8 նիշ։',
            'max' => 'Արժեքը չափազանց երկար է։',
        ];
    }
}
