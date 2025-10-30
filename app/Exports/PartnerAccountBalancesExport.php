<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PartnerAccountBalancesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected Collection $rows;

    public function __construct(Collection $rows)
    {
        $this->rows = $rows;
    }

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Գործընկեր',
            'Անվանում',
            'Հաշիվ',
            'Արժույթ',
            'Հաշվի մնացորդ',
        ];
    }

    public function map($row): array
    {
        return [
            $row->partner_code,
            $row->partner_name,
            $row->account_code,
            'AMD',
            $row->balance,
        ];
    }


    public function styles(Worksheet $sheet)
    {
        $dimension = $sheet->calculateWorksheetDimension();

        $sheet->getStyle($dimension)
            ->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        return [];
    }
}
