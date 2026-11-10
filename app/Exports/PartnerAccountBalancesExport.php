<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PartnerAccountBalancesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithStrictNullComparison
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
            'Դասակարգում',
            'Պահուստի տարբերություն',
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

        // WithStrictNullComparison is on so a reserve difference of 0 is written
        // as 0; keep zero debit/credit amounts blank as they were before.
        $debitAmd = $debitAmd === 0.0 ? '' : $debitAmd;
        $creditAmd = $creditAmd === 0.0 ? '' : $creditAmd;

        return [
            $row->partner_code,
            $row->partner_name,
            $row->account_code,
            'AMD',
            '',
            '',
            $debitAmd,
            $creditAmd,
            $row->classification ?? '',
            $row->reserve_diff ?? '',
        ];
    }


    public function styles(Worksheet $sheet)
    {
        $dimension = $sheet->calculateWorksheetDimension();

        $sheet->getStyle($dimension)
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        // Red cells: partners with no classification history (column I) and
        // reserve rows whose balance is more than 1 AMD off the required amount (column J).
        foreach ($this->rows->values() as $index => $row) {
            $excelRow = $index + 2;

            if (($row->classification ?? null) === null) {
                $this->paintRed($sheet, 'I' . $excelRow);
            }

            if (($row->reserve_diff ?? null) !== null && abs($row->reserve_diff) > 1) {
                $this->paintRed($sheet, 'J' . $excelRow);
            }
        }

        return [];
    }

    private function paintRed(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFFF0000');
    }
}
