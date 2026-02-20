<?php

namespace App\Exports\Reports;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class V13Export
{
    public function export($from, $to): string
    {
        $path = base_path('v13.XLS');
        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($path);

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $sheet1 = $spreadsheet->getSheetByName('Sheet1');
        $sheet1->setCellValueExplicit('C13','«Ակրեդիտ» ՎՄ ՍՊԸ',DataType::TYPE_STRING);
        $sheet1->setCellValue('C14',Date::PHPToExcel($from));
        $sheet1->setCellValue('C15',Date::PHPToExcel($to));

        $fileName = 'v13_exprt_' . $from . '_' . $to . '.xls';
        $path = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($path);

        return $path;
    }
}
