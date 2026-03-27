<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class V09Export
{
    public function export($from, $to)
    {
        $templatePath = base_path('v09.xls');
        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($templatePath);
        $sheet = $spreadsheet->getSheetByName('Sheet1');

        $fromDate = Carbon::parse($from);
        $toDate = Carbon::parse($to);
        $dateStr = $toDate->format('Y-m-d');

        $sheet->setCellValueExplicit('B10', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet->setCellValue('C11', Date::PHPToExcel($fromDate));
        $sheet->setCellValue('E11', Date::PHPToExcel($toDate));
        $sheet->getStyle('C11:E11')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');

        $cashAccounts = ChartOfAccount::whereIn('code', ['10000', '10001'])->pluck('id');
        $cashBalance = $this->getAccountBalance($cashAccounts, $dateStr);
        $sheet->setCellValue('E17',$cashBalance / 1000);

        $bankAccount = ChartOfAccount::idByCode('102101');
        $bankBalance = $this->getAccountBalance($bankAccount, $dateStr);
        $sheet->setCellValue('E19', $bankBalance / 1000);

        $sheet->setCellValue('E44', ($cashBalance + $bankBalance) / 1000);

        $acc19000 = ChartOfAccount::idByCode('19000');
        $balance19000 = $this->getAccountBalance($acc19000, $dateStr);
        $sheet->setCellValue('F44', $balance19000 / 1000);


        $docs = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereDate('date', '<=', $dateStr)
            ->get();
        $acc39210 = ChartOfAccount::idByCode('39210');
        $acc39102Group = ChartOfAccount::whereIn('code', ['3910201', '3910202', '3910203'])->pluck('id')->toArray();
        $acc39200 = ChartOfAccount::idByCode('39200');

        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract || $contract->status != 'initial') continue;

            $remainingDays = $toDate->diffInDays(Carbon::parse($contract->deadline), false);
            $col = $this->getColumnByDaysV09($remainingDays);

            $balanceNV = $this->getSpecificBalance($contract->id, $doc->id, $acc16200NV, $dateStr, 'active');
            $balanceNI = $this->getSpecificBalance($contract->id, $doc->id, $acc16201NI, $dateStr, 'active');

            if ($balanceNV > 0) {
                $prevNV = (float)$sheet->getCell($col . '31')->getValue();
                $sheet->setCellValue($col . '31', $prevNV + ($balanceNV / 1000));
            }
            if ($balanceNI != 0) {
                $prevNI = (float)$sheet->getCell($col . '37')->getValue();
                $sheet->setCellValue($col . '37', $prevNI + ($balanceNI / 1000));
            }

            $balance39210 = $this->getSpecificBalance($contract->id, $doc->id, $acc39210, $dateStr, 'passive');
            if ($balance39210 > 0) {
                $prev52 = (float)$sheet->getCell($col . '52')->getValue();
                $sheet->setCellValue($col . '52', $prev52 + ($balance39210 / 1000));
            }

            $balance39102 = $this->getSpecificBalance($contract->id, $doc->id, $acc39102Group, $dateStr, 'passive');
            if ($balance39102 > 0) {
                $prev54 = (float)$sheet->getCell($col . '54')->getValue();
                $sheet->setCellValue($col . '54', $prev54 + ($balance39102 / 1000));
            }

            $balance39200 = $this->getSpecificBalance($contract->id, $doc->id, $acc39200, $dateStr, $acc39200->type);
            if ($balance39200 > 0) {
                $prev59 = (float)$sheet->getCell($col . '59')->getValue();
                $sheet->setCellValue($col . '59', $prev59 + ($balance39200 / 1000));
            }
        }

        $fileName = 'v09_export_' . now()->format('Ymd_His') . '.xls';
        $outputPath = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    private function getColumnByDaysV09($days): string
    {
        if ($days <= 0) return 'E';
        if ($days <= 30) return 'F';  // Մինչև 30
        if ($days <= 60) return 'G';  // 31-60
        if ($days <= 90) return 'H';  // 61-90
        if ($days <= 120) return 'I'; // 91-120
        if ($days <= 150) return 'J'; // 121-150
        if ($days <= 180) return 'K'; // 151-180
        if ($days <= 366) return 'L'; // 1
        if ($days <= 1096) return 'M'; // 3
        return 'N'; // 3 տարուց ավել
    }
    private function getSpecificBalance($contractId, $docId, $accountId, $date, $type = 'active')
    {
        $accIds = is_array($accountId) ? $accountId : [$accountId];

        $query = DocumentJournal::where(function ($q) use ($contractId, $docId) {
            $q->where(function ($inner) use ($contractId) {
                $inner->where('journalable_type', Contract::class)
                    ->where('journalable_id', $contractId);
            })->orWhere(function ($inner) use ($docId) {
                $inner->where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $docId);
            });
        })->whereDate('date', '<=', $date);

        $results = (clone $query)->selectRaw("
            SUM(CASE WHEN debit_account_id IN (" . implode(',', $accIds) . ") THEN amount_amd ELSE 0 END) as debit,
            SUM(CASE WHEN credit_account_id IN (" . implode(',', $accIds) . ") THEN amount_amd ELSE 0 END) as credit
        ")->first();

        return ($type === 'active') ? ($results->debit - $results->credit) : ($results->credit - $results->debit);
    }
    private function getAccountBalance($accountIds, $date)
    {
        $ids = is_array($accountIds) ? $accountIds : [$accountIds];
        $debit = DocumentJournal::whereIn('debit_account_id', $ids)->whereDate('date', '<=', $date)->sum('amount_amd');
        $credit = DocumentJournal::whereIn('credit_account_id', $ids)->whereDate('date', '<=', $date)->sum('amount_amd');
        return $debit - $credit;
    }
}
