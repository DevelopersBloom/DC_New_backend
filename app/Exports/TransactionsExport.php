<?php

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
        $accounts = ChartOfAccount::query()->select('id', 'code', 'name', 'type')->get();

        $accById = $accounts->keyBy('id');

        $isDebitPositive = function (?string $type): bool {
            return in_array($type, ['active', 'expense', 'off_balance', 'contra_asset', 'contra']);
        };

//        $tx = Transaction::query()
//            ->when($this->from, fn($q) => $q->where('date', '>=', $this->from))
//            ->when($this->to, fn($q) => $q->where('date', '<=', $this->to))
//            ->select([
//                'debit_account_id',
//                'credit_account_id',
//                DB::raw('SUM(amount_amd) as sum_amd'),
//            ])
//            // Խմբավորումը կանիք առանձին-query-ներով՝ դեբետ/կրեդիտ
//            ->get(); // սա չենք օգտագործի; կանենք երկու aggregate query՝ վերևից պարզ է, բայց կգրենք երկու query կարգին
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

        // 3) Հաշվում ենք account-wise մնացորդներ
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

