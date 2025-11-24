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
        $path = base_path('v03.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        /**
         * ---------------------------
         * SHEET 1
         * ---------------------------
         */
        $sheet1 = $spreadsheet->getSheetByName('Sheet1');

        $sheet1->setCellValue('D10', 'Ակրեդիտ');
        $sheet1->setCellValue('D11', Carbon::parse($from)->format('d.m.Y'));
        $sheet1->setCellValue('F11', Carbon::parse($to)->format('d.m.Y'));

        /**
         * ---------------------------
         * SHEET 2
         * ---------------------------
         */
        $sheet = $spreadsheet->getSheetByName('Sheet2');

        $sheet->setCellValue('C3', Carbon::parse($from)->format('d.m.Y'));
        $sheet->setCellValue('E3', Carbon::parse($to)->format('d.m.Y'));

        $start = Carbon::parse($from)->startOfMonth();
        $end   = Carbon::parse($to)->endOfMonth();

        $acc50000 = ChartOfAccount::idByCode('50000');
        $acc52000 = ChartOfAccount::idByCode('52000');
        $acc52001 = ChartOfAccount::idByCode('52001');

        $row = 9;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {

            // 50000 balance
            $debit50000 = DocumentJournal::where('debit_account_id', $acc50000)
                ->whereDate('date', $date)
                ->sum('amount_amd');

            $credit50000 = DocumentJournal::where('credit_account_id', $acc50000)
                ->whereDate('date', $date)
                ->sum('amount_amd');

            $balance50000 = $credit50000 - $debit50000;

            // 52000 balance
            $debit52000 = DocumentJournal::where('debit_account_id', $acc52000)
                ->whereDate('date', $date)
                ->sum('amount_amd');

            $credit52000 = DocumentJournal::where('credit_account_id', $acc52000)
                ->whereDate('date', $date)
                ->sum('amount_amd');

            $balance52000 = $credit52000 - $debit52000;

            // 52001 balance
            $debit52001 = DocumentJournal::where('debit_account_id', $acc52001)
                ->whereDate('date', $date)
                ->sum('amount_amd');

            $credit52001 = DocumentJournal::where('credit_account_id', $acc52001)
                ->whereDate('date', $date)
                ->sum('amount_amd');

            $balance52001 = $credit52001 - $debit52001;

            $finalSum = $balance50000 + $balance52000 + $balance52001;

            $sheet->setCellValue('B' . $row, $finalSum);

            $row++;
        }


        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            foreach ($worksheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    if ($cell->isFormula()) {
                        $cell->setValue($cell->getCalculatedValue());
                    }
                }
            }
        }

//        $fileName = 'v03_export_' . now()->format('Ymd_His') . '.xls';
        $fileName = 'v03_export_' . now()->format('Ymd_His') . '.xlsx';
        $filePath = storage_path('app/public/' . $fileName);
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filePath);

        $writer = new Xls($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }
}
