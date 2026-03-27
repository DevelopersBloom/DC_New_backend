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
        $templatePath = base_path('v09.XLS');
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

        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract || $contract->status != 'initial') continue;

            $net16200NVCredit = DocumentJournal::where(function ($query) use ($contract, $doc) {
                $query->where(function ($q) use ($contract) {
                    $q->where('journalable_type', Contract::class)
                        ->where('journalable_id', $contract->id);
                })->orWhere(function ($q) use ($doc) {
                    $q->where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $doc->id);
                });
            })
                ->where('credit_account_id', $acc16200NV)
                ->whereDate('date', '<=', $dateStr)
                ->sum('amount_amd');

            $balanceNV = $doc->amount_amd - $net16200NVCredit;

            $balanceNI = DocumentJournal::where(function ($query) use ($contract, $doc) {
                $query->where(function ($q) use ($contract) {
                    $q->where('journalable_type', Contract::class)
                        ->where('journalable_id', $contract->id);
                })->orWhere(function ($q) use ($doc) {
                    $q->where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $doc->id);
                });
            })
                ->whereDate('date', '<=', $dateStr)
                ->selectRaw("SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) -
                         SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance",
                    [$acc16201NI, $acc16201NI])
                ->value('balance');

            if ($balanceNV <= 0 && $balanceNI <= 0) continue;

            $remainingDays = $toDate->diffInDays(Carbon::parse($contract->deadline), false);
            $col = $this->getColumnByDaysV09($remainingDays);

            $prevNV = (float)$sheet->getCell($col . '31')->getValue();
            $sheet->setCellValue($col . '31', $prevNV + ($balanceNV / 1000));

            $prevNI = (float)$sheet->getCell($col . '37')->getValue();
            $sheet->setCellValue($col . '37', $prevNI + ($balanceNI / 1000));
        }

        $fileName = 'v09_export_' . now()->format('Ymd_His') . '.xls';
        $outputPath = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    private function getColumnByDaysV09($days): string
    {
        if ($days <= 0) return 'E';   // Ցպահ / Ժամկետանց
        if ($days <= 30) return 'F';  // Մինչև 30 օր
        if ($days <= 60) return 'G';  // 31-60 օր
        if ($days <= 90) return 'H';  // 61-90 օր
        if ($days <= 120) return 'I'; // 91-120 օր
        if ($days <= 150) return 'J'; // 121-150 օր
        if ($days <= 180) return 'K'; // 151-180 օր
        if ($days <= 366) return 'L'; // 1 տարի
        if ($days <= 1096) return 'M'; // 3 տարի
        return 'N'; // 3 տարուց ավել
    }

    private function getAccountBalance($accountIds, $date)
    {
        $ids = is_array($accountIds) ? $accountIds : [$accountIds];
        $debit = DocumentJournal::whereIn('debit_account_id', $ids)->whereDate('date', '<=', $date)->sum('amount_amd');
        $credit = DocumentJournal::whereIn('credit_account_id', $ids)->whereDate('date', '<=', $date)->sum('amount_amd');
        return $debit - $credit;
    }
}
