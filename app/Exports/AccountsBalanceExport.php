<?php

namespace App\Exports;

use App\Models\Transaction;
use App\Models\ChartOfAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AccountsBalanceExport implements FromCollection, WithHeadings, WithMapping,WithEvents
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
            'Արժույթ',
            'Անվանում',
            'Դեբետ արժ․',
            'Կրեդիտ արժ․',
            'Դեբետ Դրամով',
            'Կրեդիտ Դրամով',
        ];
    }

//    public function columnFormats(): array
//    {
//        return [
//            'C' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1,
//        ];
//    }

    public function collection(): Collection
    {
        $accounts = ChartOfAccount::query()->select('id', 'code', 'name', 'type')->get();

        $accById = $accounts->keyBy('id');

        $isDebitPositive = function (?string $type): bool {
            return in_array($type, ['active', 'expense', 'off_balance', 'contra_asset', 'contra']);
        };


        $tx = Transaction::query()
            ->when($this->from, fn($q) => $q->where('date', '>=', $this->from))
            ->when($this->to, fn($q) => $q->where('date', '<=', $this->to))
            ->select([
                'debit_account_id',
                'credit_account_id',
                DB::raw('SUM(amount_amd) as sum_amd'),
            ])
            ->groupBy('debit_account_id', 'credit_account_id')
            ->get();

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

        $accBalance = []; // [account_id => balance_amd]

        foreach ($accById as $id => $acc) {
            $d = (float)($debits[$id] ?? 0);
            $c = (float)($credits[$id] ?? 0);

            if ($isDebitPositive($acc->type)) {
                $bal = $d - $c; // Active/Expense/Off-balance
            } else {
                $bal = $c - $d; // Passive/Equity/Income
            }

            if (abs($bal) > 0.000001) {
                $accBalance[$id] = $bal;
            }
        }

        $getBase5 = function (string $code): ?string {
            if (preg_match('/^(\d{5})/', $code, $m)) {
                return $m[1];
            }
            return null;
        };

        $isAlphaChild = function (string $code): bool {
            return (bool)preg_match('/^\d{5}[A-Za-z].*$/', $code);
        };

        $isPureNumericChild = function (string $code): bool {
            return (bool)preg_match('/^\d{6,}$/', $code);
        };

        $base5Sums = [];
        $alphaRows = [];

        foreach ($accBalance as $id => $amount) {
            $acc = $accById[$id];
            $code = (string)$acc->code;
            $name = (string)$acc->name;

            $base5 = $getBase5($code);
            if (!$base5) {
                continue;
            }

            if (!isset($base5Sums[$base5])) {
                $base5Sums[$base5] = 0.0;
            }
            $base5Sums[$base5] += $amount;

            if ($isAlphaChild($code)) {
                $alphaRows[] = [
                    'code' => $code,
                    'name' => $name,
                    'amount' => $amount,
                ];
            }
        }

        $base5Names = ChartOfAccount::query()
            ->whereRaw('code REGEXP "^[0-9]{5}$"')
            ->pluck('name', 'code'); // ['10210' => 'Անվանում']

        $rows = new Collection();

        ksort($base5Sums, SORT_NATURAL);

        foreach ($base5Sums as $code5 => $amount) {
            if (abs($amount) < 0.000001) {
                continue;
            }
            $rows->push([
                'code' => $code5,
                'name' => (string)($base5Names[$code5] ?? ''),
                'amount' => round($amount, 0),
            ]);
        }

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
        $acc = ChartOfAccount::where('code', $row['code'])->first();
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
            $row['code'],
            'AMD',
            $row['name'],
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
                foreach (range('A', 'G') as $col) {
                    $sheet->getStyle("{$col}1:{$col}{$highestRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_LEFT);
                }

                $sheet->getStyle('A1:G1')->getFont()->setBold(true);
            },
        ];
    }
}

