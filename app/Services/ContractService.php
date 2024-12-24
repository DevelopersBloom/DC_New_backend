<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRate;
use App\Models\Contract;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Subcategory;
use App\Models\SubcategoryItem;
use Carbon\Carbon;
use function Symfony\Component\String\b;

class   ContractService
{
    public function storeContractItem(int $contract_id,array $data)
    {
        $item = new Item();
        $item->category_id = $data['category_id'];
        $item->contract_id = $contract_id;
        $category = Category::findOrFail($data['category_id']);
        switch ($category->name)
        {
            case 'electronics':
                $subcategory = Subcategory::firstOrCreate(
                    [
                        'name'        => $data['subcategory'],
                        'category_id' => $data['category_id'],
                    ]
                );

                if ($data['model']) {
                    $subcategoryItem = SubcategoryItem::firstOrCreate([
                        'subcategory_id' => $subcategory->id,
                        'model' => $data['model'],
                    ]);
                    $item->model = $subcategoryItem->model;
                }
                $item->subcategory =  $subcategory->name;
                break;
            case 'gold':
                $subcategory = Subcategory::firstOrCreate(
                    [
                        'name' => $data['subcategory'],
                        'category_id' => $data['category_id']
                    ]
                );
                $item->subcategory =  $subcategory->name;
                $item->weight = $data['weight'] ?? null;
                $item->clear_weight = $data['clear_weight'] ?? null;
                $item->hallmark = $data['hallmark'] ?? null;
                break;
            case 'car':
                $subcategory = Subcategory::firstOrCreate(
                    [
                        'name'        => $data['model'],
                        'category_id' => $data['category_id'],
                    ]
                );
                if ($data['car_make']) {
                    $subcategoryItem = SubcategoryItem::firstOrCreate([
                        'subcategory_id' => $subcategory->id,
                        'model' => $data['car_make'],
                    ]);
                    $item->car_make = $subcategoryItem->model;
                }
                $item->model = $subcategory->name ?? null;
                $item->manufacture = $data['manufacture'] ?? null;
                $item->power = $data['power'] ?? null;
                $item->license_plate = $data['license_plate'] ?? null;
                $item->color = $data['color']?? null;
                $item->registration = $data['registration_certificate'] ?? null;
                $item->identification = $data['identification_number'] ?? null;
                $item->ownership = $data['ownership_certificate']?? null;
                $item->issued_by = $data['issued_by']?? null;
                $item->date_of_issuance = $data['date_of_issuance'] ?? null;
                break;
        }
        $item->save();
        return $item;
    }
    public function createContract(int $client_id, array $data,$deadline)
    {
        $contract = new Contract();
        $contract->client_id = $client_id;
        $contract->estimated_amount = $data['estimated_amount'];
        $contract->provided_amount = $data['provided_amount'];
        $contract->left = $data['provided_amount'];
        $contract->mother = $data['provided_amount'];
        $contract->interest_rate = $data['interest_rate'];
        $contract->penalty = $data['penalty'];
        $contract->deadline = $deadline;
        $contract->lump_rate = $data['lump_rate'];
        $contract->description = $data['description'] ?? null;
        $contract->status = 'initial';
        $contract->pawnshop_id = auth()->user()->pawnshop_id ?? $data['pawnshop_id'];
        $contract->save();
        return $contract;

    }
    public function createPayment(Contract $contract,$import_date = null,$import_pawnshop_id = null)
    {
       $fromDate = $import_date ? Carbon::parse($import_date)->setTimezone('Asia/Yerevan') : Carbon::parse($contract->created_at)->setTimezone('Asia/Yerevan');
       $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
        $toDate = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan');
        $currentDate = $fromDate;
        $pgi_id = 1;
        while ($currentDate->lt($toDate))
        {
            $payment = [
                'contract_id' => $contract->id,
                'from_date' => $currentDate->format('d.m.Y'),
            ];

            // Determine the next payment date, or use the deadline if it's the last payment
            $nextPaymentDate = (clone $currentDate)->addMonths();
            $paymentDate  = $nextPaymentDate->lt($toDate) ? $nextPaymentDate : $toDate;

            $diffDays = $paymentDate->diffInDays($currentDate);
            $amount = $this->calcAmount($contract->provided_amount, $diffDays, $contract->interest_rate);
            $payment['date'] = $paymentDate->format('Y-m-d');            $payment['days'] = $diffDays;
            $payment['amount'] = $amount;
            $payment['pawnshop_id'] = $pawnshop_id;
            $payment['mother'] = 0;
            $payment['PGI_ID'] = $pgi_id;

            // Check if it's the last payment
            if ($paymentDate->eq($toDate)) {
                $payment['mother'] = $contract->provided_amount; // Add mother amount for the last payment
                $payment['last_payment'] = true;
            }

            Payment::create($payment);
            $pgi_id++;
            // Move to the next payment date
            $currentDate = $nextPaymentDate;
        }
    }

//    public function createPayment(Contract $contract)
//    {
//        $fromDate = Carbon::parse($contract->created_at);
//        $toDate = Carbon::parse($contract->deadline);
//        $currentDate = $fromDate;
//
//        while ($currentDate->lt($toDate))
//        {
//            $payment = [
//                'contract_id' => $contract->id,
//                'from_date' => $fromDate,
//            ];
//            $nextPaymentDate = (clone $currentDate)->addMonths();
//            $paymentDate  = $nextPaymentDate->lt($toDate) ? $nextPaymentDate : $toDate;
//            $diffDays = $paymentDate->diffInDays($currentDate);
//            $amount = $this->calcAmount($contract->provided_amount,$diffDays,$contract->interest_rate);
//            $payment['date'] =  $paymentDate->format('d.m.Y');;
//            $payment['days'] = $diffDays;
//            $payment['amount'] = $amount;
//            $payment['pawnshop_id'] = auth()->user()->pawnshop_id;
//
//            Payment::create($payment);
//
//            $currentDate = $nextPaymentDate;
//        }
//    }

    public function calcAmount($amount,$days,$rate): int
    {
        return intval(ceil($days * $rate * $amount * 0.01 /10) * 10);
    }
}
