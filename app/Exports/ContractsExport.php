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
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
         return Contract::with(['client', 'pawnshop', 'category'])
            ->get()
            ->map(function ($contract) {
                return [
                    $contract->date,                          // Պայմանագրի ամսաթիվ
                    $contract->num,                           // Պայմ․ համար
                    $contract->pawnshop_id ?? '',          // Գրավատան համար
                    optional($contract->payments()->first())->id ?? '',  // Ելլքի օրդերի համար

                    trim(
   			 ($contract->client->name ?? '') . ' ' .
    			 ($contract->client->surname ?? '') . ' ' .
    			 ($contract->client->middle_name ?? '')
		    ),

                    $contract->client->passport_series ?? '',     // Անձնագրի սերիա
                    $contract->client->passport_validity ?? '',   // Անձնագրի վավերականություն
                    $contract->client->passport_issued ?? '',     // Տրված

                    $contract->client->country ?? '',         // Երկիր
                    $contract->client->city ?? '',            // Քաղաք
                    $contract->client->street . ' / ' . $contract->client->building,  // Փողոց/շենք

                    $contract->client->date_of_birth,         // Ծննդյան օր
                    $contract->client->email,                 
		    trim(
                         ($contract->client->phone ?? '') . ' ' .
                         ($contract->client->additional_phone ?? '')
                    ),



                    $contract->client->bank_name,             // Բանկ
                    $contract->client->card_number,           // ՔԱրտի համար
                    $contract->client->account_number,        // Հաշվ համար

                    $contract->estimated_amount,              // Գնահատվաշ
                    $contract->provided_amount,               // Տրամադրվաշ
                    $contract->interest_rate,                 // Տոկոսադրույք
                    $contract->penalty,                       // Տուգանք
                    $contract->one_time_payment,              // Միանվագ
                    $contract->deadline_days,                 // Օրեր

                    $contract->closed_at,                     // Փակման Ամսաթիվ
                    $contract->description,                   // Նկարագրություն
                    $contract->category->title ?? '',         // Կատեգորիա
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
            'Բանկ', 'ՔԱրտի համար', 'Հաշվ համար',
            'Գնահատվաշ', 'Տրամադրվաշ', 'Տոկոսադրույք', 'Տուգանք', 'Միանվագ', 'Օրեր',
            'Փակման Ամսաթիվ', 'Նկարագրություն', 'Կատեգորիա',
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
