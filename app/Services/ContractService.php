<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Contract;
use App\Models\Item;
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
            case 'phone':
                $item->subcategory = $data['subcategory'];
                $item->model = $data['model'] ?? null;
                break;
            case 'gold':
                $item->subcategory = $data['subcategory'];
                $item->weight = $data['weight'];
                $item->clear_weight = $data['clear_weight'];
                $item->hallmark = $data['hallmark'];
                break;
            case 'car':
                $item->model = $data['model'];
                $item->car_make = $data['car_make'];
                $item->manufacture = $data['manufacture'] ;
                $item->power = $data['power'] ;
                $item->license_plate = $data['license_plate'] ;
                $item->color = $data['color'];
                $item->registration_certificate = $data['registration_certificate'] ;
                $item->identification_number = $data['identification_number'] ;
                $item->ownership_certificate = $data['ownership_certificate'];
                $item->issued_by = $data['issued_by'];
                $item->date_of_issuance = $data['date_of_issuance'] ;
                break;
        }
        $item->save();
        return $item;
    }
    public function createContract(int $client_id, array $data)
    {
        $contract = new Contract();
        $contract->client_id = $client_id;
        $contract->estimated_amount = $data['estimated_amount'];
        $contract->provided_amount = $data['provided_amount'];
        $contract->interest_rate =1;
        $contract->penalty = 2;
        $contract->deadline = $data['deadline'];
        $contract->lump_sum = 5;
        $contract->description = $data['description'] ?? null;
        $contract->status = 'initial';
        $contract->pawnshop_id = auth()->user()->pawnshop_id;
        $contract->save();
        return $contract;

    }
}
