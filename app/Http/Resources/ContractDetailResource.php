<?php

namespace App\Http\Resources;

use App\Models\HistoryType;
use Carbon\Carbon;
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
        $maturityDate = $this->payments_to_date_max
            ? Carbon::parse($this->payments_to_date_max)->format('d-m-Y')
            : null;
        $interestStartDate = $this->payments_to_date_min
            ? Carbon::parse($this->payments_to_date_min)->format('d-m-Y')
            : null;

        return [
            'contract' => [
                'id'               => $this->id,
                'num'              => $this->num,
                'estimated_amount' => $this->estimated_amount,
                'provided_amount'  => $this->provided_amount,
                'contract_amount'  => $this->contract_amount,
                'interest_rate'    => $this->interest_rate,
                'effective_rate'    => $this->effective_rate,
                'penalty'          => $this->penalty,
                'lump_rate'        => $this->lump_rate,
                'description'      => $this->description,
                'status'           => $this->status,
                'effectiveRate'    => $this->effectiveRate,
                'interest_end'=> $maturityDate,
                'interest_start'    => $interestStartDate,
                'unearned_interest' => $this->unearned_interest,
                'written_off_amount' => $this->written_off_amount,
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
                'date_of_birth'     => Carbon::parse($this->client->date_of_birth)->format('d-m-Y'),
                'passport_series'   => $this->client->passport_series,
                'passport_validity' => $this->client->passport_validity,
                'passport_issued'   => $this->client->passport_issued,
                'is_linked_to_company' => (bool)$this->client->is_linked_to_company,
                'is_company_employee' => (bool)$this->client->is_company_employee,
                'classification' => $this->classification?->name,
            ],
            'payments' => $this->payments->map(function ($payment) {
                return [
                    'id'      => $payment->id,
                    'amount'  => $payment->amount,
                    'paid'    => $payment->paid,
                    'date'    => Carbon::parse($payment->date)->format('d-m-Y'),
                    'principal_payment' => $payment->principal_payment,
                    'interest_payment' => $payment->interest_payment,
                    'remaining' => $payment->remaining,
                    'status'  => $payment->status,
                    'type'    => $payment->type,
                    'mother'  => $payment->mother,
                    'discount_amount' => $payment->discount_amount ?? 0
                ];
            }),
            'history' => $this->history->map(function ($history) {
                return [
                    'id'   => $history->id,
                    'type' => $history->type->title,
//                    'date' => $history->date,
                    'date' => Carbon::parse($history->date)->format('d-m-Y'),
                    'interest_amount' => $history->interest_amount ?? 0,
                    'penalty_amount' => $history->penalty ?? 0,
                    'discount' => $history->discount ?? 0,
                    'delay_days' => $history->delay_days ?? 0,
                    'total' => $history->amount,
//                    'total' => $history->interest_amount + $history->penalty + $history->discount,
                   // 'total' => $history->order->amount,
//
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
                       // 'status' => $history->order->status ?? null,
//                        'created_at' => $history->order->date ?? null,
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
                if ($item->category->name === 'electronics') {
                    return [
                        'id' => $item->id,
                        'category' => $item->category->title,
                        'subcategory' => $item->subcategory,
                        'model' => $item->model,
                        'description' => $this->description,
                        'sn' => $item->sn,
                        'imei' => $item->imei,
                        'rated' => $item->provided_amount,
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
                        'rated' => $item->provided_amount,
                    ];
                } elseif ($item->category->name === 'car') {
                    return [
                        'id' => $item->id,
                        'category' => $item->category->title,
                        'car_make' => $item->car_make,
                        'model' => $item->model,
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
                        'rated' => $item->provided_amount,
                    ];
                } else {
                    return [
                        'id' => $item->id,
                        'category' => $item->category->title,
                        'subcategory' => $item->subcategory,
                        'model' => $item->model,
                        'description' => $item->description
                    ];
                }
            }),
            'current_payment_amount' => $this->current_payment_amount,
            'penalty_amount' => $this->penalty_amount,
        ];
    }
}
