<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'           => $this->id,
            'name' => $this->company_name
                ?: trim(($this->name ?? '') . ' ' . ($this->surname ?? '')),
            'tax_number/social_card_number' => $this->tax_number ?: $this->social_card_number,
        ];
    }
}
