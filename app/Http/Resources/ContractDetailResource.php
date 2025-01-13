<?php

namespace App\Http\Resources;

use App\Models\HistoryType;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractDetailResource extends JsonResource
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
            'contract' => [
                'id'               => $this->id,
                'num'              => $this->num,
                'estimated_amount' => $this->estimated_amount,
                'provided_amount'  => $this->provided_amount,
                'interest_rate'    => $this->interest_rate,
                'penalty'          => $this->penalty,
                'lump_rate'        => $this->lump_rate,
            ],
            'client' => [
                'id'                => $this->client->id,
                'name'              => $this->client->name,
                'surname'           => $this->client->surname,
                'middle_name'       => $this->client->middle_name,
                'country'           => $this->client->country,
                'city'              => $this->client->city,
                'street'            => $this->client->street,
                'building'          => $this->client->building,
                'phone'             => $this->client->phone,
                'additional_phone'  => $this->client->additional_phone,
                'email'             => $this->client->email,
                'date_of_birth'     => $this->client->date_of_birth,
                'passport_series'   => $this->client->passport_series,
                'passport_validity' => $this->client->passport_validity,
                'passport_issued'   => $this->client->passport_issued,
            ],
            'payments' => $this->payments->map(function ($payment) {
                return [
                    'id'      => $payment->id,
                    'amount'  => $payment->amount,
                    'paid'    => $payment->paid,
                    'penalty' => $payment->penalty,
                    'date'    => $payment->date,
                    'status'  => $payment->status,
                    'mother'  => $payment->mother
                ];
            }),
            'history' => $this->history->map(function ($history) {
                return [
                    'id'   => $history->id,
                    'type' => $history->type->title,
                    'date' => $history->date,
//                    'user' => [
//                        'id' => $history->user->id,
//                        'name' => $history->user->name,
//                        'surname' => $history->user->surname,
//                        'role' => $history->user->role,
//                        'email' => $history->user->email,
//                    ],
                    'order' => [
                        'id' => $history->order->id ?? null,
                        'amount' => $history->order->amount ?? null,
                        'status' => $history->order->status ?? null,
                        'created_at' => $history->order->created_at ?? null,
                    ]

                ];
            }),
            'files' => $this->files->map(function ($file) {
                return [
                    'id'            => $file->id,
                    'name'          => $file->name,
                    'type'          => $file->type,
                    'original_name' => $file->original_name,
                    'file_type'     => $file->file_type,
                    'url'           => asset('storage/client/files/' . $file->name),
                ];
            }),
            'items' => $this->items->map(function ($item) {
                if ($item->category->name === 'phone') {
                    return [
                        'id' => $item->id,
                        'category' => $item->category->title,
                        'subcategory' => $item->subcategory,
                        'model' => $item->model,
                        'description' => $item->description,

                    ];
                } elseif ($item->category->name === 'gold') {
                    return [
                        'id' => $item->id,
                        'category' => $item->category->title,
                        'subcategory' => $item->subcategory,
                        'weight' => $item->weight,
                        'clear_weight' => $item->clear_weight,
                        'hallmark' => $item->hallmark,
                        'description' => $item->description,

                    ];
                } elseif ($item->category->name === 'car') {
                    return [
                        'id' => $item->id,
                        'category' => $item->category->title,
                        'car_make' => $item->car_make,
                        'manufacture' => $item->manufacture,
                        'power' => $item->power,
                        'license_plate' => $item->license_plate,
                        'color' => $item->color,
                        'registration' => $item->registration,
                        'identification' => $item->identification,
                        'ownership' => $item->ownership,
                        'issued_by' => $item->issued_by,
                        'date_of_issuance' => $item->date_of_issuance,
                        'description' => $item->description,

                    ];
                } else {
                    return [
                        'id' => $item->id,
                        'category' => $item->category->title,
                        'subcategory' => $item->subcategory,
                        'description' => $item->description,
                    ];
                }
            }),
            'current_payment_amount' => $this->current_payment_amount,
            'penalty_amount' => $this->penalty_amount,
        ];
    }
}
