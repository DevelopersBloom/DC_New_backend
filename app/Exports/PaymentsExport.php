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
            $typeText = '';
            if ($type === 'penalty') {
                $typeText = 'Տուգանք';
            } elseif ($type === 'partial') {
                $typeText = 'Մասնակի';
            } elseif ($type === 'regular') {
                $typeText = 'Հերթական';
            }

            return [
                $typeText,
                $payment->PGI_ID,
                $payment->contract_id,
                $payment->date,
                $payment->amount,
                $payment->paid,
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
            'Վճարված գումար',
            'Չվճարված գումար',
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
