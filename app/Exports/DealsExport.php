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
        return Deal::with(['order', 'history', 'actions'])->get()->map(function ($deal) {
            $actions = $deal->actions->map(function ($action) {
                return implode(' | ', [
                    $action->type,
                    $action->amount,
                    $action->description,
                    optional($action->date)->format('Y-m-d')
                ]);
            })->implode('; ');
            return [
                $deal->type,
                $deal->amount,
                $deal->penalty,
                $deal->discount,
                $deal->interest_amount,
                $deal->pawnshop_id,
                $deal->contract->num ?? '',
                $deal->cash ? 'Կանխիկ' : 'Անկանխիկ',
                $deal->date,
                $deal->delay_days,
                $deal->purpose,
                $deal->receiver,
                $deal->created_by,
                $deal->updated_by,
                $deal->filter_type,
                $deal->category_id,

                // Order fields
                $deal->order->type ?? '',
                $deal->order->title ?? '',
                $deal->order->order ?? '',
                $deal->order->amount,
                $deal->order->date,
                $deal->order->client_name,
                $deal->order->purpose,
                $deal->order->receiver,
                $deal->order->filter,

                // History fields (օրինակ)
                $deal->history->type->title ?? '',
                $deal->history->date ?? '',

                $actions->type,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Գործարքի տեսակ',
            'Գումար',
            'Տուգանք',
            'Զեղչ',
            'Տոկոսագումար',
            'Գրավատան ID',
            'Պայմանագրի համար',
            'Վճարման տեսակ',
            'Գործարքի ամսաթիվ',
            'Ուշացման օրեր',
            'Նպատակ',
            'Ստացող',
            'Ստեղծել է',
            'Թարմացրել է',
            'Ֆիլտրի տեսակ',
            'Կատեգորիա',

            // Order headings
            'Օրդերի տեսակ',
            'Օրդերի վերնագիր',
            'Օրդերի համար',
            'Օրդերի գումար',
            'Օրդերի ամսաթիվ',
            'Հաճախորդի անուն',
            'Օրդերի նպատակ',
            'Օրդերի ստացող',
            'Օրդերի ֆիլտր',
            // History headings
            'Պատմության տիպ',
            'Պատմության ամսաթիվ',

            'Գործողություններ',

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
