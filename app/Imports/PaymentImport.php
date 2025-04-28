<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class PaymentImport implements ToCollection
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        $goldId = Category::where('name','gold')->first()->id;
        $phoneId = Category::where('name','phone')->first()->id;
        $laptopId = Category::where('name','laptop')->first()->id;
        $tabletId = Category::where('name','tablet')->first()->id;
        $carId = Category::where('name','car')->first()->id;
        $tvId = Category::where('name','tv')->first()->id;
        $pcId = Category::where('name','pc')->first()->id;
        foreach ($collection as $row) {
            if($row[0] !== 'PGI_ID'){
                $ADB_ID = $row[1];
                if ($ADB_ID == 17023) dd(1);
                $contract = Contract::where('ADB_ID', $ADB_ID)->first();
                if($contract){
                    $PGI_ID = $row[0];
                    $regular_number = $row[2];
                    $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[3]));
                    $from_date = null;
                    $amount = $row[4];
                    $penalty = $row[6];
                    $status = $row[5] ? 'completed' : 'initial';
                    $description = $row[11];
                    $gold_type = $row[12];
                    $gold_weight = $row[13];
                    $extended = $row[10];
                    if($extended){
                        $contract->extended = true;
                        $contract->deadline = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($extended))->format('d.m.Y');
                    }
                    if($description !== '`'){
                        $sign = $contract->comment ? ', ' : '';
                        $contract->comment = $contract->comment.$sign.$description;
                    }
                    if($row[5]){
                        $contract->collected = $contract->collected + $amount;
                    }
                    if($gold_type !== '`'){
                        $contract->category_id = $goldId;
                        $contract->items()->create([
                            'description' => $description,
                            'type'=> $gold_type,
                            'weight' => $gold_weight,
                            'category_id' => $goldId
                        ]);
                    }elseif(strpos($description,'Հեռախոս') !== false){
                        $contract->category_id = $phoneId;
                        $contract->items()->create([
                            'description' => $description,
                            'category_id' => $phoneId
                        ]);
                    }elseif(strpos($description,'Նոթբուք') !== false || strpos($description,'Նոութբուք') !== false){
                        $contract->category_id = $laptopId;
                        $contract->items()->create([
                            'description' => $description,
                            'category_id' => $laptopId
                        ]);
                    }elseif(strpos($description,'Պլանշետ') !== false){
                        $contract->category_id = $tabletId;
                        $contract->items()->create([
                            'description' => $description,
                            'category_id' => $tabletId
                        ]);
                    }elseif(strpos($description,'Ավտոմեքենա') !== false){
                        $contract->category_id = $carId;
                        $contract->items()->create([
                            'description' => $description,
                            'category_id' => $carId
                        ]);
                    }elseif(strpos($description,'Հեռուստացույց') !== false){
                        $contract->category_id = $tvId;
                        $contract->items()->create([
                            'description' => $description,
                            'category_id' => $tvId
                        ]);
                    }elseif(strpos($description,'Համակարգիչ') !== false){
                        $contract->category_id = $pcId;
                        $contract->items()->create([
                            'description' => $description,
                            'category_id' => $pcId
                        ]);
                    }
                    if($regular_number === 'ՄԳ'){
                        $lastPayment = Payment::where('contract_id',$contract->id)->where('type','regular')->orderBy('id','DESC')->first();
                        if($lastPayment){
                            $lastPayment->update([
                                'last_payment' => true,
                                'mother' => $amount
                            ]);
                        }
                            if($status === 'completed'){
                                $lastPayment->update([
                                    'paid' => $lastPayment->paid + $amount
                                ]);
                                $contract->close_date = $date->format('d.m.Y');
                            }
                    }elseif ($regular_number === '`' && $amount){
                        $contract->payments()->create([
                            'status' => 'completed',
                            'amount' => $amount,
                            'paid' => $amount,
                            'PGI_ID' => $PGI_ID,
                            'date' => $date->format('d.m.Y'),
                            'pawnshop_id' => 1,
                            'type' => 'partial'
                        ]);
                    }
                    else{
                        if($status === 'initial'){
                            $from_date = clone $date;
                            $from_date = $from_date->subMonth()->format('d.m.Y');
                        }
                        $paid = null;
                        if($status === 'completed'){
                            $paid = $amount;
                        }
                        $penaltyPaid = 0;
                        if($status === 'completed'){
                            $penaltyPaid = $penalty;
                        }
                        $contract->payments()->create([
                            'status' => $status,
                            'amount' => $amount,
                            'paid' => $paid,
                            'PGI_ID' => $PGI_ID,
                            'date' => $date->format('d.m.Y'),
                            'pawnshop_id' => 1,
                            'from_date' => $from_date,
                            'penalty' => $penaltyPaid
                        ]);
                    }
                    if($penalty && $status === 'completed'){
                        $contract->payments()->create([
                            'status' => $status,
                            'amount' => $penalty,
                            'paid' => $penalty,
                            'date' => $date->format('d.m.Y'),
                            'type' => 'penalty',
                            'pawnshop_id' => 1
                        ]);
                    }
                    $contract->save();
                }
            }
        }
    }
}
