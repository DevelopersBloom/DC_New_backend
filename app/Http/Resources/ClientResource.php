<?php
//
//namespace App\Http\Resources;
//
//use Illuminate\Http\Request;
//use Illuminate\Http\Resources\Json\JsonResource;
//
//class ClientResource extends JsonResource
//{
//    /**
//     * Transform the resource into an array.
//     *
//     * @param  Request  $request
//     * @return array
//     */
//    public function toArray($request): array
//    {
//        return [
//            'id' => $this->id,
//            'name' => $this->name,
//            'surname' => $this->surname,
//            'middle_name' => $this->middle_name,
//            'passport_series' => $this->passport_series,
//            'passport_validity' => $this->passport_validity,
//            'passport_issued' => $this->passport_issued,
//            'date_of_birth' => $this->date_of_birth,
//            'email' => $this->email,
//            'phone' => $this->phone,
//            'additional_phone' => $this->additional_phone,
//            'country' => $this->country,
//            'city' => $this->city,
//            'street' => $this->street,
//            'building' => $this->building,
//            'bank_name' => $this->bank_name,
//            'account_number' => $this->account_number,
//            'card_number' => $this->card_number,
//            'iban' => $this->iban,
//        ];
//    }
//}
// App/Http/Resources/ClientResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray($request)
    {
        $base = [
            'id'          => $this->id,
            'type'        => $this->type,
            'display_name'=> $this->display_name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'additional_phone' => $this->additional_phone,
            'country'     => $this->country,
            'city'        => $this->city,
            'street'      => $this->street,
            'building'    => $this->building,
            'website'     => $this->website,

            'bank_name'       => $this->bank_name,
            'account_number'  => $this->account_number,
            'card_number'     => $this->card_number,
            'iban'            => $this->iban,
            'swift_code'      => $this->swift_code,

            'has_contract' => (bool) $this->has_contract,
            'date'         => optional($this->date)->format('Y-m-d'),
        ];

        $individual = [
            'name'                => $this->name,
            'surname'             => $this->surname,
            'middle_name'         => $this->middle_name,
            'passport_series'     => $this->passport_series,
            'passport_validity'   => optional($this->passport_validity)->format('Y-m-d'),
            'passport_issued'     => $this->passport_issued,
            'date_of_birth'       => optional($this->date_of_birth)->format('Y-m-d'),
            'social_card_number'  => $this->social_card_number,
            'bank_client_id'      => $this->bank_client_id,
            'residency_status'    => $this->residency_status,
            'residency_country'   => $this->residency_country,
        ];

        $legal = [
            'company_name'         => $this->company_name,
            'legal_form'           => $this->legal_form,
            'tax_number'           => $this->tax_number,
            'state_register_number'=> $this->state_register_number,
            'activity_field'       => $this->activity_field,
            'director_name'        => $this->director_name,
            'accountant_info'      => $this->accountant_info,
            'internal_code'        => $this->internal_code,
        ];

        return array_merge(
            $base,
            $this->when($this->type === 'individual', $individual, []),
            $this->when($this->type === 'legal', $legal, []),
        );
    }
}
