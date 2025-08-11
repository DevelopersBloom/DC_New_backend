<?php

namespace App\Exports;

use App\Models\Contract;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Collection;

class ContractsExport implements FromCollection, WithHeadings, WithStyles
{
    /**
    * @return Collection
    */
    public function collection(): Collection
    {
         return Contract::with(['client', 'pawnshop', 'category'])
            ->get()
            ->map(function ($contract) {
                $status = 'Բաց';
                if ($contract->status == 'completed') {
                    $status = 'Փակված';
                } elseif ($contract->status == 'executed') {
                    $status = 'Իրացված';
                }
                return [
                    $contract->date,
                    $contract->num,
                    $contract->pawnshop_id ?? '',
                    optional($contract->payments()->first())->id ?? '',

                    trim(
                        ($contract->client->name ?? '') . ' ' .
    			        ($contract->client->surname ?? '') . ' ' .
    			        ($contract->client->middle_name ?? '')
		            ),

                    $contract->client->passport_series ?? '',
                    $contract->client->passport_validity ?? '',
                    $contract->client->passport_issued ?? '',

                    $contract->client->country ?? '',
                    $contract->client->city ?? '',
                    $contract->client->street . ' / ' . $contract->client->building,

                    $contract->client->date_of_birth,
                    $contract->client->email,

                    trim(
                         ($contract->client->phone ?? '') . ' ' .
                         ($contract->client->additional_phone ?? '')
                    ),

                    $contract->client->bank_name,
                    $contract->client->card_number,
                    $contract->client->account_number,

                    $contract->estimated_amount,
                    $contract->provided_amount,
                    $contract->interest_rate,
                    $contract->penalty,
                    $contract->lump_rate,
                    $contract->deadline_days,

                    $contract->closed_at,
                    $contract->description,
                    $contract->category->title ?? '',
                    $status,
                    $contract->mother,
                    $contract->left,
                    $contract->collected_amount
                ];
            });
    }

     public function headings(): array
    {
        return [
            'Պայմանագրի ամսաթիվ', 'Պայմ․ համար', 'Գրավատան համար', 'Ելլքի օրդերի համար',
            'Անուն Ազգանուն Հայրանուն',
            'Անձնագրի սերիա', 'Անձնագրի վավերականություն', 'Տրված',
            'Երկիր', 'Քաղաք', 'Փողոց/շենք',
            'Ծննդյան օր', 'Մեյլ', 'Հեռ․ համար',
            'Բանկ', 'Քարտի համար', 'Հաշվ համար',
            'Գնահատված', 'Տրամադրված', 'Տոկոսադրույք', 'Տուգանք', 'Միանվագ', 'Օրեր',
            'Փակման Ամսաթիվ', 'Նկարագրություն', 'Կատեգորիա','Կարգավիճակ',
            'Մայր գումար', 'Մնացել է ','Հավաքվել է'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'e3e3e3']
            ]],
        ];
    }
}
