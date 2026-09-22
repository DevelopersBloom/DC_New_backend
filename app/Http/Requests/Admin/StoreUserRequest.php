<?php

namespace App\Http\Requests\Admin;

class StoreUserRequest extends UserRequest
{
    public function rules(): array
    {
        return array_merge($this->profileRules(), [
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);
    }
}
