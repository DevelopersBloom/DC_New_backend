<?php

namespace App\Exports;

use App\Models\LoanNdm;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\IOFactory;

class V17Export
{
    public function export($from, $to)
    {
        $path = base_path('v17.XLS');
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Sheet1');

        $start = Carbon::parse($from)->startOfDay();
        $end   = Carbon::parse($to)->endOfDay();

        $ndms = LoanNdm::whereBetween('disbursement_date', [$start, $end])->get();

        $totalAmount = $ndms->sum('amount');

        $weighted = $ndms->sum(function($n) {
            return $n->amount * ($n->interest_rate / 100);
        });

        $middleRate = $totalAmount > 0
            ? round(($weighted / $totalAmount) * 100, 2)
            : 0;

        $sheet->setCellValue('C23', $totalAmount);
        $sheet->setCellValue('D23', $middleRate);

        $fileName = 'v17_export_' . now()->format('Ymd_His') . '.xls';
        $path = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($path);

        return $path;
    }
}
