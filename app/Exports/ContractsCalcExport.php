<?php
//
//namespace App\Exports;
//
//use App\Http\Resources\ContractDetailResource;
//use App\Models\ContractReserveHistory;
//use Illuminate\Support\Collection;
//use Maatwebsite\Excel\Concerns\FromCollection;
//use Maatwebsite\Excel\Concerns\WithStyles;
//use Maatwebsite\Excel\Concerns\ShouldAutoSize;
//use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
//use PhpOffice\PhpSpreadsheet\Style\Alignment;
//class ContractsCalcExport implements FromCollection, WithStyles, ShouldAutoSize{
//    protected Collection $contracts;
//
//    protected array $fieldMapping = [
//        'Պայմանագրի մնացորդ առ'                  => 'contract.calc_date',
//        'Պայմանագրի N'                           => 'contract.num',
//        'Հաճախորդի կոդ'                          => 'client.id',
//        'Անվանում'                              => 'client_full_name',
//        'Կապակցված է ընկերությանը'             => 'client.is_linked_to_company',
//        'Ընկերության աշխատակից է'               => 'client.is_company_employee',
//        'Արժ․'                                 => 'currency',
//        'Կնքման ամսաթիվ'                        => 'contract.date',
//        'Հստակեցման ամսաթիվ'                    => 'contract.date',
//        'Մայր գումարի մարման ժամկեը'             => 'contract.deadline',
//        'Տոկ․ մարման ժամկ․'                     => 'contract.deadline',
//        'Պայմանագրի գումար'                     => 'contract.contract_amount',
//        'Տրամադրված գումար'                      => 'provided_amount',
//        'Տոկոսադրույք'                          => 'contract.interest_rate',
//        'Արդ․ տոկոս․'                          => 'contract.effectiveRate',
//        'Ժամկետանց գումար'                      => 'contract.overdue_amount',
//        'Դուրս գրված գումար'                     => 'written_off_amount',
//        'Տոկոս' => 'contract.calculatedInterest',
//        'Արդյունավետ տոկոս' => 'contract.calculatedEffectiveInterest',
//        'Չվաստակած տոկոս' => 'contract.unearned_interest',
//        'Ժամկետանց տոկոս' => 'contract.overdue_interest',
//        'Դուրս գրված տոկոս' => 'contract.written_off_interest',
//        'Ժամկետանց գումարի տոկոս'               => 'contract.overdue_amount_interest',
//        'Դուրս գրված ժամկետանց գումարի տոկոս'    => 'contract.written_off_interest',
//        'Պահուստ'                               => 'reserve',
//        'Ռիսկի կշիռ'                            => 'risk_weight_percent',
//        'Պահուստավորման տոկոս'                   => 'reserve_percent',
//        'Ժամկետանց դառնալու ամսաթիվ'             => 'overdue_date_principal',
//        'Տոկ․ ժամկ․ դառնալու ամսաթիվ'           => 'overdue_date_interest',
//        'Փակման ամսաթիվ'                        => 'contract.closed_at',
//        'Տրամադրման օրերի քանակ'                 => 'total_days_provided',
//        'Մնացորդային մարման օրերի քանակ'         => 'contract.remaining_repayment_days',
//        'Գրավի գումար'                         => 'contract.contract_amount',
//        'Ռեզ․'                                 => 'client.residency_status',
//        'ՀՎՀՀ'                                 => 'client.tax_number',
//        'ՀԾՀ'                                  => 'client.social_card_number',
//        'Նույնականացուցիչ(BankID)'              => 'client.bank_client_id',
//    ];
//
//    public function __construct(Collection $contracts)
//    {
//        $this->contracts = $contracts;
//    }
//
//    public function collection(): Collection
//    {
//        $excelRows = collect();
//
//        foreach ($this->contracts as $contract) {
//
//            $contract->written_off_amount = null;
//
//            if (($contract->client?->classification?->name) === 'loss') {
//                $contract->written_off_amount =
//                    (float)($contract->overdue_interest ?? 0)
//                    + (float)($contract->unearned_interest ?? 0);
//            }
//
//            $contractData = (new ContractDetailResource($contract))->toArray(request());
//
//            $contractData['provided_amount'] = $contract->provided_amount ?? 0;
//
//            $contractData['total_days_provided'] = $contract->total_days_provided ?? '';
//            $contractData['written_off_amount'] = $contract->written_off_amount ?? 0;
//
//            if (!isset($contractData['contract'])) {
//                $contractData['contract'] = [];
//            }
//            $contractData['contract']['calc_date'] = $contract->calc_date
//                ? $contract->calc_date->format('d-m-Y')
//                : '';
//
//
//            $closestReserve = ContractReserveHistory::where('contract_id', $contract->id)
//                ->where('date', '<=', $contract->calc_date)
//                ->orderBy('date', 'desc')
//                ->first();
//
//            if ($closestReserve) {
//                $contractData['reserve'] = $closestReserve->reserve_amount;
//                $contractData['risk_weight_percent'] = $closestReserve->risk_weight ?? 0;
//                $contractData['reserve_percent'] = $closestReserve->reserve_percent ?? 0;
//            } else {
//                $contractData['reserve'] = 0;
//                $contractData['risk_weight_percent'] = 0;
//                $contractData['reserve_percent'] = 0;
//            }
//
//            $contractRows = $this->transformContractData($contractData);
//            $excelRows = $excelRows->merge($contractRows);
//
//            $excelRows->push(['', '']);
//        }
//
//        return $excelRows;
//    }
//
//    protected function transformContractData(array $contractData): Collection
//    {
//        $rows = collect();
//
//        $clientName = $contractData['client']['name'] ?? '';
//        $clientSurname = $contractData['client']['surname'] ?? '';
//        $contractData['client_full_name'] = trim($clientName . ' ' . $clientSurname);
//
//
//        foreach ($this->fieldMapping as $label => $dataKey) {
//            $value = $this->getNestedValue($contractData, $dataKey, '');
//
//            if ($label === 'Արժ․') {
//                $value = 'դրամ';
//            } elseif ($dataKey === 'client.is_linked_to_company' || $dataKey === 'client.is_company_employee') {
//                $value = (bool)$value ? 'Այո' : 'Ոչ';
//            }
//            $rows->push([$label, $value]);
//        }
//
//        return $rows;
//    }
//
//    protected function getNestedValue(array $data, string $key, $default = null)
//    {
//        if (array_key_exists($key, $data)) {
//            return $data[$key];
//        }
//
//        foreach (explode('.', $key) as $segment) {
//            if (is_array($data) && array_key_exists($segment, $data)) {
//                $data = $data[$segment];
//            } else {
//                return $default;
//            }
//        }
//
//        return $data;
//    }
//    public function styles(Worksheet $sheet)
//    {
//        $sheet->getStyle('A')->applyFromArray([
//            'font' => [
//                'bold' => true,
//            ],
//        ]);
//        $sheet->getStyle('B')->applyFromArray([
//            'alignment' => [
//                'horizontal' => Alignment::HORIZONTAL_LEFT,
//            ],
//        ]);
//
//    }
//}


