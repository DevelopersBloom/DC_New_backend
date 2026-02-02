<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
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
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'user_surname' => $this->user?->surname,
            'action' => $this->action,
            'description' => $this->description,
            'model' => $this->model,
            'model_id' => $this->model_id,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }

}
