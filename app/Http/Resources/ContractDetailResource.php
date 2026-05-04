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
                'loan_type'        => $this->payment_type,
                'estimated_amount' => $this->estimated_amount,
                'provided_amount'  => $this->provided_amount,
                'contract_amount'  => $this->contract_amount,
                'interest_rate'    => $this->interest_rate,
                'interest_annual_rate' => $this->interest_rate * 365,
                'effective_annual_rate' => $this->effective_annual_rate,
                'fee_annual_rate'  => $this->fee_annual_rate,
                'penalty'          => $this->penalty,
                'lump_rate'        => $this->lump_rate,
                'description'      => $this->description,
                'status'           => $this->status,
                'effective_rate'    => $this->effective_daily_rate,
                'kasko_amount'     => $this->kasko_amount,
                'effective_rate_kasko' => $this->effective_rate_kasko,
                'effective_annual_rate_kasko' => $this->effective_annual_rate_kasko,
                'date' => Carbon::parse($this->date)->format('d-m-Y'),
                'interest_end'=> $maturityDate,
                'interest_start'    => $interestStartDate,
                'unearned_interest' => $this->unearned_interest, //չվաստակած
                'written_off_amount' => $this->written_off_amount,//դուրս գրված
                'written_off_interest' => $this->written_off_interest,
                'overdue_amount' => $this->overdue_amount, //ժամկետնանց գումար
                'overdue_interest' => $this->overdue_interest, //Ժամկետանց տոկոս
                'overdue_amount_interest' => $this->overdue_amount_interest, //Ժամկետանց գումարի տոկոս
                'calculatedEffectiveInterest' => $this->calculatedEffectiveInterest,
                'calculatedInterest' => $this->calculatedInterest,
                'risk_weight' => $this->risk_weight,
                'reserve' => $this->reserve,
                'days_provided' => $this->days_provided,
                'remaining_repayment_days' => $this->remaining_repayment_days,
                'deadline' => Carbon::parse($this->deadline)->format('d-m-Y'),
                'daily_interest_sum' => $this->daily_interest_sum,
                'daily_effective_sum' => $this->daily_effective_sum,
                'interest_amount' => $this->interest_amount,

            ],
            'client' => [
                'id'                => $this->client->id,
                'name'              => $this->client->name,
                'surname'           => $this->client->surname,
                'middle_name'       => $this->client->middle_name,
                'country'           => $this->client->country ?? $this->client->actual_country,
                'city'              => $this->client->city ?? $this->client->actual_community,
                'street'            => $this->client->street ?? $this->client->actual_street_building,
                'building'          => $this->client->building,
                'phone'             => $this->client->phone,
                'additional_phone'  => $this->client->additional_phone,
                'email'             => $this->client->email,
                'date_of_birth'     => Carbon::parse($this->client->date_of_birth)->format('d-m-Y'),
                'passport_series'   => $this->client->passport_series,
                'passport_validity' => Carbon::parse($this->client->passport_validity)->format('d-m-Y'),
                'passport_issued'   => $this->client->passport_issued,
                'is_linked_to_company' => (bool)$this->client->is_linked_to_company,
                'is_company_employee' => (bool)$this->client->is_company_employee,
                'classification' => $this->client->classification?->title,
                'reserve_percent' => $this->client->classification?->reserve_percent,
                'risk_weight_percent' => $this->client->classification?->risk_weight,
                'bank_client_id' => $this->client->bank_client_id,
                'social_card_number' => $this->client->social_card_number,
                'tax_number' => $this->client->tax_number,
                'residency_status' => $this->client->residency_status,
                'type' => $this->client->type,
                'gender' => $this->client->gender,
            ],
            'guarantors' => $this->guarantors->map(function ($guarantor) {
                return [
                    'id' => $guarantor->id,
                    'name' => $guarantor->name,
                    'surname' => $guarantor->surname,
                    'middle_name' => $guarantor->middle_name,
                    'phone' => $guarantor->phone,
                    'passport_series' => $guarantor->passport_series,
                ];
            }),
            'seller' => $this->seller ? [
                'id'              => $this->seller->id,
                'name'            => $this->seller->name,
                'surname'         => $this->seller->surname,
            ] : null,

            'payments' => $this->payments->map(function ($payment) {
                return [
                    'id'      => $payment->id,
                    'amount'  => $payment->amount,
                    'original_amount' => $payment->original_amount,
                    'paid'    => $payment->paid,
                    'date'    => Carbon::parse($payment->date)->format('d-m-Y'),
                    'to_date'    => Carbon::parse($payment->to_date)->format('d-m-Y'),
                    'principal_payment' => $payment->principal_payment,
                    'original_principal_payment' => $payment->original_principal_payment,
                    'interest_payment' => $payment->interest_payment,
                    'original_interest_payment' => $payment->original_interest_payment,
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
                    'date' => Carbon::parse($history->date)->format('d-m-Y'),
                    'interest_amount' => $history->interest_amount ?? 0,
                    'penalty_amount' => $history->penalty ?? 0,
                    'discount' => $history->discount ?? 0,
                    'delay_days' => $history->delay_days ?? 0,
                    'total' => $history->amount,
                    'order' => [
                        'id' => $history->order->id ?? null,
                        'amount' => $history->order->amount ?? null,
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
                    $re = $item->realEstate;
                    return [
                        'id'                         => $item->id,
                        'category'                   => $item->category->title,
                        'description'                => $item->description,
                        'rated'                      => $item->provided_amount,
                        'certificate_number'         => $re?->certificate_number,
                        'certificate_password'       => $re?->certificate_password,
                        'cadastral_code'             => $re?->cadastral_code,
                        'area_sqm'                   => $re?->area_sqm,
                        'appraiser_company'          => $re?->appraiser_company,
                        'appraisal_report_number'    => $re?->appraisal_report_number,
                        'appraisal_date'             => $re?->appraisal_date?->format('d-m-Y'),
                        'appraised_value'            => $re?->appraised_value,
                        'unified_reference_number'   => $re?->unified_reference_number,
                        'unified_reference_password' => $re?->unified_reference_password,
                        'is_joint'                   => $re?->is_joint ?? false,
                    ];
                } elseif ($item->category->name === 'gold') {
                    return [
                        'id'          => $item->id,
                        'category'    => $item->category->title,
                        'subcategory' => $item->subcategory,
                        'weight'      => $item->weight,
                        'clear_weight'=> $item->clear_weight,
                        'hallmark'    => $item->hallmark,
                        'description' => $item->description,
                        'rated'       => $item->provided_amount,
                    ];
                } elseif ($item->category->name === 'car') {
                    return [
                        'id'              => $item->id,
                        'category'        => $item->category->title,
                        'car_make'        => $item->car_make,
                        'model'           => $item->model,
                        'manufacture'     => $item->manufacture,
                        'power'           => $item->power,
                        'license_plate'   => $item->license_plate,
                        'color'           => $item->color,
                        'registration'    => $item->registration,
                        'identification'  => $item->identification,
                        'ownership'       => $item->ownership,
                        'issued_by'       => $item->issued_by,
                        'date_of_issuance'=> $item->date_of_issuance,
                        'description'     => $item->description,
                        'rated'           => $item->provided_amount,
                    ];
                } else {
                    return [
                        'id'          => $item->id,
                        'category'    => $item->category->title,
                        'subcategory' => $item->subcategory,
                        'model'       => $item->model,
                        'description' => $item->description,
                    ];
                }
            }),
            'current_payment_amount' => $this->current_payment_amount,
            'penalty_amount' => $this->penalty_amount,
            'future_interest_discount' => $this->future_interest_discount ?? 0,
        ];
    }
}
