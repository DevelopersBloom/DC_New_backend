<?php

namespace App\Imports;

use App\Models\Contract;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;
use App\Services\ClientService;

class ContractsImportNewData implements ToCollection, WithHeadingRow
{
    protected $clientService;

    public function __construct(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
dd($row);
            $date = $this->parseDate($row['պայմանագրի_ամսաթիվ'] ?? null);

            // Ծննդյան օրվա մշակում
            $date_of_birth = $this->parseDate($row['ծննդյան_օր'] ?? null, true);

            // Փակման ամսաթվի մշակում
            $closed_at = $this->parseDate($row['փակման_ամսաթիվ'] ?? null);

            // Անուն Ազգանուն Հայրանուն բաժանում
            $client_info = preg_split('/\s+/', trim($row['անուն_ազգանուն_հայրանուն'] ?? ''));
            $client_name = $client_info[0] ?? null;
            $client_surname = $client_info[1] ?? null;
            $client_middle_name = $client_info[2] ?? null;

            // Հեռախոսների մշակում
            $phones = array_map('trim', explode(',', $row['հեռ․_համար'] ?? ''));
            $phone = isset($phones[0]) ? "(+374) " . $phones[0] : null;
            $additional_phone = isset($phones[1]) ? "(+374) " . $phones[1] : null;

            // Կլիենտի տվյալների array
            $client_data = [
                'name'             => $client_name,
                'surname'          => $client_surname,
                'middle_name'      => $client_middle_name,
                'passport_series'  => $row['անձնագրի_սերիա'] ?? null,
                'passport_validity'=> $row['անձնագրի_վավերականություն'] ?? null,
                'passport_issued'  => preg_replace('/\D/', '', $row['տրված'] ?? null),
                'country'          => $row['երկիր'] ?? null,
                'city'             => $row['քաղաք'] ?? null,
                'street'           => $row['փողոց/շենք'] ?? null,
                'date_of_birth'    => $date_of_birth,
                'email'            => $row['մեյլ'] ?? null,
                'phone'            => $phone,
                'additional_phone' => $additional_phone,
                'bank_name'        => $row['բանկ'] ?? null,
                'card_number'      => $row['քարտի_համար'] ?? null,
                'account_number'   => $row['հաշվ_համար'] ?? null,
                'date'             => $date
            ];

            // Ստեղծել կամ թարմացնել կլիենտին
            $client = $this->clientService->storeOrUpdate($client_data);

            // Պայմանագրի ստեղծում
            Contract::create([
                'date'             => $date,
                'num'              => $row['պայմ․_համար'] ?? null,
                'pawnshop_id'      => $row['գրավատան_համար'] ?? null,
                'estimated_amount' => $row['գնահատված'] ?? 0,
                'provided_amount'  => $row['տրամադրված'] ?? 0,
                'interest_rate'    => $row['տոկոսադրույք'] ?? 0,
                'penalty'          => $row['տուգանք'] ?? 0,
                'lump_rate'        => $row['միանվագ'] ?? 0,
                'deadline_days'    => $row['օրեր'] ?? 0,
                'closed_at'        => $closed_at,
                'description'      => $row['նկարագրություն'] ?? null,
                'category_id'      => null, // կարող ես category lookup անել
                'status'           => $this->mapStatus($row['կարգավիճակ'] ?? ''),
                'mother'           => $row['մայր_գումար'] ?? 0,
                'left'             => $row['մնացել_է'] ?? 0,
                'collected_amount' => $row['հավաքվել_է'] ?? 0,
                'client_id'        => $client->id,
            ]);
        }
    }

    private function parseDate($raw, $formatToString = false)
    {
        if (!$raw || trim($raw) === '՝') {
            return null;
        }

        try {
            $raw = str_replace(['.', '․'], '/', trim($raw));
            if (is_numeric($raw)) {
                $date = Carbon::parse(ExcelDate::excelToDateTimeObject($raw));
            } else {
                $date = Carbon::createFromFormat('d/m/Y', $raw);
            }
            return $formatToString ? $date->format('Y-m-d') : $date;
        } catch (\Exception $e) {
            Log::error('Date parse error', [
                'raw_value' => $raw,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function mapStatus($statusText)
    {
        return match ($statusText) {
            'Բաց'      => 'initial',
            'Փակված'   => 'completed',
            'Իրացված'  => 'executed',
            default    => 'initial',
        };
    }
}
