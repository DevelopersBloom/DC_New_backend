<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

use App\Traits\CalculatesAccountBalancesTrait;

class ReportsJournalExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithEvents,
    WithStyles,
    WithColumnWidths,
    WithColumnFormatting,
    ShouldAutoSize
{
    use CalculatesAccountBalancesTrait;

    protected ?string $to;
    /** @var array<string,float> */
    protected array $summary = [];
    /** @var \Illuminate\Support\Collection */
    protected Collection $rows;

    public function __construct(?string $to = null)
    {
        $this->to = $to;
        $this->rows = collect();
        $this->summary = [];
    }

    public function collection(): Collection
    {
        $this->rows = $this->balancesRowsQuery($this->to)->get();

        $this->summary = $this->balancesSummary($this->to);

        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Հաշիվ',
            'Անվանում',
            'Ռեզ',
            'Ոչ ռեզ',
            'Ընդամենը',
            'AMD Ռեզ',
            'AMD Ոչ ռեզ',
        ];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->name,
            (float) $row->total_resident,
            (float) $row->total_non_resident,
            (float) $row->total,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => '#,##0', // ԸնդամենըՌեզ
            'D' => '#,##0', // Ընդամենը ոչ ռեզ
            'E' => '#,##0', // Ընդամենը
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 14, // Հաշիվ
            'B' => 60, // Անվանում
            'C' => 18, // ԸնդամենըՌեզ
            'D' => 20, // Ընդամենը ոչ ռեզ
            'E' => 18, // Ընդամենը
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);

        return [
            1 => [
                'font'      => ['bold' => true, 'size' => 11],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER,
                    'wrapText'   => true
                ],
                'fill'      => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E6F0FF']
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet      = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestCol = $sheet->getHighestColumn(); // օրինակ՝ 'E'

                $dataRange  = "A1:{$highestCol}{$highestRow}";

                $summaryLabels = ['Ակտիվներ', 'Պարտավորություններ', 'Կապիտալ', 'Հաշվեշիռ'];
                $summaryValues = [
                    $this->summary['Ակտիվներ'] ?? 0,
                    $this->summary['Պարտավորություններ'] ?? 0,
                    $this->summary['Կապիտալ'] ?? 0,
                    $this->summary['Հաշվեշիռ'] ?? 0,
                ];


                $lastColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
                $startColIndex = $lastColIndex + 5;
                $labelCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($startColIndex);
                $valueCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($startColIndex + 1);
                $sheet->getColumnDimension($labelCol)->setWidth(22);
                $sheet->getColumnDimension($valueCol)->setWidth(18);
                $sheet->setCellValue("{$labelCol}1", 'Ամփոփում');
                $sheet->mergeCells("{$labelCol}1:{$valueCol}1");
                $sheet->getStyle("{$labelCol}1:{$valueCol}1")->applyFromArray([
                    'font'      => ['bold' => true],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F6FF'],
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color'       => ['rgb' => 'B7B7B7'],
                        ],
                    ],
                ]);

                foreach ($summaryLabels as $i => $label) {
                    $row = 2 + $i; // սկսում ենք 2-րդ տողից
                    $sheet->setCellValue("{$labelCol}{$row}", $label);
                    $sheet->setCellValue("{$valueCol}{$row}", $summaryValues[$i]);

                    $sheet->getStyle("{$labelCol}{$row}:{$valueCol}{$row}")->applyFromArray([
                        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B7B7B7']]],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    ]);

                    $sheet->getStyle("{$valueCol}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle("{$valueCol}{$row}")->getNumberFormat()->setFormatCode('#,##0');
                }
            },
        ];
    }
}
