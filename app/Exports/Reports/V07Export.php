<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class V07Export
{
    public function export($from, $to)
    {
        $path = base_path('v07.XLS');
        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName('Sheet1');

        $start = Carbon::parse($from)->startOfMonth();
        $end   = Carbon::parse($to)->endOfMonth();
        $account50000 = ChartOfAccount::idByCode('50000');
        $class6Ids = ChartOfAccount::where('code', 'like', '6%')->pluck('id')->toArray();
        $class7Ids = ChartOfAccount::where('code', 'like', '7%')->pluck('id')->toArray();
        $row = 21;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
//
//            $account52000 = ChartOfAccount::idByCode('52000');
//            $account50000 = ChartOfAccount::idByCode('50000');

//            $debit52000 = DocumentJournal::where('debit_account_id', $account52000)
//                ->where('date','<=', $date)
//                ->sum('amount_amd');
//
//            $credit52000 = DocumentJournal::where('credit_account_id', $account52000)
//                ->where('date','<=', $date)
//                ->sum('amount_amd');
//
//            $balance52000 =$credit52000 - $debit52000;
            $sum6 = DocumentJournal::whereIn('credit_account_id', $class6Ids)
                    ->where('date', '<=', $date)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('debit_account_id', $class6Ids)
                    ->where('date', '<=', $date)
                    ->sum('amount_amd');

            $sum7 = DocumentJournal::whereIn('debit_account_id', $class7Ids)
                    ->where('date', '<=', $date)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('credit_account_id', $class7Ids)
                    ->where('date', '<=', $date)
                    ->sum('amount_amd');

            $balance52000 = $sum6 - $sum7;
            // 50000 balance
            $balance50000 = 0;
            if ($account50000) {
                $balance50000 = DocumentJournal::where('credit_account_id', $account50000)
                        ->where('date', '<=', $date)
                        ->sum('amount_amd')
                    - DocumentJournal::where('debit_account_id', $account50000)
                        ->where('date', '<=', $date)
                        ->sum('amount_amd');
            }

            $final = ($balance52000 + $balance50000) * 0.05 / 1000;

            $sheet->setCellValue('D' . $row, $final);

            $row++;
        }
        $sheet->setCellValueExplicit('D12', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet->setCellValue('C14', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($from));
        $sheet->getStyle('C14')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $sheet->setCellValue('E14', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($to));
        $sheet->getStyle('E14')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $fileName = 'v07_export_' . now()->format('Ymd_His') . '.xls';
        $path = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($path);

        return $path;
    }
}
