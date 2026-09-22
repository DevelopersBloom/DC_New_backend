<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'surname' => $this->surname,
            'middle_name' => $this->middle_name,
            'full_name' => trim(implode(' ', array_filter([$this->name, $this->middle_name, $this->surname]))),
            'email' => $this->email,
            'tel' => $this->tel,
            'position' => $this->position,
            'role' => $this->roles->first()?->name,
            'pawnshop' => $this->pawnshop ? [
                'id' => $this->pawnshop->id,
                'city' => $this->pawnshop->city,
            ] : null,
            'start_work' => $this->start_work ? substr((string) $this->start_work, 0, 10) : null,
            'created_at' => $this->created_at?->toDateString(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
        ];
    }
}
