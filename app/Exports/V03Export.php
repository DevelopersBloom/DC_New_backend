<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
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
//        // ---------------------------
//        // SHEET 2
//        // ---------------------------
//        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
//        $sheet2->setCellValue('C3', $startDay);
//        $sheet2->setCellValue('E3', $endDay);

        $acc50000 = ChartOfAccount::idByCode('50000');
        $acc52000 = ChartOfAccount::idByCode('52000');
        $acc52001 = ChartOfAccount::idByCode('52001');

//        $row = 9;
//        $current = Carbon::parse($from);
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
            $sheet2->setCellValue('B' . $row, $finalSum/1000);

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
//        $fileName = 'v03_export_' . now()->format('Ymd_His') . '.xls';
//        $filePath = storage_path('app/public/' . $fileName);
//
//        $writer = new Xls($spreadsheet);
//        $writer->save($filePath);
        $fileName = 'v03_export_' . now()->format('Ymd_His') . '.xlsx';
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        return $filePath;
    }
}
