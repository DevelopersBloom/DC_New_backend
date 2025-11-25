<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

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
        $sheet1->setCellValue('D10', 'Ակրեդիտ');
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

        $startDay = Carbon::parse($from)->day;
        $row = 9 + ($startDay - 1);

        $current = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($current->lte($end)) {
            $balance50000 = DocumentJournal::where('debit_account_id', $acc50000)
                    ->whereDate('date', $current)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc50000)
                    ->whereDate('date', $current)
                    ->sum('amount_amd');

            $balance52000 = DocumentJournal::where('debit_account_id', $acc52000)
                    ->whereDate('date', $current)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc52000)
                    ->whereDate('date', $current)
                    ->sum('amount_amd');

            $balance52001 = DocumentJournal::where('debit_account_id', $acc52001)
                    ->whereDate('date', $current)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc52001)
                    ->whereDate('date', $current)
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
            0   => 'B',
            10  => 'D',
            20  => 'F',
            30  => 'H',
            50  => 'J',
            75  => 'L',
            100 => 'N',
            110 => 'P',
            150 => 'R',
            225 => 'T',
        ];

        $row = 8;
        $current = Carbon::parse($from);

        while ($current->lte($end)) {

            $journals = DocumentJournal::with([
                'journalable.client.classification'
            ])
                ->whereDate('date', $current)
                ->where('comment', 'contract_payment') // ըստ քո business rule-ի
                ->get();

            // Risk-weight մեկ օրվա ընդհանուր գումարները
            $dailyAmounts = [
                0 => 0, 10 => 0, 20 => 0, 30 => 0,
                50 => 0, 75 => 0, 100 => 0, 110 => 0,
                150 => 0, 225 => 0
            ];

            foreach ($journals as $j) {

                $risk = optional(
                    $j->journalable->client->classification
                )->risk_weight;

                if ($risk === null) {
                    continue;
                }

                if (!isset($dailyAmounts[$risk])) {
                    continue;
                }

                $dailyAmounts[$risk] += $j->amount_amd / 1000;
            }

            // Գրել Excel-ում
            foreach ($dailyAmounts as $risk => $value) {
                $col = $riskColumns[$risk];
                $sheet3->setCellValue($col . $row, $value);
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
