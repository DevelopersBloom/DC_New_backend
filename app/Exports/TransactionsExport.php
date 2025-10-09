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
//use PhpOffice\PhpSpreadsheet\Worksheet\Table;
//use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
//use Carbon\Carbon;
//
//class TransactionsExport implements
//    FromCollection,
//    WithHeadings,
//    WithMapping,
//    WithEvents,
//    WithStyles,
//    WithColumnFormatting,
//    ShouldAutoSize
//
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
//    /**
//     * Տվյալների լցնում
//     * @return Collection
//     */
//    public function collection(): Collection
//    {
//        $query = Transaction::with([
//            'debitAccount:id,code,name',
//            'creditAccount:id,code,name',
//            'debitCurrency:id,code',
//            'creditCurrency:id,code',
//            'amountCurrencyRelation:id,code',
//            'user:id,name,surname',
//            // NEW: partner-ների հարաբերությունները
//            'debitPartner:id,type,name,surname,company_name,tax_number,social_card_number',
//            'creditPartner:id,type,name,surname,company_name,tax_number,social_card_number',
//        ])->select([
//            'id','date','document_number','document_type',
//            'amount_amd','amount_currency','amount_currency_id',
//            'debit_account_id','credit_account_id','user_id',
//            // NEW: վերցնենք ID-ները, ոչ թե չկան սյունակներ
//            'debit_partner_id','credit_partner_id',
//            'debit_currency_id','credit_currency_id',
//        ]);
//
//        if ($this->from && $this->to) {
//            $query->whereBetween('date', [$this->from, $this->to]);
//        } elseif ($this->from) {
//            $query->where('date', '>=', $this->from);
//        } elseif ($this->to) {
//            $query->where('date', '<=', $this->to);
//        }
//
//        return $query->orderBy('date','desc')->get();
//    }
//
//    /**
//     * Գլխագրեր (Հայերեն)
//     */
//    public function headings(): array
//    {
//        return [
//            'Ամսաթիվ',
//            'Փաստաթղթի N',
//            'Փաստաթղթի Տեսակ',
//            'Դեբետ հաշիվ',
//            'Դեբետ գործ․ կոդ',
//            'Դեբետ գործընկեր անվանում',
//            'Դեբետ արժ․',
//            'Կրեդիտ հաշիվ',
//            'Կրեդիտ գործ․ կոդ',
//            'Կրեդիտ գործընկեր անվանում',
//            'Կրեդիտ արժ․',
//            'Գումար (դրամով)',
//            'Օգտագործող',
//        ];
//    }
//
//    /**
//     * Տողերի քարտեզավորում
//     */
//
//
//    public function map($tx): array
//    {
//        $excelDate = null;
//        if (!empty($tx->date)) {
//            $excelDate = ExcelDate::dateTimeToExcel(Carbon::parse($tx->date));
//        }
//
//        $debitPartnerCode = null;
//        $debitPartnerName = null;
//        if ($tx->debitPartner) {
//            $p = $tx->debitPartner;
//            $debitPartnerCode = $p->type === 'individual'
//                ? ($p->social_card_number ?? null)
//                : ($p->tax_number ?? null);
//
//            $debitPartnerName = $p->type === 'legal'
//                ? ($p->company_name ?? '')
//                : trim(($p->name ?? '') . ' ' . ($p->surname ?? ''));
//        }
//
//        $creditPartnerCode = null;
//        $creditPartnerName = null;
//        if ($tx->creditPartner) {
//            $p = $tx->creditPartner;
//            $creditPartnerCode = $p->type === 'individual'
//                ? ($p->social_card_number ?? null)
//                : ($p->tax_number ?? null);
//
//            $creditPartnerName = $p->type === 'legal'
//                ? ($p->company_name ?? '')
//                : trim(($p->name ?? '') . ' ' . ($p->surname ?? ''));
//        }
//
//        return [
//            $excelDate,
//            $tx->document_number,
//            $tx->document_type,
//
//            trim((optional($tx->debitAccount)->code ?? '') . ' ' . (optional($tx->debitAccount)->name ?? '')),
//            $debitPartnerCode,
//            $debitPartnerName,
//            optional($tx->debitCurrency)->code,
//
//            trim((optional($tx->creditAccount)->code ?? '') . ' ' . (optional($tx->creditAccount)->name ?? '')),
//            $creditPartnerCode,
//            $creditPartnerName,
//            optional($tx->creditCurrency)->code,
//
//            $tx->amount_amd,
//
//            trim((optional($tx->user)->name ?? '') . ' ' . (optional($tx->user)->surname ?? '')),
//        ];
//    }
//
//    public function columnFormats(): array
//    {
//        return [
//            'A' => NumberFormat::FORMAT_DATE_YYYYMMDD2, // Date serial ցուցադրում
//            'L' => '#,##0',                             // Amount AMD
//        ];
//    }
//
////    public function columnWidths(): array
////    {
////        return [
////            'A' => 12,  // Ամսաթիվ
////            'B' => 16,  // Փաստաթղթի N
////            'C' => 18,  // Փաստաթղթի Տեսակ
////            'D' => 38,  // Դեբետ հաշիվ (կոդ + անվանում)
////            'E' => 16,  // Դեբետ գործ․ կոդ
////            'F' => 28,  // Դեբետ գործընկեր անվանում
////            'G' => 10,  // Դեբետ արժ․
////            'H' => 38,  // Կրեդիտ հաշիվ
////            'I' => 16,  // Կրեդիտ գործ․ կոդ
////            'J' => 28,  // Կրեդիտ գործընկեր անվանում
////            'K' => 10,  // Կրեդիտ արժ․
////            'L' => 18,  // Գումար (դրամով)
////            'M' => 20,  // Օգտագործող
////        ];
////    }
//
//    /**
//     * Ընդհանուր ոճավորում (լռելյայն տառատեսակներ և այլն)
//     */
//    public function styles(Worksheet $sheet)
//    {
//        $sheet->getDefaultRowDimension()->setRowHeight(18);
//        $sheet->getParent()->getDefaultStyle()->getFont()->setName('DejaVu Sans')->setSize(10);
//
//        return [
//            1 => [
//                'font'      => ['bold' => true, 'size' => 11],
//                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
//                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F0FF']],
//            ],
//        ];
//    }
//
//    public function registerEvents(): array
//    {
//        return [
//            AfterSheet::class => function (AfterSheet $event) {
//                $sheet      = $event->sheet->getDelegate();
//                $highestRow = $sheet->getHighestRow();
//                $highestCol = $sheet->getHighestColumn(); // e.g. 'M'
//                $dataRange  = "A1:{$highestCol}{$highestRow}";
//                $headerRange = "A1:{$highestCol}1";
//
//                // Header style
//                $sheet->getStyle($headerRange)->applyFromArray([
//                    'font'      => ['bold' => true, 'size' => 11],
//                    'alignment' => [
//                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
//                        'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
//                        'wrapText'   => true,
//                    ],
//                    'fill'      => [
//                        'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
//                        'startColor' => ['rgb' => 'E6F0FF'],
//                    ],
//                ]);
//
//                // Body: vertical center + wrap
//                $sheet->getStyle($dataRange)->getAlignment()
//                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
//                    ->setWrapText(true);
//
//                // Borders
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
//                // Column alignments
//                $sheet->getStyle("A2:A{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Ամսաթիվ
//                $sheet->getStyle("B2:B{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Փաստաթղթի N
//                $sheet->getStyle("C2:C{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Տեսակ
//                $sheet->getStyle("G2:G{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Դեբետ արժ.
//                $sheet->getStyle("K2:K{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER); // Կրեդիտ արժ.
//                $sheet->getStyle("L2:L{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);  // Գումար
//                $sheet->getStyle("M2:M{$highestRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);   // Օգտագործող
//
//                // Freeze header
//                $sheet->freezePane('A2');
//
//                // Autofilter
//                $sheet->setAutoFilter("A1:{$highestCol}1");
//
//                // Sheet title
//                $sheet->setTitle('Գործարքներ');
//
//                // Page setup
//                $pageSetup = $sheet->getPageSetup();
//                $pageSetup->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
//                $pageSetup->setFitToWidth(1);
//                $pageSetup->setFitToHeight(0);
//
//                // Amount number format
//                $sheet->getStyle("L2:L{$highestRow}")
//                    ->getNumberFormat()->setFormatCode('#,##0');
//
//                // --- AutoSize columns by content (keep only if you DON'T set fixed widths) ---
//                foreach (range('A', $highestCol) as $col) {
//                    $sheet->getColumnDimension($col)->setAutoSize(true);
//                }
//            },
//        ];
//    }
//}


