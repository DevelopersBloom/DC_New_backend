<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Item;

class   ContractService
{
    public function storeContractItem(int $contract_id,array $data)
    {
        $item = new Item();
        $item->category_id = $data['category_id'];
        $item->contract_id = $contract_id;
        $item->subcategory = $data['subcategory'];
        $item->model = $data['model'] ?? null;

        $item->save();
        return $item;
    }
    public function createContract(int $client_id, array $data)
    {
        $contract = new Contract();
        $contract->client_id = $client_id;
        $contract->estimated_amount = $data['estimated_amount'];
        $contract->provided_amount = $data['provided_amount'];
        $contract->interest_rate = $data['interest_rate'];
        $contract->penalty = $data['penalty'];
        $contract->deadline = $data['deadline'];
        $contract->lump_sum = $data['lump_sum'] ?? null;
        $contract->description = $data['description'] ?? null;
        $contract->status = 'initial';
        $contract->pawnshop_id  = $data['pawnshop_id'];
        $contract->save();
        return $contract;

    }

}
