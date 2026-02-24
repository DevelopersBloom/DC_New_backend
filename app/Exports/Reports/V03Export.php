<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class V03Export
{
    public function export($from, $to)
    {
        // Load XLSX template
        $path = base_path('v03.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        // ---------------------------
        // SHEET 1
        // ---------------------------
        $sheet1 = $spreadsheet->getSheetByName('Sheet1');
        $sheet1->setCellValueExplicit('D10', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet1->setCellValue('D11', ExcelDate::PHPToExcel(Carbon::parse($from)->toDateTime()));
        $sheet1->setCellValue('F11', ExcelDate::PHPToExcel(Carbon::parse($to)->toDateTime()));

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
//
//        $sheet3 = $spreadsheet->getSheetByName('Sheet3');
//
//        $riskColumns = [
//            0   => 'B', 10  => 'D', 20  => 'F', 30  => 'H',
//            50  => 'J', 75  => 'L', 100 => 'N', 110 => 'P',
//            150 => 'R', 225 => 'T',
//        ];
//
//        $activeAccountsWithRisk = ChartOfAccount::where('type', 'active')
//            ->whereNotNull('risk_weight')
//            ->get();
//
//        $acc2100Id = ChartOfAccount::idByCode('2100');
//        $acc2101Id = ChartOfAccount::idByCode('2101');
//        $acc2200Id = ChartOfAccount::idByCode('2200');
//        $acc2211Id = ChartOfAccount::idByCode('2211');
//
//        $current = Carbon::parse($from);
//        $end = Carbon::parse($to);
//        $row = 8 + ($current->day - 1);
//
//        while ($current->lte($end)) {
//            $dailyAmounts = array_fill_keys(array_keys($riskColumns), 0);
//
//            foreach ($activeAccountsWithRisk as $account) {
//                $accId = $account->id;
//                $riskKey = (int) $account->risk_weight;
//                if (!isset($riskColumns[$riskKey])) continue;
//
//                $balance = DocumentJournal::where('debit_account_id', $accId)
//                        ->where('date', '<=', $current)
//                        ->sum('amount_amd')
//                    - DocumentJournal::where('credit_account_id', $accId)
//                        ->where('date', '<=', $current)
//                        ->sum('amount_amd');
//
//                if ($accId == $acc2100Id) {
//                    $subBalance = DocumentJournal::where('debit_account_id', $acc2101Id)
//                            ->where('date', '<=', $current)
//                            ->sum('amount_amd')
//                        - DocumentJournal::where('credit_account_id', $acc2101Id)
//                            ->where('date', '<=', $current)
//                            ->sum('amount_amd');
//                    $balance -= $subBalance;
//                }
//
//                if ($accId == $acc2200Id) {
//                    $subBalance = DocumentJournal::where('debit_account_id', $acc2211Id)
//                            ->where('date', '<=', $current)
//                            ->sum('amount_amd')
//                        - DocumentJournal::where('credit_account_id', $acc2211Id)
//                            ->where('date', '<=', $current)
//                            ->sum('amount_amd');
//                    $balance -= $subBalance;
//                }
//
//                $dailyAmounts[$riskKey] += $balance;
//            }
//
//            $journals = DocumentJournal::with(['journalable.client.classification'])
//                ->where('date', '<=', $current->format('Y-m-d'))
//                ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//                ->get();
//
//            foreach ($journals as $j) {
//                $classification = optional(optional($j->journalable)->client)->classification;
//                if ($classification && isset($riskColumns[(int)$classification->risk_weight])) {
//                    $riskKey = (int)$classification->risk_weight;
//                    $dailyAmounts[$riskKey] += $j->amount_amd;
//                }
//            }
//
////            foreach ($dailyAmounts as $risk => $value) {
////                $col = $riskColumns[$risk];
////
////                $sheet3->setCellValue($col . $row, $value / 1000);
////            }
//            foreach ($dailyAmounts as $risk => $value) {
//                $col = $riskColumns[$risk];
//                $finalValue = $value / 1000;
//
//                $sheet3->setCellValue($col . $row, $finalValue);
//
//                if ($col === 'F') {
//                    $targetCol = 'G';
//                    $percentValue = $finalValue * 0.01;
//                    $sheet3->setCellValue($targetCol . $row, $percentValue);
//                }
//
//            }
//            $current->addDay();
//            $row++;
//        }
        $sheet3 = $spreadsheet->getSheetByName('Sheet3');

        $riskColumns = [
            0   => 'B', 10  => 'D', 20  => 'F', 30  => 'H',
            50  => 'J', 75  => 'L', 100 => 'N', 110 => 'P',
            150 => 'R', 225 => 'T',
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
            }

            $journals = DocumentJournal::with(['journalable.client.classification'])
                ->where('date', '<=', $current->format('Y-m-d'))
                ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
                ->get();

            foreach ($journals as $j) {
                $classification = optional(optional($j->journalable)->client)->classification;
                if ($classification && isset($riskColumns[(int)$classification->risk_weight])) {
                    $riskKey = (int)$classification->risk_weight;
                    $amount = $j->amount_amd;

                    $dailyData[$riskKey]['amount'] += $amount;

                    $reservePercent = $classification->reserve_percent ?? 0;
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
}
