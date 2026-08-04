<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\ClientClassification;
use App\Models\ClassificationHistory;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class V03Export
{
    public function export($from, $to, string $version = 'new')
    {
        // Load XLSX template
        $templateFile = $version === 'new' ? 'v03-04.xlsx' : 'v03.xlsx';
        $path = base_path($templateFile);
        $reader = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);

        // ---------------------------
        // SHEET 1
        // ---------------------------
        $sheet1 = $spreadsheet->getSheetByName('Sheet1');
        $sheet1->setCellValueExplicit('D10', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet1->getStyle('D10')->getFont()->setName('Sylfaen');
        $sheet1->setCellValue('D11', ExcelDate::PHPToExcel(Carbon::parse($from)->toDateTime()));
        $sheet1->setCellValue('F11', ExcelDate::PHPToExcel(Carbon::parse($to)->toDateTime()));

        // D16: client with largest gross loan on report end date, then risk formula on that loan
        $sheet1->setCellValue('D16', $this->computeD16Value($from, $to));
        $sheet1->setCellValue('E16', 50.3);

        // ---------------------------
        // SHEET 2
        // ---------------------------
        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
        $sheet2->setCellValue('C3', ExcelDate::PHPToExcel(Carbon::parse($from)->toDateTime()));
        $sheet2->setCellValue('E3', ExcelDate::PHPToExcel(Carbon::parse($to)->toDateTime()));

        $acc50000 = ChartOfAccount::idByCode('50000');
        $acc52000 = ChartOfAccount::idByCode('52000');
        $acc52001 = ChartOfAccount::idByCode('52001');

        $class6Ids = ChartOfAccount::where('code', 'like', '6%')->pluck('id')->toArray();
        $class7Ids = ChartOfAccount::where('code', 'like', '7%')->pluck('id')->toArray();

        $startDay = Carbon::parse($from)->day;
        $row = 9 + ($startDay - 1);

        $current = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($current->lte($end)) {
            $balance50000 = DocumentJournal::where('debit_account_id', $acc50000)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc50000)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');
            $sum6 = DocumentJournal::whereIn('credit_account_id', $class6Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('debit_account_id', $class6Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');

            $sum7 = DocumentJournal::whereIn('debit_account_id', $class7Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('credit_account_id', $class7Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');
            $balance52000 = $sum6 - $sum7;
//            $balance52000 = DocumentJournal::where('debit_account_id', $acc52000)
//                    ->where('date', '<=', $current)
//                    ->sum('amount_amd') * -1
//                + DocumentJournal::where('credit_account_id', $acc52000)
//                    ->where('date', '<=', $current)
//                    ->sum('amount_amd');

            $balance52001 = DocumentJournal::where('debit_account_id', $acc52001)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc52001)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');

            $finalSum = $balance50000 + $balance52000 + $balance52001;

            $sheet2->setCellValue('B' . $row, $finalSum / 1000);

            $current->addDay();
            $row++;
        }

        // ---------------------------
        // SHEET 3
        // ---------------------------
        $sheet3 = $spreadsheet->getSheetByName('Sheet3');

        $riskColumns = [
            0   => 'B', 10  => 'D', 20  => 'F', 30  => 'H',
            50  => 'J', 75  => 'L', 85 => 'N', 100 => 'P',
            110 => 'R', 125 => 'T', 150 => 'V', 225 => 'X',
        ];

        $activeAccountsWithRisk = ChartOfAccount::where('type', 'active')
            ->whereNotNull('risk_weight')
            ->get();

        $acc2100Id = ChartOfAccount::idByCode('2100');
        $acc2101Id = ChartOfAccount::idByCode('2101');
        $acc2200Id = ChartOfAccount::idByCode('2200');
        $acc2211Id = ChartOfAccount::idByCode('2211');

        $current = Carbon::parse($from);
        $end = Carbon::parse($to);
        $row = 8 + ($current->day - 1);

        while ($current->lte($end)) {
            $dailyData = [];
            foreach ($riskColumns as $risk => $col) {
                $dailyData[$risk] = ['amount' => 0, 'reserve' => 0];
            }

            foreach ($activeAccountsWithRisk as $account) {
                $accId = $account->id;
                $riskKey = (int) $account->risk_weight;
                if (!isset($riskColumns[$riskKey])) continue;

                $balance = DocumentJournal::where('debit_account_id', $accId)
                        ->where('date', '<=', $current)
                        ->sum('amount_amd')
                    - DocumentJournal::where('credit_account_id', $accId)
                        ->where('date', '<=', $current)
                        ->sum('amount_amd');

                if ($accId == $acc2100Id) {
                    $subBalance = DocumentJournal::where('debit_account_id', $acc2101Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd')
                        - DocumentJournal::where('credit_account_id', $acc2101Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd');
                    $balance -= $subBalance;
                }

                if ($accId == $acc2200Id) {
                    $subBalance = DocumentJournal::where('debit_account_id', $acc2211Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd')
                        - DocumentJournal::where('credit_account_id', $acc2211Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd');
                    $balance -= $subBalance;
                }

                $dailyData[$riskKey]['amount'] += $balance;
                $dailyData[$riskKey]['reserve'] += ($balance * 0.01);
            }

            $loanPortionIds = array_values(array_filter([
                ChartOfAccount::idByCode('16200'),
                ChartOfAccount::idByCode('16200NV'),
                ChartOfAccount::idByCode('16201NI'),
            ]));

            if ($loanPortionIds !== []) {
//                $debit = DB::table('transactions as t')
//                    ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
//                    ->whereNull('t.deleted_at')
//                    ->whereNotNull('t.debit_partner_id')
//                    ->whereIn('t.debit_account_id', $loanPortionIds)
//                    ->whereDate('t.date', '<=', $current->format('Y-m-d'))
//                    ->selectRaw("
//                        t.debit_partner_id as partner_id,
//                        SUM(
//                            CASE
//                                WHEN a.type IN ('active','expense','off_balance') THEN t.amount_amd
//                                ELSE -t.amount_amd
//                            END
//                        ) as amount
//                    ")
//                    ->groupBy('t.debit_partner_id');
//
//                $credit = DB::table('transactions as t')
//                    ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
//                    ->whereNull('t.deleted_at')
//                    ->whereNotNull('t.credit_partner_id')
//                    ->whereIn('t.credit_account_id', $loanPortionIds)
//                    ->whereDate('t.date', '<=', $current->format('Y-m-d'))
//                    ->selectRaw("
//                        t.credit_partner_id as partner_id,
//                        SUM(
//                            CASE
//                                WHEN a.type IN ('active','expense','off_balance') THEN -t.amount_amd
//                                ELSE t.amount_amd
//                            END
//                        ) as amount
//                    ")
//                    ->groupBy('t.credit_partner_id');
                $debit = DB::table('documents_journal')
                    ->whereNull('deleted_at')
                    ->whereNotNull('debit_partner_id')
                    ->whereIn('debit_account_id', $loanPortionIds)
                    ->whereDate('date', '<=', $current->format('Y-m-d'))
                    ->selectRaw('debit_partner_id as partner_id, SUM(amount_amd) as amount')
                    ->groupBy('debit_partner_id');

                $credit = DB::table('documents_journal')
                    ->whereNull('deleted_at')
                    ->whereNotNull('credit_partner_id')
                    ->whereIn('credit_account_id', $loanPortionIds)
                    ->whereDate('date', '<=', $current->format('Y-m-d'))
                    ->selectRaw('credit_partner_id as partner_id, SUM(-amount_amd) as amount')
                    ->groupBy('credit_partner_id');

                $partnerBalances = DB::query()
                    ->fromSub($debit->unionAll($credit), 'x')
                    ->selectRaw("
                        partner_id,
                        SUM(amount) as balance
                    ")
                    ->groupBy('partner_id')
                    ->having('balance', '>', 0)
                    ->get()
                    ->keyBy('partner_id');
                foreach ($partnerBalances as $partnerData) {
                    $partnerId = $partnerData->partner_id;
                    if (!$partnerId) continue;

                    $classification = ClassificationHistory::where('client_id', $partnerId)
                        ->whereDate('date', '<=', $current->format('Y-m-d'))
                        ->orderBy('date', 'desc')
                        ->first();

                    if (!$classification) {
                        $client = \App\Models\Client::find($partnerId);
                        $classification = $client?->classification;
                    }

                    if (!$classification) continue;

                    $riskKey = (int) $classification->risk_weight;
                    if (!isset($riskColumns[$riskKey])) continue;

                    $amount = (float) $partnerData->balance;
                    $reservePercent = (float) ($classification->reserve_percent ?? 0);

                    $dailyData[$riskKey]['amount'] += $amount;
                    $dailyData[$riskKey]['reserve'] += ($amount * $reservePercent / 100);
                }
            }
            foreach ($dailyData as $risk => $values) {
                $col = $riskColumns[$risk];
                $sheet3->setCellValue($col . $row, $values['amount'] / 1000);
                $nextCol = ++$col;
                $sheet3->setCellValue($nextCol . $row, $values['reserve'] / 1000);
            }

            $current->addDay();
            $row++;
        }
        // ---------------------------
        // SHEET 6
        // ---------------------------
        $sheet6 = $spreadsheet->getSheetByName('Sheet6');
        $sheet6->setCellValue('E10', '=IF(D10<=4,3,IF(D10=5,3.4,IF(D10=6,3.5,IF(D10=7,3.65,IF(D10=8,3.75,IF(D10=9,3.85,4))))))');

        // ---------------------------
        // SAVE XLS
        // ---------------------------
        $fileName = 'v03_export_' . now()->format('Ymd_His') . '.xlsx';
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    /**
     * D16: client with largest 16200NV partner balance on report end date; reserve from 16605PC/16605PS partner balance.
     */
    private function computeD16Value(string $from, string $to): int
    {
        $asOfDate = Carbon::parse($to)->format('Y-m-d');
        $debtsByClient = $this->clientLoanDebtsAsOf($asOfDate);

        if ($debtsByClient === []) {
            return 0;
        }

        arsort($debtsByClient, SORT_NUMERIC);
        $clientId = (int) array_key_first($debtsByClient);
        $debt = $debtsByClient[$clientId];

        $classification = ClassificationHistory::where('client_id', $clientId)
            ->whereDate('date', '<=', $asOfDate)
            ->orderBy('date', 'desc')
            ->first();

        if (!$classification) {
            $classification = Client::find($clientId)?->classification;
        }

        if (!$classification) {
            return 0;
        }

        $riskWeight = (float) ($classification->risk_weight ?? 0) / 100;
        $reserveAccountId = $this->reserveAccountIdForClassification($classification);
        $reserve = $reserveAccountId
            ? $this->clientReserveBalanceAsOf($clientId, $reserveAccountId, $asOfDate)
            : 0.0;

        return (int) round((($debt - $reserve) / 1000) * $riskWeight);
    }

    /**
     * Standard → 16605PC; all other classes → 16605PS (same as reserve posting elsewhere).
     */
    private function reserveAccountIdForClassification(
        ClassificationHistory|ClientClassification $classification
    ): ?int {
        $name = $classification instanceof ClassificationHistory
            ? ($classification->classification?->name
                ?? ClientClassification::find($classification->classification_id)?->name)
            : $classification->name;

        $code = $name === 'standard' ? '16605PC' : '16605PS';

        return ChartOfAccount::idByCode($code);
    }

    /**
     * Partner reserve balance on 16605PC / 16605PS (debit − credit), same as getClientReserveBalance elsewhere.
     */
    private function clientReserveBalanceAsOf(int $clientId, int $accountId, string $asOfDate): float
    {
        $debit = (float) DB::table('documents_journal')
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $clientId)
            ->where('debit_account_id', $accountId)
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount_amd');

        $credit = (float) DB::table('documents_journal')
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $clientId)
            ->where('credit_account_id', $accountId)
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount_amd');

        $balance = $debit - $credit;

        // Reserve is posted as partner credit; use credit balance as positive reserve amount.
        return $balance < 0 ? -$balance : 0.0;
    }

    /**
     * Gross per-client loan balance on 16200NV only as of date (debit − credit).
     * Classification is intentionally excluded here so selection uses loan size only.
     *
     * @return array<int, float> client_id => gross debt
     */
    private function clientLoanDebtsAsOf(string $asOfDate): array
    {
        $loanAccountId = ChartOfAccount::idByCode('16200NV');

        if (!$loanAccountId) {
            return [];
        }

        $loanPortionIds = [$loanAccountId];

        $debit = DB::table('documents_journal')
            ->whereNull('deleted_at')
            ->whereNotNull('debit_partner_id')
            ->whereIn('debit_account_id', $loanPortionIds)
            ->whereDate('date', '<=', $asOfDate)
            ->selectRaw('debit_partner_id as partner_id, SUM(amount_amd) as amount')
            ->groupBy('debit_partner_id');

        $credit = DB::table('documents_journal')
            ->whereNull('deleted_at')
            ->whereNotNull('credit_partner_id')
            ->whereIn('credit_account_id', $loanPortionIds)
            ->whereDate('date', '<=', $asOfDate)
            ->selectRaw('credit_partner_id as partner_id, SUM(-amount_amd) as amount')
            ->groupBy('credit_partner_id');

        $debtsByClient = [];
        foreach (
            DB::query()
                ->fromSub($debit->unionAll($credit), 'x')
                ->selectRaw('partner_id, SUM(amount) as balance')
                ->groupBy('partner_id')
                ->having('balance', '>', 0)
                ->get() as $row
        ) {
            $clientId = (int) $row->partner_id;
            if ($clientId) {
                $debtsByClient[$clientId] = (float) $row->balance;
            }
        }

        return $debtsByClient;
    }
}
