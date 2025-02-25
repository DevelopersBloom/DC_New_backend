<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Contract;
use App\Services\ClientService;
use App\Services\ContractService;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

class ContractImportNew implements ToCollection
{
    use ContractTrait, OrderTrait;
    protected $contractService;
    protected $clientService;

    public function __construct(ClientService $clientService,ContractService $contractService)
    {
        $this->clientService = $clientService;
        $this->contractService = $contractService;
    }
    /**
     * Process the imported collection of contracts.
     *
     * @param Collection $collection
     */
    public function collection(Collection $collection)
    {
        foreach ($collection->skip(2) as $row) {
            $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[0]));
            $date_of_birth = null;
            if (trim($row[11]) != "՝") {
                $date_of_birth = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject(trim($row[11])))->format('Y-m-d');
            }
            //$passport_validity = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[6]));
//            $date_of_birth = null;
            $closed_at = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[23]));
            // Extract contract and client details
            $contract_num = $row[1];
            $pawnshop_id = $row[2];
            $order_id = $row[3];
            // Extract client details
            $client_info = preg_split('/\s+/', trim($row[4]));
            $client_name = $client_info[0] ?? null;
            $client_surname = $client_info[1] ?? null;
            $client_middle_name = $client_info[2] ?? null;

            // Parse phone numbers
            $phone_info = $row[13];
            $phone_arr = [];
            $additional_phone = null;

            if (preg_match_all('#\d{3} \d{2} \d{2} \d{2}|\d{3}  \d{2} \d{2} \d{2}#', $phone_info, $matches)) {
                $phone_arr = $matches[0];
            }

            $phone = count($phone_arr) > 0 ? trim($phone_arr[0]) : null;
            $additional_phone = count($phone_arr) > 1 ? trim($phone_arr[1]) : null;

//            $passport_data = $row[5];
            $passport_series = $row[5];
            $passport_issued = preg_replace('/\D/', '', $row[7]); // Removes non-numeric characters տրվ․
//            if($passport_data){
//                if(preg_match('#[a-zA-Z]{2}\d{7}#', str_replace(' ', '', $passport_data), $matches, PREG_OFFSET_CAPTURE)) {
//                    $passport_series = $matches[0][0];
//                }elseif(preg_match('#[a-zA-Z]{2}\d{6}#', str_replace(' ', '', $passport_data), $matches, PREG_OFFSET_CAPTURE)) {
//                    $passport_series = $matches[0][0];
//                }elseif (preg_match('#\d{9}#', str_replace(' ', '', $passport_data), $matches, PREG_OFFSET_CAPTURE)){
//                    $passport_series = $matches[0][0];
//                }elseif (preg_match('#\d{8}#', str_replace(' ', '', $passport_data), $matches, PREG_OFFSET_CAPTURE)){
//                    $passport_series = $matches[0][0];
//                }elseif(preg_match('#\d{2} \d{2} \d{6}#', $passport_data, $matches, PREG_OFFSET_CAPTURE)){
//                    $passport_series = $matches[0][0];
//                }
//                $password_given_check = substr($passport_data,-3);
//                if(preg_match('#\d{3}#', $password_given_check, $matches, PREG_OFFSET_CAPTURE)) {
//                    $passport_issued = $password_given_check;
//                }
//            }
            $client_data = [
                'name' => $client_name,
                'surname' => $client_surname,
                'middle_name' => $client_middle_name,
                'passport_series' => $passport_series,
             //   'passport_validity' => $passport_validity,
                'passport_issued' => $passport_issued,
                'country' => $row[8],
                'city' => $row[9],
                'street' => $row[10],
                'date_of_birth' => $date_of_birth,
                'email' => $row[12],
                'phone' => $phone,
                'additional_phone' => $additional_phone,
            ];
            // Store or update client
            $client = $this->clientService->storeOrUpdate($client_data);
            $deadline_days = $row[22];
            $category_name = isset($row[25]) ? mb_strtolower(trim($row[25])) : null;
            $category_id = null;
            if ($category_name) {
                $category = Category::whereRaw('LOWER(title) = ?', [$category_name])->first();
                if ($category) {
                    $category_id = $category->id;
                }
            }

            // Prepare contract data
            $contract_data = [
                'date' => $date,
                'num' => $row[1],
                'estimated_amount' => $row[17],
                'provided_amount' => $row[18],
                'interest_rate' => $row[19],
                'penalty' =>$row[20],
                'lump_rate' =>floatval($row[21]),
                'description' => $row[24] ?? null,
                'pawnshop_id' => $pawnshop_id,
                'mother' => $row[17],
                'left' => $row[17],
                'deadline' => $deadline_days,
                'category_id' => $category_id
            ];
            // Calculate contract deadline
            $deadline = (clone $date)->addDays($deadline_days);
            // Create contract
            $contract = $this->contractService->createContract($client->id, $contract_data, $deadline);
            $cash = $contract->provided_amount < 20000;
            // Prepare full client name
            $client_fullname = $client->name . ' ' . $client->surname;
            if ($client->middle_name) {
                $client_fullname .= ' ' . $client->middle_name;
            }

            // Create order and history
            $this->createOrderAndHistory($contract, $client->id, $client_fullname, $cash, null, $contract_num, 1,$date,true);
        }
    }
}
