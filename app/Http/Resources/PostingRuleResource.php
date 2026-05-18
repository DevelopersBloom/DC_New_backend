<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostingRuleResource extends JsonResource
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
            'id'                     => $this->id,
            'business_event_filter'  => $this->business_event_filter,
            'debit_account'          => new AccountResource($this->whenLoaded('debitAccount')),
            'credit_account'         => new AccountResource($this->whenLoaded('creditAccount')),
            'debit_partner'          => $this->debit_partner,
            'credit_partner'         => $this->credit_partner,
        ];
    }
}
