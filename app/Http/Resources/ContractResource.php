<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|\JsonSerializable
     */
    public function toArray($request): array|\JsonSerializable|Arrayable
    {
        return [
            'id' => $this->id,
            'estimated_amount' => $this->estimated_amount,
            'provided_amount' => $this->provided_amount,
            'interest_rate' => $this->interest_rate,
            'penalty' => $this->penalty,
            'deadline' => $this->deadline,
            'lump_sum' => $this->lump_sum,
            'description' => $this->description,
            'pawnshop_id' => $this->pawnshop_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'client' => new ClientResource($this->whenLoaded('client')),
            'items' => ItemResource::collection($this->whenLoaded('items')),
            'files' => FileResource::collection($this->whenLoaded('files')),
        ];
    }
}
