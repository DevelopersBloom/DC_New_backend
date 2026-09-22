<?php

namespace App\Http\Requests\Admin;

class UpdateUserRequest extends UserRequest
{
    public function rules(): array
    {
        return $this->profileRules();
    }

    protected function ignoredUserId(): ?int
    {
        return $this->route('user')->getKey();
    }
}
