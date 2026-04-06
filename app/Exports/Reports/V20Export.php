<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use Carbon\Carbon;
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

        $sheet = $spreadsheet->getSheetByName('Sheet1');

        $fromDate = Carbon::parse($from);
        $toDate = Carbon::parse($to);

        $sheet->setCellValueExplicit('C5','«Ակրեդիտ» ՎՄ ՍՊԸ',DataType::TYPE_STRING);
        $sheet->setCellValue('C6', Date::PHPToExcel($toDate));

        $bankAccounts = ['102101', '102102', '102103'];

        $count = ChartOfAccount::whereIn('code', $bankAccounts)->count();
        $sheet->setCellValue('D11', $count);


        $legalCount = Contract::whereHas('client', function ($q) {
            $q->where('type', 'legal');
        })->distinct('client_id')->count('client_id');

        $physicalCount = Contract::whereHas('client', function ($q) {
            $q->where('type', 'individual');
        })->distinct('client_id')->count('client_id');

        $sheet->setCellValue('C15', $legalCount);
        $sheet->setCellValue('C16', $physicalCount);



        $acc10000s = ChartOfAccount::where('code', 'like', '10000%')->pluck('id')->toArray();

        $cashQuery = DocumentJournal::whereDate('date', '<=', $toDate);

        $cashDebitSum = (clone $cashQuery)
            ->whereIn('debit_account_id', $acc10000s)
            ->sum('amount_amd');

        $cashCreditSum = (clone $cashQuery)
            ->whereIn('credit_account_id', $acc10000s)
            ->sum('amount_amd');

        $cashDebitCount = (clone $cashQuery)
            ->whereIn('debit_account_id', $acc10000s)
            ->count();

        $cashCreditCount = (clone $cashQuery)
            ->whereIn('credit_account_id', $acc10000s)
            ->count();

        $sheet->setCellValue('C22', $cashCreditSum / 1000);
        $sheet->setCellValue('C23', $cashDebitSum / 1000);

        $sheet->setCellValue('D22', $cashCreditCount);
        $sheet->setCellValue('D23', $cashDebitCount);


        $acc102101s = ChartOfAccount::where('code', 'like', '102101%')->pluck('id')->toArray();

        $bankQuery = DocumentJournal::whereDate('date', '<=', $toDate);
        $bankDebitSum = (clone $bankQuery)
            ->whereIn('debit_account_id', $acc102101s)
            ->sum('amount_amd');

        $bankCreditSum = (clone $bankQuery)
            ->whereIn('credit_account_id', $acc102101s)
            ->sum('amount_amd');

        $bankDebitCount = (clone $bankQuery)
            ->whereIn('debit_account_id', $acc102101s)
            ->count();

        $bankCreditCount = (clone $bankQuery)
            ->whereIn('credit_account_id', $acc102101s)
            ->count();

        $sheet->setCellValue('E22', $bankCreditSum / 1000);
        $sheet->setCellValue('E23', $bankDebitSum / 1000);

        $sheet->setCellValue('F22', $bankCreditCount);
        $sheet->setCellValue('F23', $bankDebitCount);

        $fileName = 'v20_export_' . $from . '_' . $to . '.xls';
        $outputPath = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }
}
