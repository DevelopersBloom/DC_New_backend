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

            // Պայմանագրի ամսաթվի մշակում
            $date = $this->parseDate($row['paymanagri_amsathiv'] ?? null);

            // Ծննդյան օրվա մշակում
            $date_of_birth = $this->parseDate($row['tsnndyan_or'] ?? null, true);

            // Փակման ամսաթվի մշակում
            $closed_at = $this->parseDate($row['phakman_amsathiv'] ?? null);

            // Անուն Ազգանուն Հայրանուն բաժանում
            $client_info = preg_split('/\s+/', trim($row['anvoun_azganvoun_hayranvoun'] ?? ''));
            $client_name = $client_info[0] ?? null;
            $client_surname = $client_info[1] ?? null;
            $client_middle_name = $client_info[2] ?? null;

            // Հեռախոսների մշակում՝ փորձելով առաջին և երկրորդ հեռախոսները ստանալ
            $phones = preg_split('/\s+/', trim($row['her_hamar'] ?? ''));
            $phone = null;
            $additional_phone = null;
            if (count($phones) >= 4) {
                $phone = "(+374) " . implode(' ', array_slice($phones, 1, 3));
            }
            if (count($phones) >= 8) {
                $additional_phone = "(+374) " . implode(' ', array_slice($phones, 5, 3));
            }

            // Կլիենտի տվյալների array
            $client_data = [
                'name'             => $client_name,
                'surname'          => $client_surname,
                'middle_name'      => $client_middle_name,
                'passport_series'  => $row['andznagri_seria'] ?? null,
                'passport_validity'=> $row['andznagri_vaverakanvouthyvoun'] ?? null,
                'passport_issued'  => preg_replace('/\D/', '', $row['trvats'] ?? null),
                'country'          => $row['erkir'] ?? null,
                'city'             => $row['qaghaq'] ?? null,
                'street'           => $row['phvoghvocshenq'] ?? null,
                'date_of_birth'    => $date_of_birth,
                'email'            => $row['meyl'] ?? null,
                'phone'            => $phone,
                'additional_phone' => $additional_phone,
                'bank_name'        => $row['bank'] ?? null,
                'card_number'      => $row['qarti_hamar'] ?? null,
                'account_number'   => $row['hashv_hamar'] ?? null,
                'date'             => $date
            ];

            // Ստեղծել կամ թարմացնել կլիենտին
            $client = $this->clientService->storeOrUpdate($client_data);

            // Պայմանագրի ստեղծում
            Contract::create([
                'date'             => $date,
                'num'              => $row['paym_hamar'] ?? null,
                'pawnshop_id'      => $row['gravatan_hamar'] ?? null,
                'estimated_amount' => $row['gnahatvats'] ?? 0,
                'provided_amount'  => $row['tramadrvats'] ?? 0,
                'interest_rate'    => $row['tvokvosadrvouyq'] ?? 0,
                'penalty'          => $row['tvouganq'] ?? 0,
                'lump_rate'        => $row['mianvag'] ?? 0,
                'deadline_days'    => $row['orer'] ?? 0,
                'closed_at'        => $closed_at,
                'description'      => $row['nkaragrvouthyvoun'] ?? null,
                'category_id'      => null, // այստեղ կարող ես ավելացնել category lookup
                'status'           => $this->mapStatus($row['kargavitchak'] ?? ''),
                'mother'           => $row['mayr_gvoumar'] ?? 0,
                'left'             => $row['mnacel_e'] ?? 0,
                'collected_amount' => $row['havaqvel_e'] ?? 0,
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
