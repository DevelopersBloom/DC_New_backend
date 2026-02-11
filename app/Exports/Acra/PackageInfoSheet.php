<?php

namespace App\Exports\Acra;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PackageInfoSheet implements FromArray, WithTitle, WithColumnWidths, WithEvents
{
    protected $startDate;
    protected $endDate;

    public function __construct($start, $end) {
        $this->startDate = $start;
        $this->endDate = $end;
    }

    public function title(): string { return 'PackageInfo'; }

    public function array(): array {
        return [
            ['SourceName', 'TMP'],
            ['StartDate', $this->startDate],
            ['EndDate', $this->endDate],
            ['CreatedDateTime', now()->format('Y-m-d H:i:s')],
            ['FileCount', '1'],
            ['FileNum', '1'],
        ];
    }

    public function columnWidths(): array {
        return [
            'A' => 25,
            'B' => 25,
        ];
    }

    public function registerEvents(): array {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $cellRange = 'A1:B6';

                $event->sheet->getDelegate()->getStyle('A1:A6')->getFont()->setBold(true);
                $event->sheet->getDelegate()->getStyle('A1:A6')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('F2F2F2');

                $event->sheet->getDelegate()->getStyle($cellRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            },
        ];
    }
}