namespace App\Exports;

use App\Models\Transaction;
use App\Models\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;

class TransactionsExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting
{
    public function __construct(
        protected ?string $from = null,
        protected ?string $to = null,
    )
    {
    }

    public function headings(): array
    {
        return [
            'Հաշիվ',
            'Անվանում',
            'Մնացորդ (դրամ)',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // #,##0.0 by default; change if you want 0 decimals
        ];
    }

    public function collection(): Collection
    {
        // 1) Բերում ենք բոլոր հաշիվները (id, code, name, type) map-ի մեջ
        $accounts = ChartOfAccount::query()->select('id', 'code', 'name', 'account_type')->get();

        $accById = $accounts->keyBy('id');

        // Օգնական՝ account_type ⇒ sign rule
        $isDebitPositive = function (?string $type): bool {
            // Արագ հարմարեցում՝ եթե ունես այլ անվանում contra-asset՝ ավելացրու այստեղ
            return in_array($type, ['active', 'expense', 'off_balance', 'contra_asset', 'contra']);
        };

        // 2) Ընդհանուր debit/credit գումարներ ամեն հաշվեհամարի համար ընտրված ժամանակահատվածում
        $tx = Transaction::query()
            ->when($this->from, fn($q) => $q->where('date', '>=', $this->from))
            ->when($this->to, fn($q) => $q->where('date', '<=', $this->to))
            ->select([
                'debit_account_id',
                'credit_account_id',
                DB::raw('SUM(amount_amd) as sum_amd'),
            ])
            // Խմբավորումը կանիք առանձին-query-ներով՝ դեբետ/կրեդիտ
            ->get(); // սա չենք օգտագործի; կանենք երկու aggregate query՝ վերևից պարզ է, բայց կգրենք երկու query կարգին

        $debits = Transaction::query()
            ->when($this->from, fn($q) => $q->where('date', '>=', $this->from))
            ->when($this->to, fn($q) => $q->where('date', '<=', $this->to))
            ->select('debit_account_id as account_id', DB::raw('SUM(amount_amd) as d_sum'))
            ->groupBy('debit_account_id')
            ->pluck('d_sum', 'account_id');

        $credits = Transaction::query()
            ->when($this->from, fn($q) => $q->where('date', '>=', $this->from))
            ->when($this->to, fn($q) => $q->where('date', '<=', $this->to))
            ->select('credit_account_id as account_id', DB::raw('SUM(amount_amd) as c_sum'))
            ->groupBy('credit_account_id')
            ->pluck('c_sum', 'account_id');

        // 3) Հաշվում ենք account-wise մնացորդներ
        $accBalance = []; // [account_id => balance_amd]

        foreach ($accById as $id => $acc) {
            $d = (float)($debits[$id] ?? 0);
            $c = (float)($credits[$id] ?? 0);

            if ($isDebitPositive($acc->account_type)) {
                $bal = $d - $c; // Active/Expense/Off-balance
            } else {
                $bal = $c - $d; // Passive/Equity/Income
            }

            if (abs($bal) > 0.000001) {
                $accBalance[$id] = $bal;
            }
        }

        // 4) Օգնական regex-ներ
        $getBase5 = function (string $code): ?string {
            // Առաջին 5 թվանշանը սկզբից
            if (preg_match('/^(\d{5})/', $code, $m)) {
                return $m[1];
            }
            return null;
        };

        $isAlphaChild = function (string $code): bool {
            // 5 թվանշան + առնվազն 1 տառ (օր՝ 10210NI, 10210A, 10210US1, և այլն)
            return (bool)preg_match('/^\d{5}[A-Za-z].*$/', $code);
        };

        $isPureNumericChild = function (string $code): bool {
            // ավելի քան 5 նիշ և բոլորը թվեր (օր՝ 102101, 10210101, …)
            return (bool)preg_match('/^\d{6,}$/', $code);
        };

        // 5) Կառուցում ենք երկու dataset
        $base5Sums = [];    // ['10210' => amount]
        $alphaRows = [];    // [['code'=>..., 'name'=>..., 'amount'=>...],...]

        foreach ($accBalance as $id => $amount) {
            $acc = $accById[$id];
            $code = (string)$acc->code;
            $name = (string)$acc->name;

            $base5 = $getBase5($code);
            if (!$base5) {
                continue; // օրինակ՝ եթե կոդը չսկսվի թվով կամ չունենա 5 թվանշան
            }

            // Խմբային գումարում՝ ԲՈԼՈՐ հաշիվների համար
            if (!isset($base5Sums[$base5])) {
                $base5Sums[$base5] = 0.0;
            }
            $base5Sums[$base5] += $amount;

            // Ալֆա-թվայինները՝ առանձին տողով (բայց միևնույն ժամանակ արդեն մտավ base5-ի մեջ)
            if ($isAlphaChild($code)) {
                $alphaRows[] = [
                    'code' => $code,
                    'name' => $name,
                    'amount' => $amount,
                ];
            }
            // Մաքուր թվային child-երը չենք ավելացնում առանձին rows (միայն base5-ում են)
        }

        // 6) Բերում ենք base5 հաշիվների անվանումները՝ հենց 5 թվանշանով code ունեցող հաշիվներից
        $base5Names = ChartOfAccount::query()
            ->whereRaw('code REGEXP "^[0-9]{5}$"')
            ->pluck('name', 'code'); // ['10210' => 'Անվանում']

        // 7) Վերջնական հավաքածու
        $rows = new Collection();

        // base5 տողերը՝ ըստ code-ի աճման կարգով
        ksort($base5Sums, SORT_NATURAL);

        foreach ($base5Sums as $code5 => $amount) {
            if (abs($amount) < 0.000001) {
                continue; // 0-ականները չցուցադրել
            }
            $rows->push([
                'code' => $code5,
                'name' => (string)($base5Names[$code5] ?? ''),
                'amount' => round($amount, 0), // փոխիր կլորացումը ըստ կարիքի
            ]);
        }

        // ալֆա-թվային տողերը՝ code ա/կ
        usort($alphaRows, fn($a, $b) => strcmp($a['code'], $b['code']));
        foreach ($alphaRows as $r) {
            if (abs($r['amount']) < 0.000001) continue;
            $rows->push([
                'code' => $r['code'],
                'name' => $r['name'],
                'amount' => round($r['amount'], 0),
            ]);
        }

        return $rows;
    }

    public function map($row): array
    {
        return [
            $row['code'],
            $row['name'],
            $row['amount'],
        ];
    }
}
