<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'surname' => $this->surname,
            'middle_name' => $this->middle_name,
            'passport_series' => $this->passport_series,
            'passport_validity' => $this->passport_validity,
            'passport_issued' => $this->passport_issued,
            'date_of_birth' => $this->date_of_birth,
            'email' => $this->email,
            'phone' => $this->phone,
            'additional_phone' => $this->additional_phone,
            'country' => $this->country,
            'city' => $this->city,
            'street' => $this->street,
            'building' => $this->building,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'card_number' => $this->card_number,
            'iban' => $this->iban,
        ];
    }
}
