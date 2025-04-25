<?php

namespace App\Exports;

use App\Models\Deal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithColumnWidths;

class DailyExportSheet2 implements FromCollection, WithHeadings, WithStyles,ShouldAutoSize
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $pawnshopId = auth()->user()->pawnshop_id;

        $deals = Deal::where('pawnshop_id', $pawnshopId)
            ->select('id', 'date',DB::raw("DATE_FORMAT(date, '%d.%m.%Y') as formatted_date"), 'amount', 'cash', 'type', 'order_id', 'contract_id', 'created_by','purpose')
            ->with(['order:id,client_name', 'contract:id,num', 'createdBy:id,name,surname'])
            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")
            ->orderByDesc('id')
            ->get();

        return $deals->map(function ($deal) use ($pawnshopId) {
            $cash = $deal->cash ? 'Կանխիկ' : 'Անկանխիկ';
            $type = match ($deal->type) {
                'in' => 'Մուտք',
                'out', 'cost_out' => 'Ելք',
                'ndm' => 'ՆԴՄ',
                'expense' => 'Ծախս',
                default   => $deal->type,
            };
            return [
                'ID' => $deal->id,
                'Date' => $deal->formatted_date,
                'Client Name' => $deal->order->client_name ?? '',
                'Amount' => $deal->amount,
                'Cash' => $cash,
                'Type' => $type,
                'Purpose' => $deal->purpose ?? '',
                'Contract Number' => $deal->contract->num ?? '',
                'Interest Amount' => $deal->interest_amount,
                'Discount' => $deal->contract->discount ?? '',
                'Penalty' => $deal->contract->penalty_amount ?? '',
                'Mother' => $deal->contract->mother ?? '',
                'Delay Days' => $deal->delay_days,
                'Created By' => $deal->createdBy->name . ' ' . $deal->createdBy->surname,
            ];
        });

    }
    public function headings(): array
    {
        return [
            'Համար',
            'Ամսաթիվ',
            'Հաճախորդ',
            'Գումար',
            'Կանխիկ/Անկանխիկ',
            'Մուտք/Ելք',
            'Նպատակ',
            'Պայմանագրի Համար',
            'Տոկոսագումար',
            'Զեխչ',
            'Տուգանք',
            'ՄԳ',
            'Ուշացման օրեր',
            'Ստեղծել է',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FFCCE5FF'],
                ],
            ],
        ];
    }
}
