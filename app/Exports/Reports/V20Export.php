<?php

namespace App\Exports\Reports;

use App\Models\Client;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class V20Export
{
    public function export($from, $to): string
    {
        $path = base_path('v20.XLS');
        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($path);


        $sheet1 = $spreadsheet->getSheetByName('Sheet1');
        $sheet1->setCellValueExplicit('C5','«Ակրեդիտ» ՎՄ ՍՊԸ',DataType::TYPE_STRING);
        $sheet1->setCellValue('C6',Date::PHPToExcel($to));

        $nonResidentCount = Client::where('residency_status', 'non_resident')->count();
        $sheet1->setCellValue('C15', $nonResidentCount);

        $residentCount = Client::where('residency_status', 'resident')->count();
        $sheet1->setCellValue('C16', $residentCount);

        $fileName = 'v20_exprt_' . $from . '_' . $to . '.xls';
        $path = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($path);

        return $path;
    }
}
