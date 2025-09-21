<?php
//
//namespace App\Exports;
//
//use App\Models\Transaction;
//use Illuminate\Support\Collection;
//use Maatwebsite\Excel\Concerns\FromCollection;
//use Maatwebsite\Excel\Concerns\WithHeadings;
//use Maatwebsite\Excel\Concerns\WithMapping;
//use Maatwebsite\Excel\Concerns\WithEvents;
//use Maatwebsite\Excel\Concerns\WithStyles;
//use Maatwebsite\Excel\Concerns\WithColumnWidths;
//use Maatwebsite\Excel\Concerns\WithColumnFormatting;
//use Maatwebsite\Excel\Concerns\ShouldAutoSize;
//use Maatwebsite\Excel\Events\AfterSheet;
//
//use PhpOffice\PhpSpreadsheet\Style\Alignment;
//use PhpOffice\PhpSpreadsheet\Style\Border;
//use PhpOffice\PhpSpreadsheet\Style\Fill;
//use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
//use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
//
//class LoanNdmJournalExport implements
//    FromCollection,
//    WithHeadings,
//    WithMapping,
//    WithEvents,
//    WithStyles,
//    WithColumnWidths,
//    WithColumnFormatting,
//    ShouldAutoSize
//{
//    protected $from;
//    protected $to;
//
//    public function __construct($from = null, $to = null)
//    {
//        $this->from = $from;
//        $this->to   = $to;
//    }
//
//
//    public function collection(): Collection
//    {
//        $query = Transaction::with([
//            'amountCurrencyRelation:id,code',
//            'user:id,name,surname',
//        ])
//            ->where('document_type', \App\Models\Transaction::LOAN_NDM_TYPE)
//            ->select([
//                'id',
//                'date',
//                'document_number',
//                'document_type',
//                'amount_amd',
//                'amount_currency_id',
//                'debit_partner_code',
//                'debit_partner_name',
//                'comment',
//                'user_id',
//                'disbursement_date',
//            ]);
//
//        if ($this->from && $this->to) {
//            $query->whereBetween('date', [$this->from, $this->to]);
//        } elseif ($this->from) {
//            $query->where('date', '>=', $this->from);
//        } elseif ($this->to) {
//            $query->where('date', '<=', $this->to);
//        }
//
//        return $query->orderBy('date', 'desc')->get();
//    }
//
//    public function headings(): array
//    {
//        return [
//            'Ամսաթիվ',
//            'Փաստաթղթի N',
//            'Փաստաթղթի Տեսակ',
//            'Արժույթ',
//            'Գումար (դրամով)',
//            'Գործընկեր կոդ',
//            'Գործընկեր անվանում',
//            'Մեկնաբանություն',
//            'Օգտագործող',
//            'Գրանցման ամսաթիվ',
//        ];
//    }
//
//
//    public function map($tx): array
//    {
//        return [
//            $tx->date,
//            $tx->document_number,
//            $tx->document_type,
//            optional($tx->amountCurrencyRelation)->code,
//            $tx->amount_amd,
//            $tx->debit_partner_code,
//            $tx->debit_partner_name,
//            $tx->comment,
//            trim((optional($tx->user)->name ?? '') . ' ' . (optional($tx->user)->surname ?? '')),
//            $tx->disbursement_date,
//        ];
//    }
//
//    public function columnFormats(): array
//    {
//        return [
//            'A' => NumberFormat::FORMAT_DATE_YYYYMMDD2,
//            'E' => '#,##0',
//            'J' => NumberFormat::FORMAT_DATE_YYYYMMDD2,
//        ];
//    }
//
//    /**
//     * Լայնություններ
//     */
//    public function columnWidths(): array
//    {
//        return [
//            'A' => 12,  // Ամսաթիվ
//            'B' => 16,  // Փաստաթղթի N
//            'C' => 40,  // Փաստաթղթի Տեսակ
//            'D' => 10,  // Արժույթ
//            'E' => 18,  // Գումար
//            'F' => 20,  // Գործընկեր կոդ
//            'G' => 32,  // Գործընկեր անվանում
//            'H' => 36,  // Մեկնաբանություն
//            'I' => 22,  // Օգտագործող
//            'J' => 20,  // Գրանցման ամսաթիվ
//        ];
//    }
//
//    /**
//     * Ընդհանուր ոճավորում
//     */
//    public function styles(Worksheet $sheet)
//    {
//        $sheet->getDefaultRowDimension()->setRowHeight(18);
//        $sheet->getParent()->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);
//
//        return [
//            1 => [
//                'font'      => ['bold' => true, 'size' => 11],
//                'alignment' => [
//                    'horizontal' => Alignment::HORIZONTAL_CENTER,
//                    'vertical'   => Alignment::VERTICAL_CENTER,
//                    'wrapText'   => true
//                ],
//                'fill'      => [
//                    'fillType'   => Fill::FILL_SOLID,
//                    'startColor' => ['rgb' => 'E6F0FF']
//                ],
//            ],
//        ];
//    }
//
//    /**
//     * AfterSheet դիզայն
//     */
//    public function registerEvents(): array
//    {
//        return [
//            AfterSheet::class => function (AfterSheet $event) {
//                $sheet      = $event->sheet->getDelegate();
//                $highestRow = $sheet->getHighestRow();
//                $highestCol = $sheet->getHighestColumn();
//                $dataRange  = "A1:{$highestCol}{$highestRow}";
//
//                // Header already styled in styles(); re-assert borders etc.
//                $sheet->getStyle($dataRange)->getAlignment()
//                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
//                    ->setWrapText(true);
//
//                $sheet->getStyle($dataRange)->getBorders()->getAllBorders()
//                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
//                    ->getColor()->setRGB('B7B7B7');
//
//                // Zebra rows + row height
//                for ($r = 2; $r <= $highestRow; $r++) {
//                    if ($r % 2 === 0) {
//                        $sheet->getStyle("A{$r}:{$highestCol}{$r}")
//                            ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
//                            ->getStartColor()->setRGB('FAFAFA');
//                    }
//                    $sheet->getRowDimension($r)->setRowHeight(20);
//                }
//
//                // Alignments
//                $sheet->getStyle("A2:A{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
//                $sheet->getStyle("B2:B{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
//                $sheet->getStyle("C2:C{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
//                $sheet->getStyle("D2:D{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
//                $sheet->getStyle("E2:E{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
//                $sheet->getStyle("I2:I{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
//                $sheet->getStyle("J2:J{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
//
//                // Freeze + Filter
//                $sheet->freezePane('A2');
//                $sheet->setAutoFilter("A1:{$highestCol}1");
//
//                // Title + print setup
//                $sheet->setTitle('NDM մատյան');
//                $pageSetup = $sheet->getPageSetup();
//                $pageSetup->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
//                $pageSetup->setFitToWidth(1);
//                $pageSetup->setFitToHeight(0);
//
//                // Numeric formats (backup)
//                $sheet->getStyle("E2:E{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');
//            },
//        ];
//    }
//}


