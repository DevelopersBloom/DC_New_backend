<?php

namespace App\Imports;

use App\Models\Contract;
use App\Models\Deal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class ContractImport implements ToCollection
{
    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        foreach ($collection as $row) {
            if($row[0] !== 'ADB_ID'){
                $ADB_ID = $row[0];
                $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[1]));
                $person = preg_split('/\s+/', trim($row[3]));
                $name = null;
                $surname = null;
                $middle_name = null;
                $passport = null;
                $passport_given = null;
                $dob = null;
                $phone1 = null;
                $phone2 = null;
                $email = null;
                $fact = $row[4];
                $address = $row[5];
                $numbers = $row[6];
//                $numbers_arr = explode(',',$numbers);
                $numbers_arr = [];
                $worth = $row[7];
                $given = $row[8];
                $left = $given - $row[21];
                $rate = $row[9];
                $deadline_days = $row[10];
                $deadline = clone $date;
                $deadline = $deadline->addDays($deadline_days);
                $contract_closed = $row[11];
                $completed_info = $row[14];
                $penalty_rate = $row[15];
                $extended = $row[18] === '+';
                $status = 'initial';
                $one_time_payment = null;
                if($given >= 400000){
                    $one_time_payment = intval(round($given * 0.01 * 2 /10) * 10);
                }else{
                    $one_time_payment = intval(round($given * 0.01 * 2.5 /10) * 10);
                }
                if($contract_closed){
                    $status = 'completed';
                }
                if(trim($row[20])){
                    $status = 'executed';
                }
                if($status === 'executed' || $status === 'completed'){
                    $left = 0;
                }
                if(preg_match('#[a-zA-Z\.0-9-_]+@[a-zA-Z\.0-9-]+\.[a-z]+#', $completed_info, $matches, PREG_OFFSET_CAPTURE)) {
                    $email = $matches[0][0];
                }

                if(preg_match_all('#\d{3} \d{2} \d{2} \d{2}|\d{3}  \d{2} \d{2} \d{2}#', $numbers, $matches, PREG_OFFSET_CAPTURE)) {
                    $numbers_arr = $matches[0];
                }
                if(count($numbers_arr)){
                    $phone1 = trim($numbers_arr[0][0]);
                }
                if(count($numbers_arr) > 1){
                    $phone2 = trim($numbers_arr[1][0]);
                }


                if(count($person)){
                    $name = $person[0];
                }
                if(count($person) > 1){
                    $surname = $person[1];
                }
                if(count($person) > 2){
                    $middle_name = $person[2];
                }


                if($fact){
                    if(preg_match('#\d{2}\.\d{2}\.\d{4}#', $fact, $matches, PREG_OFFSET_CAPTURE)) {
                        $dob = $matches[0][0];
                    }elseif(preg_match('#\d{2},\d{2},\d{4}#', $fact, $matches, PREG_OFFSET_CAPTURE)) {
                        $dob = str_replace(',', '.', $matches[0][0]);
                    }
                    if(preg_match('#[a-zA-Z]{2}\d{7}#', str_replace(' ', '', $fact), $matches, PREG_OFFSET_CAPTURE)) {
                        $passport = $matches[0][0];
                    }elseif(preg_match('#[a-zA-Z]{2}\d{6}#', str_replace(' ', '', $fact), $matches, PREG_OFFSET_CAPTURE)) {
                        $passport = $matches[0][0];
                    }elseif (preg_match('#\d{9}#', str_replace(' ', '', $fact), $matches, PREG_OFFSET_CAPTURE)){
                        $passport = $matches[0][0];
                    }elseif (preg_match('#\d{8}#', str_replace(' ', '', $fact), $matches, PREG_OFFSET_CAPTURE)){
                        $passport = $matches[0][0];
                    }elseif(preg_match('#\d{2} \d{2} \d{6}#', $fact, $matches, PREG_OFFSET_CAPTURE)){
                        $passport = $matches[0][0];
                    }
                    $password_given_check = substr($fact,-3);
                    if(preg_match('#\d{3}#', $password_given_check, $matches, PREG_OFFSET_CAPTURE)) {
                        $passport_given = $password_given_check;
                    }
                }

                Contract::create([
                    'pawnshop_id' => 1,
                    'date' => $date->format('d.m.Y'),
                    'name' => $name,
                    'surname' => $surname,
                    'middle_name' => $middle_name,
                    'passport' => $passport,
                    'passport_given' => $passport_given,
                    'dob' => $dob,
                    'info' => $fact,
                    'address' => $address,
                    'phone1' => $phone1,
                    'phone2' => $phone2,
                    'worth' => $worth,
                    'given' => $given,
                    'rate' => $rate,
                    'deadline' => $deadline->format('d.m.Y'),
                    'status' => $status,
                    'ADB_ID' => $ADB_ID,
                    'penalty' => $penalty_rate,
                    'extended' => $extended,
                    'left' => $left,
                    'email' => $email,
                    'one_time_payment' => $one_time_payment
                ]);
            }
        }
    }
}
