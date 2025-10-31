<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
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
            'Դեբետ արժ․',
            'Կրեդիտ արժ․',
            'Դեբետ Դրամով',
            'Կրեդիտ Դրամով',
        ];
    }

    public function map($row): array
    {
        $acc = ChartOfAccount::where('code', $row->account_code)->first();
        $type = $acc?->type ?? 'active';

        $amount = $row['amount'];

        $debitAmd = '';
        $creditAmd = '';

        if ($type === 'active') {
            if ($amount >= 0) {
                $debitAmd = $amount;
            } else {
                $creditAmd = abs($amount);
            }
        } elseif ($type === 'passive') {
            if ($amount >= 0) {
                $creditAmd = $amount;
            } else {
                $debitAmd = abs($amount);
            }
        }
        return [
            $row->partner_code,
            $row->partner_name,
            $row->account_code,
            'AMD',
            '',
            '',
            $debitAmd,
            $creditAmd,
        ];
    }


    public function styles(Worksheet $sheet)
    {
        $dimension = $sheet->calculateWorksheetDimension();

        $sheet->getStyle($dimension)
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        return [];
    }
}