namespace App\Exports;

use App\Models\LoanNdm;
use App\Models\Transaction;
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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LoanNdmJournalExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithEvents,
    WithStyles,
    WithColumnWidths,
    WithColumnFormatting,
    ShouldAutoSize
{
    protected $from;
    protected $to;

    public function __construct($from = null, $to = null)
    {
        $this->from = $from;
        $this->to = $to;
    }

    public function collection(): Collection
    {
        $q = LoanNdm::with([
            'client:id,type,name,surname,company_name,social_card_number,tax_number',
            'currency:id,code',
            // եթե loan_ndm-ում user_id ունես
//            'user:id,name,surname',
        ]);

        if ($this->from && $this->to) {
            $q->whereBetween('contract_date', [$this->from, $this->to]);
        } elseif ($this->from) {
            $q->where('contract_date', '>=', $this->from);
        } elseif ($this->to) {
            $q->where('contract_date', '<=', $this->to);
        }

        return $q->orderBy('contract_date', 'desc')->get();
    }

    public function headings(): array
    {
        return [
            'Ամսաթիվ',
            'Փաստաթղթի N',
            'Փաստաթղթի Տեսակ',
            'Արժույթ',
            'Գումար (դրամով)',
            'Գործընկեր կոդ',
            'Գործընկեր անվանում',
            'Մեկնաբանություն',
            'Օգտագործող',
            'Գրանցման ամսաթիվ',
        ];
    }

    public function map($ndm): array
    {
        $client = $ndm->client;

        $partnerName = $client
            ? ($client->type === 'legal'
                ? ($client->company_name ?? '')
                : trim(($client->name ?? '') . ' ' . ($client->surname ?? '')))
            : '';

        $partnerCode = $client
            ? ($client->type === 'individual'
                ? ($client->social_card_number ?? '')
                : ($client->tax_number ?? ''))
            : '';

        $documentType = defined(Transaction::class . '::LOAN_NDM_TYPE')
            ? Transaction::LOAN_NDM_TYPE
            : 'loan_ndm';

        return [
            optional($ndm->contract_date)->format('Y-m-d'),
            $ndm->contract_number,
            $documentType,
            optional($ndm->currency)->code,
            $ndm->amount,
            $partnerCode,
            $partnerName,
            $ndm->comment,
            'Գրիգոր Սահակյան',
//            trim(($ndm->user->name ?? '') . ' ' . ($ndm->user->surname ?? '')),
            optional($ndm->created_at)->format('Y-m-d H:i'),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_DATE_YYYYMMDD2,
            'E' => '#,##0',
            'J' => NumberFormat::FORMAT_DATE_DATETIME,
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12,
            'B' => 16,
            'C' => 18,
            'D' => 10,
            'E' => 18,
            'F' => 20,
            'G' => 28,
            'H' => 30,
            'I' => 20,
            'J' => 20,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);

        return [
            1 => [
                'font' => ['bold' => true, 'size' => 11],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F0FF']],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();
                $highestCol = $sheet->getHighestColumn();
                $dataRange = "A1:{$highestCol}{$highestRow}";

                $headerRange = "A1:{$highestCol}1";
                $sheet->getStyle($headerRange)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E6F0FF'],
                    ],
                ]);

                $sheet->getStyle($dataRange)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$highestCol}1");
                $sheet->setTitle('ՆԴՄ Export');
            },
        ];
    }
}

