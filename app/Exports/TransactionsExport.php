<?php

namespace App\Exports;

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
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;

class TransactionsExport implements
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
        $this->to   = $to;
    }

    /**
     * Տվյալների լցնում
     * @return Collection
     */
    public function collection(): Collection
    {
        $query = Transaction::with([
            'debitAccount:id,code,name',
            'creditAccount:id,code,name',
            // Եթե ունես առանձին արժույթներ՝ ակտիվացրու/ավելացրու
            'debitCurrency:id,code',
            'creditCurrency:id,code',

            'amountCurrencyRelation:id,code',
            'user:id,name,surname',
        ])->select([
            'id','date','document_number','document_type',
            'amount_amd','amount_currency','amount_currency_id',
            'debit_account_id','credit_account_id','user_id',
            'debit_partner_code','debit_partner_name','credit_partner_code',
            'credit_partner_name'
        ]);

        if ($this->from && $this->to) {
            $query->whereBetween('date', [$this->from, $this->to]);
        } elseif ($this->from) {
            $query->where('date', '>=', $this->from);
        } elseif ($this->to) {
            $query->where('date', '<=', $this->to);
        }

        return $query->orderBy('date','desc')->get();
    }

    /**
     * Գլխագրեր (Հայերեն)
     */
    public function headings(): array
    {
        return [
            'Ամսաթիվ',
            'Փաստաթղթի N',
            'Փաստաթղթի Տեսակ',
            'Դեբետ հաշիվ',
            'Դեբետ գործ․ կոդ',
            'Դեբետ գործընկեր անվանում',
            'Դեբետ արժ․',
            'Կրեդիտ հաշիվ',
            'Կրեդիտ գործ․ կոդ',
            'Կրեդիտ գործընկեր անվանում',
            'Կրեդիտ արժ․',
            'Գումար (դրամով)',
            'Օգտագործող',
        ];
    }

    /**
     * Տողերի քարտեզավորում
     */
    public function map($tx): array
    {
        return [
            $tx->date, // կֆորմատավորվի AfterSheet/WithColumnFormatting-ով
            $tx->document_number,
            $tx->document_type,

            optional($tx->debitAccount)->code . ' ' . optional($tx->debitAccount)->name,
            $tx->debit_partner_code,
            $tx->debit_partner_name,
            // Եթե չկան debitCurrency/creditCurrency, կարող ես տեղադրել:
            // optional($tx->amountCurrencyRelation)->code,
            optional($tx->debitCurrency)->code,

            optional($tx->creditAccount)->code . ' ' . optional($tx->creditAccount)->name,
            $tx->credit_partner_code,
            $tx->credit_partner_name,
            // կամ նույնը՝ amountCurrencyRelation
            optional($tx->creditCurrency)->code,

            $tx->amount_amd,

            trim((optional($tx->user)->name ?? '') . ' ' . (optional($tx->user)->surname ?? '')),
        ];
    }

    /**
     * Սյունակների թվային/թվային- ամսաթվի ֆորմատներ
     */
    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_DATE_YYYYMMDD2, // Ամսաթիվ
            'L' => '#,##0', // Գումար (դրամով)՝ հազարների բաժանումով
        ];
    }

    /**
     * Սյունակների լայնություններ (մի մասը՝ հստակ, մնացածը՝ autosize)
     */
    public function columnWidths(): array
    {
        return [
            'A' => 12,  // Ամսաթիվ
            'B' => 16,  // Փաստաթղթի N
            'C' => 18,  // Փաստաթղթի Տեսակ
            'D' => 38,  // Դեբետ հաշիվ (կոդ + անվանում)
            'E' => 16,  // Դեբետ գործ․ կոդ
            'F' => 28,  // Դեբետ գործընկեր անվանում
            'G' => 10,  // Դեբետ արժ․
            'H' => 38,  // Կրեդիտ հաշիվ
            'I' => 16,  // Կրեդիտ գործ․ կոդ
            'J' => 28,  // Կրեդիտ գործընկեր անվանում
            'K' => 10,  // Կրեդիտ արժ․
            'L' => 18,  // Գումար (դրամով)
            'M' => 20,  // Օգտագործող
        ];
    }

    /**
     * Ընդհանուր ոճավորում (լռելյայն տառատեսակներ և այլն)
     */
    public function styles(Worksheet $sheet)
    {
        // Լռելյայն՝ տառատեսակ և տողի բարձրություն
        $sheet->getDefaultRowDimension()->setRowHeight(18);
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);

        // Գլխագրի տողի ոճը (A1:M1)
        return [
            1 => [
                'font'      => ['bold' => true, 'size' => 11],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F0FF']],
            ],
        ];
    }

    /**
     * AfterSheet՝ գեղագիտական վերջնական հարդարում
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet    = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();   // վերջին տողի ինդեքսը
                $highestCol = $sheet->getHighestColumn(); // օրինակ՝ 'M'

                $dataRange = "A1:{$highestCol}{$highestRow}";

                // Բոլոր բջիջների vertical alignment + wrap
                $sheet->getStyle($dataRange)->getAlignment()
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                // Բորդեր՝ բարակ սահմանագծեր ամբողջ աղյուսակի համար
                $sheet->getStyle($dataRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('B7B7B7');

                // Գումար սյունակ՝ աջ հավասարեցում
                $sheet->getStyle("L2:L{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // Freeze top row
                $sheet->freezePane('A2');

                // AutoFilter
                $sheet->setAutoFilter("A1:{$highestCol}1");

                // Տողի բարձրություն՝ partner name/account name սյունակների համար թեթև բարձր
                for ($r = 2; $r <= $highestRow; $r++) {
                    $sheet->getRowDimension($r)->setRowHeight(20);
                }

                // Թերթիկի անուն (ցանկության դեպքում՝ դինամիկ)
                $sheet->setTitle('Գործարքներ');

                // PageSetup՝ տպելու համար՝ Landscape + Fit to width
                $pageSetup = $sheet->getPageSetup();
                $pageSetup->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
                $pageSetup->setFitToWidth(1);
                $pageSetup->setFitToHeight(0);

                // Թվային ֆորմատ՝ գումար (եթե WithColumnFormatting-ը չի կիրառվի, սա backup)
                $sheet->getStyle("L2:L{$highestRow}")
                    ->getNumberFormat()->setFormatCode('#,##0');

                // «Excel Table» տեսք՝ գունային շաբլոնով (TableStyleMedium9)
                // ⚠️ Նշում. PhpSpreadsheet-ի Table API-ն պահանջում է եզակի անուն:
                $tableName = 'TxTable_' . uniqid('', false);
                $table = new Table("A1:{$highestCol}{$highestRow}", $tableName);
                $table->setShowRowStripes(true);
                $table->setShowFirstColumn(false);
                $table->setShowLastColumn(false);
                $table->setTableStyle(Table::TABLE_STYLE_MEDIUM9);

                // Table ավելացնել worksheet-ի մեջ (PhpSpreadsheet >= 1.23)
                // Եթե քո տարբերակում սա սխալ է տալիս, պարզապես թող այս բլոկը comment:
                try {
                    $sheet->addTable($table);
                } catch (\Throwable $e) {
                    // Խաղաղորեն անտեսում ենք, եթե տվյալ PhpSpreadsheet- ընտրված տարբերակը չի աջակցում addTable
                }

                // Սյունակների հորիզոնական հավասարեցում՝ ըստ բովանդակության
                $sheet->getStyle("A2:A{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // ամսաթիվ
                $sheet->getStyle("B2:B{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Փաստաթղթի N
                $sheet->getStyle("C2:C{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Տեսակ
                $sheet->getStyle("G2:G{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Դեբետ արժ.
                $sheet->getStyle("K2:K{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Կրեդիտ արժ.
                $sheet->getStyle("M2:M{$highestRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);   // Օգտագործող
            },
        ];
    }
}
