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
    protected array $summary = [];
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
            ['Հաշիվ','Անվանում','Ընդամենը','Այդ թվում','','','','','','','','','','','','',''],
            ['', '', '', 'Ընդամենը','', 'AMD','', 'I խմբի արտարժույթ','', 'Այդ թվում','','','', 'II խմբի արտարժույթ','', 'Ռուսական ռուբլի',''],
            // Row 3 (currencies)
            ['', '', '', '', '', '', '', '', '', 'ԱՄՆ դոլար','', 'Եվրո','', '', '', '', ''],
            ['', '', '', 'Ռեզ','Ոչ ռեզ', 'Ռեզ','Ոչ ռեզ', 'Ռեզ','Ոչ ռեզ', 'Ռեզ','Ոչ ռեզ', 'Ռեզ','Ոչ ռեզ', 'Ռեզ','Ոչ ռեզ', 'Ռեզ','Ոչ ռեզ'],
        ];
    }

    public function map($row): array
    {
        return [
            $row->code,                     // A
            $row->name,                     // B
            (float)($row->total_resident ?? 0) + (float)($row->total_non_resident ?? 0), // C

            (float)($row->total_resident ?? 0),
            (float)($row->total_non_resident ?? 0),

            (float)($row->amd_resident ?? 0),
            (float)($row->amd_non_resident ?? 0),

            // H–I: I խմբի արտարժույթ (ընդհանուր)
            (float)($row->fx_group1_resident ?? 0),
            (float)($row->fx_group1_non_resident ?? 0),

            // J–K: ԱՄՆ դոլար
            (float)($row->usd_resident ?? 0),
            (float)($row->usd_non_resident ?? 0),

            // L–M: Եվրո
            (float)($row->eur_resident ?? 0),
            (float)($row->eur_non_resident ?? 0),

            // N–O: II խմբի արտարժույթ (ընդհանուր)
            (float)($row->fx_group2_resident ?? 0),
            (float)($row->fx_group2_non_resident ?? 0),

            // P–Q: Ռուսական ռուբլի
            (float)($row->rub_resident ?? 0),
            (float)($row->rub_non_resident ?? 0),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => '#,##0',
            'D' => '#,##0','E' => '#,##0',
            'F' => '#,##0','G' => '#,##0',
            'H' => '#,##0','I' => '#,##0',
            'J' => '#,##0','K' => '#,##0',
            'L' => '#,##0','M' => '#,##0',
            'N' => '#,##0','O' => '#,##0',
            'P' => '#,##0','Q' => '#,##0',
            // ամփոփման արժեքներ (T)
            'T' => '#,##0',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 14, 'B' => 60,
            'C' => 18,
            'D' => 16, 'E' => 16,
            'F' => 16, 'G' => 16,
            'H' => 16, 'I' => 16,
            'J' => 16, 'K' => 16,
            'L' => 16, 'M' => 16,
            'N' => 16, 'O' => 16,
            'P' => 16, 'Q' => 16,
            'S' => 22, 'T' => 18,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);

        foreach ([1,2,3,4] as $r) {
            $sheet->getStyle("A{$r}:Q{$r}")->applyFromArray([
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
                'borders'   => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'B7B7B7']
                    ],
                ],
            ]);
        }

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {

                $s = $event->sheet->getDelegate();

                $s->freezePane('A5');

                $s->mergeCells('A1:A4');   // Հաշիվ
                $s->mergeCells('B1:B4');   // Անվանում
                $s->mergeCells('C1:C4');   // Ընդամենը (միակ)
                $s->mergeCells('D1:Q1');   // Այդ թվում

                // Row2
                $s->mergeCells('D2:E2');   // Այդ թվում → Ընդամենը
                $s->mergeCells('F2:G2');   // AMD
                $s->mergeCells('H2:I2');   // I խմբի արտարժույթ
                $s->mergeCells('J2:M2');   // ԱՄՆ դոլար + Եվրո (ենթախումբ "Այդ թվում")
                $s->mergeCells('N2:O2');   // II խմբի արտարժույթ
                $s->mergeCells('P2:Q2');   // Ռուսական ռուբլի

                // Row3
                $s->mergeCells('D3:E3');
                $s->mergeCells('F3:G3');
                $s->mergeCells('H3:I3');
                $s->mergeCells('J3:K3');   // ԱՄՆ դոլար
                $s->mergeCells('L3:M3');   // Եվրո
                $s->mergeCells('N3:O3');
                $s->mergeCells('P3:Q3');

                $lastRow = $s->getHighestRow();

                $s->getStyle("A5:B{$lastRow}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $s->getStyle("C5:Q{$lastRow}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $s->getStyle("A1:Q{$lastRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color'       => ['rgb' => 'B7B7B7'],
                        ],
                    ],
                ]);



                $s->setCellValue("S1", "Ամփոփում");
                $s->mergeCells("S1:T1");

                $s->getStyle("S1:T5")->applyFromArray([
                    'font' => ['bold' => false],
                    'fill' => [
                        'fillType'   => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FFFFFF'],
                    ],
                ]);

                $s->getStyle("S1:T1")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color'       => ['rgb' => 'B7B7B7'],
                        ],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                $labels = ['Ակտիվներ','Պարտավորություններ','Կապիտալ','Հաշվեկշիռ'];
                $values = [
                    $this->summary['Ակտիվներ'] ?? 0,
                    $this->summary['Պարտավորություններ'] ?? 0,
                    $this->summary['Կապիտալ'] ?? 0,
                    $this->summary['Հաշվեկշիռ'] ?? ($this->summary['Հաշվեշիռ'] ?? 0),
                ];

                foreach ($labels as $i => $label) {
                    $r = 2 + $i;
                    $s->setCellValue("S{$r}", $label);
                    $s->setCellValue("T{$r}", $values[$i]);
                    $s->getStyle("S{$r}:T{$r}")->applyFromArray([
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color'       => ['rgb' => 'B7B7B7'],
                            ],
                        ],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    ]);
                    $s->getStyle("T{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $s->getStyle("T{$r}")->getNumberFormat()->setFormatCode('#,##0');
                    $s->getStyle("T{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $s->getStyle("T{$r}")->getNumberFormat()->setFormatCode('#,##0');
                }
                $s->getColumnDimension('S')->setWidth(22);
                $s->getColumnDimension('T')->setWidth(18);
            },
        ];
    }
}
