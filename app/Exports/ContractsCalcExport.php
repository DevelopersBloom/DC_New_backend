<?php

namespace App\Exports;

use App\Http\Resources\ContractDetailResource;
use App\Models\Contract;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class ContractsCalcExport implements FromCollection
{
    protected Collection $contracts;

    protected array $fieldMapping = [
        'Պայմանագրի մնացորդ առ'                  => 'current_payment_amount',
        'Պայմանագրի N'                           => 'contract.num',
        'Հաճախորդի կոդ'                          => 'client.id',
        'Անվանում'                              => 'client_full_name', // Հատուկ բանալի՝ լրիվ անունը ստանալու համար
        'Կապակցված է ընկերությանը'             => 'client.is_linked_to_company',
        'Ընկերության աշխատակից է'               => 'client.is_company_employee',
        'Արժ․'                                 => 'contract.currency', // Ենթադրելի դաշտ
        'Կնքման ամսաթիվ'                        => 'contract.date', // Կնքման ամսաթիվ
        'Հստակեցման ամսաթիվ'                    => 'contract.date', // Պետք է ճշգրտել
        'Մայր գումարի մարման ժամկեը'             => 'contract.interest_end', // payments_to_date_max
        'Տոկ․ մարման ժամկ․'                     => 'contract.deadline',
        'Պայմանագրի գումար'                     => 'contract.contract_amount',
        'Տրամադրված գումար'                      => 'contract.provided_amount',
        'Տոկոսադրույք'                          => 'contract.interest_rate',
        'Արդ․ տոկոս․'                          => 'contract.effectiveRate',
        'Ժամկետանց գումար'                      => 'contract.overdue_amount',
        'Դուրս գրված գումար'                     => 'written_off_total', // $this->overdue_interest + $this->unearned_interest
        'Ժամկետանց գումարի տոկոս'               => 'contract.overdue_amount_interest',
        'Դուրս գրված ժամկետանց գումարի տոկոս'    => 'contract.written_off_interest',
        'Պահուստ'                               => 'contract.reserve',
        'Ռիսկի կշիռ'                            => 'contract.risk_weight',
        'Պահուստավորման տոկոս'                   => 'client.reserve_percent',
        'Ժամկետանց դառնալու ամսաթիվ'             => 'overdue_date_principal', // Ենթադրելի դաշտ
        'Տոկ․ ժամկ․ դառնալու ամսաթիվ'           => 'overdue_date_interest', // Ենթադրելի դաշտ
        'Փակման ամսաթիվ'                        => 'contract.closed_at', // Ենթադրելի դաշտ
        'Տրամադրման օրերի քանակ'                 => 'contract.days_provided',
        'Մնացորդային մարման օրերի քանակ'         => 'contract.remaining_repayment_days',
        'Գրավի գումար'                         => 'collateral_amount', // Ենթադրելի դաշտ
        'Ռեզ․'                                 => 'client.classification',
        'ՀՎՀՀ'                                 => 'client.tax_id', // Ենթադրելի դաշտ
        'ՀԾՀ'                                  => 'client.social_security_number', // Ենթադրելի դաշտ
        'Նույնականացուցիչ(BankID)'              => 'client.bank_id', // Ենթադրելի դաշտ
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
            $value = $this->getNestedValue($contractData, $dataKey, 'Չկա');

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
}
