<?php

namespace App\Exports;

use App\Models\Payment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentsExport implements FromCollection, WithHeadings, WithStyles
{
    /**
     * @return Collection
     */
    public function collection(): Collection
    {
        return Payment::all()->map(function ($payment) {
            $status = $payment->status === 'completed' ? 'Վճարված' : 'Չվճարված';

            $type = $payment->type;
            $amount = $payment->amount;
            $typeText = '';
            if ($type === 'penalty') {
                $typeText = 'Տուգանք';
                $amount = 0;
            } elseif ($type === 'partial') {
                $typeText = 'Մասնակի';
                $amount = 0;
            } elseif ($type === 'regular') {
                $typeText = 'Հերթական';
            }

            return [
                $typeText,
                $payment->PGI_ID,
                $payment->contract_id,
                $payment->date,
                $amount ?? 0,
                $payment->paid ?? 0,
                $payment->mother,
                $status,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Տեսակ',
            'N',
            'Պայմանագրի համար',
            'Վճարման ամսաթիվ',
            'Չվճարված գումար',
            'Վճարված գումար',
            'Մայր գումար',
            'Կարգավիճակը',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'e3e3e3'],
            ]],
        ];
    }
}
