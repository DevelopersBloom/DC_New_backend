<?php
//
//namespace App\Exports;
//
//use App\Models\Transaction;
//use Illuminate\Support\Collection;
//use Illuminate\Support\Facades\DB;
//use Maatwebsite\Excel\Concerns\FromCollection;
//use Maatwebsite\Excel\Concerns\WithHeadings;
//use Maatwebsite\Excel\Concerns\WithMapping;
//use Maatwebsite\Excel\Concerns\WithEvents;
//use Maatwebsite\Excel\Events\AfterSheet;
//use PhpOffice\PhpSpreadsheet\Style\Alignment;
//use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
//
//class AccountsBalancesExport implements FromCollection, WithHeadings, WithMapping, WithEvents
//{
//    protected ?string $to;
//
//    public function __construct(?string $to = null)
//    {
//        $this->to = $to;
//    }
//
//
//    public function collection(): Collection
//    {
//        $debitQ = Transaction::query()
//            ->when($this->to, fn($q) => $q->where('date', '<=', $this->to))
//            ->selectRaw('debit_account_id as account_id, SUM(amount_amd) as delta')
//            ->groupBy('debit_account_id')
//            ->whereNotNull('debit_account_id');
//
//        $creditQ = Transaction::query()
//            ->when($this->to, fn($q) => $q->where('date', '<=', $this->to))
//            ->selectRaw('credit_account_id as account_id, -SUM(amount_amd) as delta')
//            ->groupBy('credit_account_id')
//            ->whereNotNull('credit_account_id');
//
//        $union = $debitQ->unionAll($creditQ);
//
//        $balances = DB::query()
//            ->fromSub($union, 'u')
//            ->join('chart_of_accounts as ca', 'ca.id', '=', 'u.account_id')
//            ->whereNull('ca.deleted_at')
//            ->select([
//                'u.account_id',
//                'ca.code',
//                'ca.name',
//                'ca.type',
//                DB::raw('SUM(u.delta) as balance')
//            ])
//            ->groupBy('u.account_id', 'ca.code', 'ca.name', 'ca.type')
//            ->orderBy('ca.code')
//            ->get();
//
//        return collect($balances->map(function ($r) {
//            $r->balance = (float) $r->balance;
//            return (array) $r;
//        })->all());
//    }
//
//    public function headings(): array
//    {
//        return [
//            'Հաշիվ',
//            'Արժույթ',
//            'Անվանում',
//            'Դեբետ արժ․',
//            'Կրեդիտ արժ․',
//            'Դեբետ Դրամով',
//            'Կրեդիտ Դրամով',
//        ];
//    }
//
//    /**
//     * Map one row to export columns applying the active/passive + sign rules
//     */
//    public function map($row): array
//    {
//        $code = $row['code'] ?? '';
//        $name = $row['name'] ?? '';
//        $type = $row['type'] ?? 'active';
//        $balance = (float) ($row['balance'] ?? 0);
//
//        $debitAmd = '';
//        $creditAmd = '';
//
//        if (in_array($type, ['active', 'expense', 'off_balance', 'contra_asset', 'contra'])) {
//            if ($balance >= 0) {
//                $debitAmd = round($balance, 6);
//            } else {
//                $creditAmd = round(abs($balance), 6);
//            }
//        } else {
//            if ($balance >= 0) {
//                $creditAmd = round($balance, 6);
//            } else {
//                $debitAmd = round(abs($balance), 6);
//            }
//        }
//
//        return [
//            $code,
//            'AMD',
//            $name,
//            '',
//            '',
//            $debitAmd,
//            $creditAmd,
//        ];
//    }
//
//    /**
//     * Styling & formatting (autosize, alignment, header bold, number format for columns F/G)
//     */
//    public function registerEvents(): array
//    {
//        return [
//            AfterSheet::class => function (AfterSheet $event) {
//                $sheet = $event->sheet->getDelegate();
//
//                // Autosize columns A..G
//                foreach (range('A', 'G') as $col) {
//                    $sheet->getColumnDimension($col)->setAutoSize(true);
//                }
//
//                $highestRow = $sheet->getHighestRow();
//
//                // Left align columns A..E, right align numeric columns F,G
//                foreach (range('A', 'E') as $col) {
//                    $sheet->getStyle("{$col}1:{$col}{$highestRow}")
//                        ->getAlignment()
//                        ->setHorizontal(Alignment::HORIZONTAL_LEFT);
//                }
//
//                foreach (['F', 'G'] as $col) {
//                    $sheet->getStyle("{$col}1:{$col}{$highestRow}")
//                        ->getAlignment()
//                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
//
//                    // number format for F and G (AMD)
//                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
//                        ->getNumberFormat()
//                        ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
//                }
//
//                // Bold header row
//                $sheet->getStyle('A1:G1')->getFont()->setBold(true);
//            },
//        ];
//    }
//}


namespace App\Exports;

use App\Traits\CalculatesAccountBalancesTrait;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class AccountsBalancesExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    use CalculatesAccountBalancesTrait;


    protected ?string $to;

    public function __construct(?string $to = null)
    {
        $this->to = $to;
    }

    public function collection(): Collection
    {
        $balances = $this->balancesSubquery($this->to)
            ->orderBy('ca.code')
            ->get();

        return collect($balances->map(function ($r) {
            return (array)$r;
        })->all());
    }

    public function headings(): array
    {
        return [
            'Հաշիվ',
            'Արժույթ',
            'Անվանում',
            'Դեբետ արժ․',
            'Կրեդիտ արժ․',
            'Դեբետ Դրամով',
            'Կրեդիտ Դրամով',
        ];
    }

    public function map($row): array
    {
        $code = $row['code'] ?? '';
        $name = $row['name'] ?? '';
        $type = $row['type'] ?? 'active';
        $balance = (float)($row['balance'] ?? 0);

        $debitAmd = '';
        $creditAmd = '';

        if (in_array($type, ['active', 'expense', 'off_balance'])) {
            if ($balance >= 0) {
                $debitAmd = round($balance, 6);
            } else {
                $creditAmd = round(abs($balance), 6);
            }
        } else {
            // passive, equity, income
            if ($balance >= 0) {
                $creditAmd = round($balance, 6);
            } else {
                $debitAmd = round(abs($balance), 6);
            }
        }

        return [
            $code,
            'AMD',
            $name,
            '',
            '',
            $debitAmd,
            $creditAmd,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                foreach (range('A', 'G') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $highestRow = $sheet->getHighestRow();

                foreach (range('A', 'E') as $col) {
                    $sheet->getStyle("{$col}1:{$col}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_LEFT);
                }

                foreach (['F', 'G'] as $col) {
                    $sheet->getStyle("{$col}1:{$col}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getNumberFormat()
                        ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                }

                $sheet->getStyle('A1:G1')->getFont()->setBold(true);
            },
        ];
    }
}