namespace App\Exports;

use App\Http\Resources\ContractDetailResource;
use App\Models\ContractReserveHistory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ContractsCalcExport implements FromCollection, WithStyles, ShouldAutoSize
{
    protected Collection $contracts;

    protected array $fieldMapping = [
        'Պայմանագրի N' => 'contract.num',
        'Հաճախորդի կոդ' => 'client.id',
        'Անվանում' => 'client_full_name',
        'Կապակցված է ընկերությանը' => 'client.is_linked_to_company',
        'Ընկերության աշխատակից է' => 'client.is_company_employee',
        'Արժ․' => 'currency',
        'Կնքման ամսաթիվ' => 'contract.date',
        'Հստակեցման ամսաթիվ' => 'contract.date',
        'Մայր գումարի մարման ժամկեը' => 'contract.deadline',
        'Տոկ․ մարման ժամկ․' => 'contract.deadline',
        'Պայմանագրի գումար' => 'contract.contract_amount',
        'Տրամադրված գումար' => 'provided_amount',
        'Տոկոսադրույք' => 'contract.interest_rate',
        'Արդ․ տոկոս․' => 'contract.effective_annual_rate',
        'Ժամկետանց գումար' => 'contract.overdue_amount',
        'Դուրս գրված գումար' => 'contract.written_off_amount',
        'Տոկոս' => 'contract.calculatedInterest',
        'Արդյունավետ տոկոս' => 'contract.calculatedEffectiveInterest',
        'Չվաստակած տոկոս' => 'contract.unearned_interest',
        'Ժամկետանց տոկոս' => 'contract.overdue_interest',
        'Դուրս գրված տոկոս' => 'contract.written_off_interest',
        'Ժամկետանց գումարի տոկոս' => 'contract.overdue_amount_interest',
        'Դուրս գրված ժամկետանց գումարի տոկոս' => 'contract.written_off_interest',
        'Պահուստ' => 'contract.reserve',
        'Ռիսկի կշիռ' => 'risk_weight_percent',
        'Պահուստավորման տոկոս' => 'reserve_percent',
        'Ժամկետանց դառնալու ամսաթիվ' => 'overdue_date_principal',
        'Տոկ․ ժամկ․ դառնալու ամսաթիվ' => 'overdue_date_interest',
        'Փակման ամսաթիվ' => 'contract.closed_at',
        'Տրամադրման օրերի քանակ' => 'total_days_provided',
        'Մնացորդային մարման օրերի քանակ' => 'contract.remaining_repayment_days',
        'Գրավի գումար' => 'contract.contract_amount',
        'Ռեզ․' => 'client.residency_status',
        'ՀՎՀՀ' => 'client.tax_number',
        'ՀԾՀ' => 'client.social_card_number',
        'Նույնականացուցիչ(BankID)' => 'client.bank_client_id',
    ];

    public function __construct(Collection $contracts)
    {
        $this->contracts = $contracts;
    }

    public function collection(): Collection
    {
        $rows = collect();

        $firstContract = $this->contracts->first();
        $balance = $firstContract ? $firstContract->calc_date?->format('d-m-Y') : '';

        $rows->push(['Պայմանագրի մնացորդ առ']);
        $rows->push([$balance]);

        $headers = array_keys($this->fieldMapping);
        $rows->push($headers);

        foreach ($this->contracts as $contract) {
//            $contract->written_off_amount = null;
//            if (($contract->client?->classification?->name) === 'loss') {
//                $contract->written_off_amount =
//                    (float)($contract->overdue_interest ?? 0)
//                    + (float)($contract->unearned_interest ?? 0);
//            }

            $contractData = (new ContractDetailResource($contract))->toArray(request());
            $contractData['provided_amount'] = $contract->provided_amount ?? 0;
            $contractData['total_days_provided'] = $contract->total_days_provided ?? '';
//            $contractData['written_off_amount'] = $contract->written_off_amount ?? 0;

            if (!isset($contractData['contract'])) {
                $contractData['contract'] = [];
            }
            $contractData['contract']['calc_date'] = $contract->calc_date
                ? $contract->calc_date->format('d-m-Y')
                : '';

            $closestReserve = ContractReserveHistory::where('contract_id', $contract->id)
                ->where('date', '<=', $contract->calc_date)
                ->orderBy('date', 'desc')
                ->first();
            $clientClass = $contract->client->classification;
            dd($closestReserve,$contract->id,$contract->calc_date,$clientClass);
            if ($closestReserve) {
//                $contractData['reserve'] = $closestReserve->reserve_amount;
                $contractData['risk_weight_percent'] = $closestReserve->classification->risk_weight ?? 0;
                $contractData['reserve_percent'] = $closestReserve->classification->reserve_percent ?? 0;
            } else {
//                $contractData['reserve'] = 0;
                $contractData['risk_weight_percent'] = $clientClass->risk_weight ?? 0;
                $contractData['reserve_percent'] = $clientClass->reserve_percent ?? 0;
            }

            // client_full_name
            $clientName = $contractData['client']['name'] ?? '';
            $clientSurname = $contractData['client']['surname'] ?? '';
            $contractData['client_full_name'] = trim($clientName . ' ' . $clientSurname);

            $row = [];
            foreach ($this->fieldMapping as $label => $dataKey) {
                $value = $this->getNestedValue($contractData, $dataKey, '');
                if ($label === 'Արժ․') {
                    $value = 'դրամ';
                } elseif ($dataKey === 'client.is_linked_to_company' || $dataKey === 'client.is_company_employee') {
                    $value = (bool)$value ? 'Այո' : 'Ոչ';
                }

                if ($label === 'Տոկոսադրույք') {
                    $dailyRate = (float)$value;
                    $value = $dailyRate * 365;
                }
                $row[] = $value;
            }
            $rows->push($row);
        }

        return $rows;
    }

    protected function getNestedValue(array $data, string $key, $default = null)
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return $default;
            }
        }
        return $data;
    }

    public function styles(Worksheet $sheet)
    {
        $highestColumn = $sheet->getHighestColumn();
        $maxRow = $sheet->getHighestRow();

        $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true]]);

        $sheet->getStyle('A3:' . $highestColumn . '3')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        if ($maxRow > 3) {
            $sheet->getStyle('A4:' . $highestColumn . $maxRow)->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);
        }
    }
}
