<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class V13Export
{
    public function export($from, $to): string
    {
        $path = base_path('v13.xls');
        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($path);

        $fromDate = Carbon::parse($from);
        $toDate = Carbon::parse($to);

        $sheet1 = $spreadsheet->getSheetByName('Sheet1');

        $sheet1->setCellValueExplicit('C13', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet1->setCellValue('C14', Date::PHPToExcel($fromDate));
        $sheet1->setCellValue('C15', Date::PHPToExcel($toDate));
        $sheet1->getStyle('C14:C15')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $acc10000 = ChartOfAccount::idByCode('10000');
        $acc102101 = ChartOfAccount::idByCode('102101');

        $openingBalance = $this->getAccountBalance($acc10000, $fromDate);
        $sheet1->setCellValue('C21', $openingBalance / 1000);

        $debit10000NotFromBank = DocumentJournal::where('debit_account_id', $acc10000)
            ->where('credit_account_id', '!=', $acc102101)
            ->whereBetween('date', '<=', $toDate)
            ->sum('amount_amd');
        $sheet1->setCellValue('C22', $debit10000NotFromBank / 1000);

        $sheet1->setCellValue('C23', 0);

        $debit10000FromBank = DocumentJournal::where('debit_account_id', $acc10000)
            ->where('credit_account_id', $acc102101)
            ->whereBetween('date', '<=', $toDate)
            ->sum('amount_amd');
        $sheet1->setCellValue('C24', $debit10000FromBank / 1000);

        $credit10000NotToBank = DocumentJournal::where('credit_account_id', $acc10000)
            ->where('debit_account_id', '!=', $acc102101)
            ->whereBetween('date','<=', $toDate)
            ->sum('amount_amd');
        $sheet1->setCellValue('C25', $credit10000NotToBank / 1000);

        $sheet1->setCellValue('C26', 0);

        $credit10000ToBank = DocumentJournal::where('credit_account_id', $acc10000)
            ->where('debit_account_id', $acc102101)
            ->whereBetween('date', '<=', $toDate)
            ->sum('amount_amd');
        $sheet1->setCellValue('C27', $credit10000ToBank / 1000);

        $closingBalance = $this->getAccountBalance($acc10000, $toDate);
        $sheet1->setCellValue('C28', $closingBalance / 1000);

        $fileName = 'v13_export_' . now()->format('Ymd_His') . '.xls';
        $savePath = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($savePath);

        return $savePath;
    }

    private function getAccountBalance($accountId, $date)
    {
        if (!$accountId) return 0;

        $debit = DocumentJournal::where('debit_account_id', $accountId)
            ->where('date', '<=', $date)
            ->sum('amount_amd');

        $credit = DocumentJournal::where('credit_account_id', $accountId)
            ->where('date', '<=', $date)
            ->sum('amount_amd');

        return $debit - $credit;
    }
}
