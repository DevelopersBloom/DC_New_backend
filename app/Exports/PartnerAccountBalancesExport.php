<?php

namespace App\Exports;

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
        $type = $row->type ?? 'active';
        $balance = (float) ($row->balance ?? 0);

        $debitAmd = '';
        $creditAmd = '';

        // Same rules as AccountsBalancesExport / partner balances UI
        if (in_array($type, ['active', 'expense', 'off_balance'], true)) {
            if ($balance >= 0) {
                $debitAmd = round($balance, 2);
            } else {
                $creditAmd = round(abs($balance), 2);
            }
        } else {
            if ($balance >= 0) {
                $creditAmd = round($balance, 2);
            } else {
                $debitAmd = round(abs($balance), 2);
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

        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        return [];
    }
}
