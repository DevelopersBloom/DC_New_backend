<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

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
        $sheet1->setCellValue('D11', Carbon::parse($from)->format('d.m.Y'));
        $sheet1->setCellValue('F11', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(Carbon::parse($to)->toDateTime()));

        // ---------------------------
        // SHEET 2
        // ---------------------------
        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
        $sheet2->setCellValue('C3', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(Carbon::parse($from)->toDateTime()));
        $sheet2->setCellValue('E3', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(Carbon::parse($to)->toDateTime()));

        $start = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->endOfMonth();

        $acc50000 = ChartOfAccount::idByCode('50000');
        $acc52000 = ChartOfAccount::idByCode('52000');
        $acc52001 = ChartOfAccount::idByCode('52001');

        $row = 9;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $balance50000 = DocumentJournal::where('debit_account_id', $acc50000)
                    ->whereDate('date', $date)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc50000)
                    ->whereDate('date', $date)
                    ->sum('amount_amd');

            $balance52000 = DocumentJournal::where('debit_account_id', $acc52000)
                    ->whereDate('date', $date)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc52000)
                    ->whereDate('date', $date)
                    ->sum('amount_amd');

            $balance52001 = DocumentJournal::where('debit_account_id', $acc52001)
                    ->whereDate('date', $date)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc52001)
                    ->whereDate('date', $date)
                    ->sum('amount_amd');

            $finalSum = $balance50000 + $balance52000 + $balance52001;

            $sheet2->setCellValue('B' . $row, $finalSum);
            $row++;
        }
        $sheet6 = $spreadsheet->getSheetByName('Sheet6');
//        $sheet6->setCellValue('E10', 0);

        $d10 = $sheet6->getCell('D10')->getValue();
        $sheet6->setCellValue('E10', '=IF(D10<=4,3,IF(D10=5,3.4,IF(D10=6,3.5,IF(D10=7,3.65,IF(D10=8,3.75,IF(D10=9,3.85,4))))))');

        if ($d10 <= 4) {
            $e10 = 3;
        } elseif ($d10 == 5) {
            $e10 = 3.4;
        } elseif ($d10 == 6) {
            $e10 = 3.5;
        } elseif ($d10 == 7) {
            $e10 = 3.65;
        } elseif ($d10 == 8) {
            $e10 = 3.75;
        } elseif ($d10 == 9) {
            $e10 = 3.85;
        } else { // >=10
            $e10 = 4;
        }

//        $sheet6->setCellValue('E10', $e10);


        // ---------------------------
        // SAVE XLS
        // ---------------------------
        $fileName = 'v03_export_' . now()->format('Ymd_His') . '.xls';
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }
}
