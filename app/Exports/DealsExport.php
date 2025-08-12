<?php

namespace App\Exports;

use App\Models\Deal;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Collection;

class DealsExport implements FromCollection, WithHeadings, WithStyles
{
    public function collection(): Collection
    {
        return Deal::with(['order', 'history'])->get()->map(function ($deal) {
            return [
                // Deal fields
                $deal->type,
                $deal->amount,
                $deal->penalty,
                $deal->discount,
                $deal->interest_amount,
                $deal->order_id,
                $deal->pawnshop_id,
                $deal->contract->num ?? '', // եթե պետք է contract_num
                $deal->cash ? 'Yes' : 'No',
                $deal->given,
                $deal->insurance,
                $deal->date,
                $deal->delay_days,
                $deal->purpose,
                $deal->receiver,
                $deal->source,
                $deal->created_by,
                $deal->updated_by,
                $deal->filter_type,
                $deal->payment_id,
                $deal->history_id,
                $deal->category_id,

                // Order fields (օրինակ)
                $deal->order->id ?? '',
                $deal->order->date ?? '',
                $deal->order->amount ?? '',

                // History fields (օրինակ)
                $deal->history->id ?? '',
                $deal->history->date ?? '',
                $deal->history->amount ?? '',
            ];
        });
    }

    public function headings(): array
    {
        return [
            // Deal headings
            'Type',
            'Amount',
            'Penalty',
            'Discount',
            'Interest Amount',
            'Order ID',
            'Pawnshop ID',
            'Contract Num',
            'Cash',
            'Given',
            'Insurance',
            'Date',
            'Delay Days',
            'Purpose',
            'Receiver',
            'Source',
            'Created By',
            'Updated By',
            'Filter Type',
            'Payment ID',
            'History ID',
            'Category ID',

            // Order headings
            'Order ID',
            'Order Date',
            'Order Amount',

            // History headings
            'History ID',
            'History Date',
            'History Amount',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'e3e3e3']
                ]
            ],
        ];
    }
}
