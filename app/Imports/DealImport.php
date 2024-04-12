<?php

namespace App\Imports;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class DealImport implements ToCollection
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        $minDate = Carbon::parse('2009-12-31');
        foreach ($collection as $row){
            if($row[0] !== 'Cash_ID'){
                $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[1]));
                $receiver = null;
                if($row[3] !== '`'){
                    $receiver = $row[3];
                }
                $regular_out = $row[4];
                $purpose = null;
                $contract_id = null;
                if($row[5] !== '`'){
                    $purpose = $row[5];
                }
                $ADB_ID = $row[6];
                $PGI_ID = $row[7];
                $in = $row[8];
                $out = $row[9];
                $insurance = $row[10];
                $funds = $row[12];
                $type = null;
                $amount = null;
                if($regular_out){
                    $type = 'regular_out';
                    $amount = $regular_out;
                }elseif ($in){
                    $type = 'in';
                    $amount = $in;
                }elseif ($out){
                    $type = 'out';
                    $amount = $out;
                }
                if(($regular_out || $in || $out) && $date->gt($minDate)){
                    if($ADB_ID){
                        $contract = Contract::where('ADB_ID',$ADB_ID)->first();
                        if($contract){
                            $contract_id = $contract->id;
                        }
                    }
                    Deal::create([
                        'type' => $type,
                        'amount' => $amount,
                        'date' => $date->format('d.m.Y'),
                        'insurance' => $insurance,
                        'funds' => $funds,
                        'pawnshop_id' => 1,
                        'purpose' => $purpose,
                        'contract_id' => $contract_id,
                        'receiver' => $receiver
                    ]);
                }
            }
        }
    }
}
