<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Contract;
use App\Services\ContractService;
use App\Traits\ContractTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

class ItemImport implements ToCollection
{
    protected $contractService;
    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
    }

    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        foreach ($collection->skip(2) as $row) {
                $contract_num = $row[0];
                $contract = Contract::where('num',$contract_num)->first();

                if (!$contract) {
                    Log::error('It with ' . $contract_num . 'is not exist');
                    continue;
                }
            $category = Category::where('title', $row[1])->first();
            if (!$category) {
                Log::error('Category with title' . $row[2] . 'does not exist');
                continue;
            }
            $data = [
                'category_id' => $category->id,
                'subcategory' => $row[2],
                'model' => $row[3],
                'hallmark' => $row[4],
                'weight' => $row[5],
                'clear_weight' => $row[6],
                'car_make' => $row[7],
                'manufacture' => $row[8],
                'power' => $row[9],
                'license_plate' => $row[10],
                'color' => $row[11],
                'registration' => $row[12],
                'identification' => $row[13],
                'ownership' => $row[14],
                'issued_by' => $row[15],
                'date_of_issuance' => $row[16],
            ];
            $this->contractService->storeContractItem($contract->id, $data);
        }
    }
}
