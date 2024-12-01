<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|\JsonSerializable
     */
    public function toArray($request): array|\JsonSerializable|Arrayable
    {
        $created_months = Carbon::parse($this->created_at)->diffInMonths();
        return [
            'name'        => $this->name,
            'surname'     => $this->surname,
            'middle_name' => $this->middle_name,
            'role'        => $this->role,
            'email'       => $this->email,
            'tel'         => $this->tel,
            'months'      => $created_months,
            'files'       => $this->files->map(function ($file) {
                return [
                    'id'            => $file->id,
                    'name'          => $file->name,
                    'file_type'     => $file->file_type,
                    'original_name' => $file->original_name,
                ];
            }),
        ];
    }
}
