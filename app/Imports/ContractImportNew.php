<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Services\ClientService;
use App\Services\ContractService;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use PhpOffice\PhpSpreadsheet\Shared\Date;

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
          //  $date = Carbon::parse(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[0]));
            if (isset($row[0]) && !empty($row[0])) {
                $rawDate = trim($row[0]);
                try {
                    if (is_numeric($rawDate)) {
                        $date = Carbon::parse(Date::excelToDateTimeObject($rawDate));
                    } else {
                        $rawDate = str_replace(['.', '․'], '/', $rawDate); // fix both dot types
                        $date = Carbon::createFromFormat('d/m/Y', $rawDate);
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing main date', [
                        'raw_value' => $rawDate,
                        'error' => $e->getMessage(),
                    ]);
                    dd("Invalid date value: ", $rawDate);
                }
            }

            $date_of_birth = null;
            if (isset($row[11]) && !empty($row[11]) && trim($row[11]) !== '՝') {
                $rawValue = trim($row[11]);

                try {
                    // Replace normal dot and Armenian full stop
                    $rawValue = str_replace(['.', '․'], '/', $rawValue);

                    if (is_numeric($rawValue)) {
                        $date_of_birth = Carbon::parse(Date::excelToDateTimeObject($rawValue))->format('Y-m-d');
                    } else {
                        $date_of_birth = Carbon::createFromFormat('d/m/Y', $rawValue)->format('Y-m-d');
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing date', [
                        'raw_value' => $rawValue,
                        'error' => $e->getMessage(),
                    ]);
                    dd("Invalid date format: ", $rawValue);
                }
            }

            $closed_at = null;
            if (isset($row[23]) && !empty($row[23])) {
                $rawClosedAt = trim($row[23]);

                try {
                    // Replace normal dot and Armenian full stop
                    $rawClosedAt = str_replace(['.', '․'], '/', $rawClosedAt);

                    if (is_numeric($rawClosedAt)) {
                        $closed_at = Carbon::parse(Date::excelToDateTimeObject($rawClosedAt));
                    } else {
                        $closed_at = Carbon::createFromFormat('d/m/Y', $rawClosedAt);
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing closed_at date', [
                        'raw_value' => $rawClosedAt,
                        'error' => $e->getMessage(),
                    ]);
                    dd("Invalid closed_at value: ", $rawClosedAt);
                }

            }

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
            $phones = array_map('trim', explode(',', $phone_info));

            foreach ($phones as $phone) {
                if (preg_match('#^\d{2,3}\s+\d{2}\s+\d{2}\s+\d{2}$#', $phone)) {
                    $phone_arr[] = $phone;
                }
            }

            $phone = count($phone_arr) > 0 ? "(+374) " . trim($phone_arr[0]) : null;
            $additional_phone = count($phone_arr) > 1 ? "(+374) "  . trim($phone_arr[1]) : null;

            $passport_series = $row[5];
            $passport_issued = preg_replace('/\D/', '', $row[7]);

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
                'date' => $date
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
                'mother' => $row[18],
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
            $deal_id = $this->createOrderAndHistory($contract, $client->id, $client_fullname, $cash, null, $contract_num, 1,$date,true);

            ContractAmountHistory::create([
                'contract_id' => $contract->id,
                'amount' =>  $row[17],
                'amount_type' => 'estimated_amount',
                'type' => 'in',
                'date' => $contract->date,
                'deal_id' => $deal_id,
                'category_id' => $category_id,
                'pawnshop_id' => auth()->user()->pawnshop_id ?? 1
            ]);
            ContractAmountHistory::create([
                'contract_id' => $contract->id,
                'amount' => $row[18],
                'amount_type' => 'provided_amount',
                'type' => 'in',
                'date' => $contract->date,
                'deal_id' => $deal_id,
                'category_id' => $category_id,
                'pawnshop_id' => auth()->user()->pawnshop_id ?? 1


            ]);
        }
    }
}
