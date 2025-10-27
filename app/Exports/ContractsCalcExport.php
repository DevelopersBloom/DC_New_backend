<?php

namespace App\Exports;

use App\Http\Resources\ContractDetailResource;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
class ContractsCalcExport implements FromCollection, WithStyles, ShouldAutoSize{
    protected Collection $contracts;

    protected array $fieldMapping = [
        'Պայմանագրի մնացորդ առ'                  => 'contract.date',
        'Պայմանագրի N'                           => 'contract.num',
        'Հաճախորդի կոդ'                          => 'client.id',
        'Անվանում'                              => 'client_full_name',
        'Կապակցված է ընկերությանը'             => 'client.is_linked_to_company',
        'Ընկերության աշխատակից է'               => 'client.is_company_employee',
        'Արժ․'                                 => 'դրամ',
        'Կնքման ամսաթիվ'                        => 'contract.date',
        'Հստակեցման ամսաթիվ'                    => 'contract.date',
        'Մայր գումարի մարման ժամկեը'             => 'contract.deadline',
        'Տոկ․ մարման ժամկ․'                     => 'contract.deadline',
        'Պայմանագրի գումար'                     => 'contract.contract_amount',
        'Տրամադրված գումար'                      => 'contract.provided_amount',
        'Տոկոսադրույք'                          => 'contract.interest_rate',
        'Արդ․ տոկոս․'                          => 'contract.effectiveRate',
        'Ժամկետանց գումար'                      => 'contract.overdue_amount',
        'Դուրս գրված գումար'                     => 'written_off_total',
        'Ժամկետանց գումարի տոկոս'               => 'contract.overdue_amount_interest',
        'Դուրս գրված ժամկետանց գումարի տոկոս'    => 'contract.written_off_interest',
        'Պահուստ'                               => 'contract.reserve',
        'Ռիսկի կշիռ'                            => 'client.risk_weight_percent',
        'Պահուստավորման տոկոս'                   => 'client.reserve_percent',
        'Ժամկետանց դառնալու ամսաթիվ'             => 'overdue_date_principal',
        'Տոկ․ ժամկ․ դառնալու ամսաթիվ'           => 'overdue_date_interest',
        'Փակման ամսաթիվ'                        => 'contract.closed_at',
        'Տրամադրման օրերի քանակ'                 => 'total_days_provided',
        'Մնացորդային մարման օրերի քանակ'         => 'contract.remaining_repayment_days',
        'Գրավի գումար'                         => 'contract.contract_amount',
        'Ռեզ․'                                 => 'client.residency_status',
        'ՀՎՀՀ'                                 => 'client.tax_number',
        'ՀԾՀ'                                  => 'client.social_card_number',
        'Նույնականացուցիչ(BankID)'              => 'client.bank_client_id',
    ];

    public function __construct(Collection $contracts)
    {
        $this->contracts = $contracts;
    }

    public function collection(): Collection
    {
        $transformedContracts = $this->contracts->map(function ($contract) {
            return (new ContractDetailResource($contract))->toArray(request());
        });

        $excelRows = collect();

        foreach ($transformedContracts as $contractData) {
            $contractRows = $this->transformContractData($contractData);
            $excelRows = $excelRows->merge($contractRows);

            $excelRows->push(['', '']);
        }

        return $excelRows;
    }


    protected function transformContractData(array $contractData): Collection
    {
        $rows = collect();

        $clientName = $contractData['client']['name'] ?? '';
        $clientSurname = $contractData['client']['surname'] ?? '';
        $contractData['client_full_name'] = trim($clientName . ' ' . $clientSurname);

        $contractData['written_off_total'] = ($contractData['contract']['overdue_interest'] ?? 0) + ($contractData['contract']['unearned_interest'] ?? 0);

        foreach ($this->fieldMapping as $label => $dataKey) {
            $value = $this->getNestedValue($contractData, $dataKey, '-');

            if ($dataKey === 'client.is_linked_to_company' || $dataKey === 'client.is_company_employee') {
                $value = (bool)$value ? 'Այո' : 'Ոչ';
            }
            $rows->push([$label, $value]);
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
        $sheet->getStyle('A')->applyFromArray([
            'font' => [
                'bold' => true,
            ],
        ]);
        $sheet->getStyle('B')->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
            ],
        ]);

    }
}
