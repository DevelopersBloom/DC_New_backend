<?php

namespace App\Imports;

use App\Services\ContractService;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class ContractImportNew implements ToCollection
{
    use ContractTrait, OrderTrait;
    protected $contractService;

    public function __construct(ContractService $contractService)
    {
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
            // Parse date fields
            $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[0]));
            $date_of_birth = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[11]));
            $passport_validity = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[6]));
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

            // Prepare client data
            $client_data = [
                'name' => $client_name,
                'surname' => $client_surname,
                'middle_name' => $client_middle_name,
                'passport_series' => $row[5],
                'passport_validity' => $passport_validity,
                'passport_issued' => $row[7],
                'country' => $row[8],
                'city' => $row[9],
                'street' => $row[10],
                'date_of_birth' => $date_of_birth,
                'email' => $row[12],
                'phone' => $phone,
                'additional_phone' => $additional_phone,
            ];

            // Store or update client
            $client = $this->clientsStoreOrUpdate($client_data);
            // Prepare contract data
            $contract_data = [
                'num' => $row[1],
                'estimated_amount' => $row[17],
                'provided_amount' => $row[18],
                'interest_rate' => $row[19],
                'penalty' =>$row[20],
                'lump_rate' =>floatval($row[21]),
                'description' => $row[24] ?? null,
                'pawnshop_id' => $pawnshop_id,
                'mother' => $row[17],
                'left' => $row[17]
            ];

            // Calculate contract deadline
            $deadline_days = $row[22];
            $deadline = (clone $date)->addDays($deadline_days);

            // Create contract
            $contract = $this->createContract($client->id, $contract_data, $deadline);
            // Determine payment type
            $cash = $contract->provided_amount < 20000;

            // Prepare full client name
            $client_fullname = $client->name . ' ' . $client->surname;
            if ($client->middle_name) {
                $client_fullname .= ' ' . $client->middle_name;
            }

            // Create order and history
            $this->createOrderAndHistory($contract, $client->id, $client_fullname, $cash, null, $contract_num, $pawnshop_id);
        }
    }
}
