<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
        $toDay = now();
        $sheet->setCellValueExplicit('B10', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet->setCellValue('C11', Date::PHPToExcel($fromDate));
        $sheet->setCellValue('E11', Date::PHPToExcel($toDate));
        $sheet->getStyle('C11:E11')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');

        $acc10000 = ChartOfAccount::idByCode('10000');
        $balance10000 = $acc10000 ? $this->getAccountBalance($acc10000,$dateStr) : 0;
        $acc10001 = ChartOfAccount::idByCode('10001');
        $balance10001 = $acc10001 ? $this->getAccountBalance($acc10001,$dateStr) : 0;
        $cashBalance = $balance10000 + $balance10001;
        $sheet->setCellValue('E17', $cashBalance / 1000);

        $bankAccount102101 = ChartOfAccount::idByCode('102101');
        $bankBalance102101 = $bankAccount102101 ? $this->getAccountBalance($bankAccount102101, $dateStr) : 0;
        $bankAccount102102 = ChartOfAccount::idByCode('102102');
        $bankBalance102102 = $bankAccount102102 ? $this->getAccountBalance($bankAccount102102, $dateStr) : 0;
        $bankBalance = $bankBalance102101 + $bankBalance102102;
        $sheet->setCellValue('E19', $bankBalance / 1000);

        $sheet->setCellValue('E44', ($cashBalance + $bankBalance) / 1000);

        $acc19000 = ChartOfAccount::idByCode('19000');
        $balance19000 = $acc19000 ? $this->getAccountBalance($acc19000, $dateStr) : 0;
        $sheet->setCellValue('F22', $balance19000 / 1000);
        $sheet->setCellValue('F44', $balance19000 / 1000);

        $docs = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereDate('date', '<=', $dateStr)
            ->get();

        $acc39210 = ChartOfAccount::idByCode('39210');
        $acc39102Group = ChartOfAccount::whereIn('code', ['3910201', '3910202', '3910203'])->pluck('id')->toArray();
        $acc39200Account = ChartOfAccount::where('code', '39200')->first();
        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract || $contract->status != 'initial') continue;
            $date = Carbon::parse($contract->date);
            $remainingDays = $toDay->diffInDays(Carbon::parse($contract->deadline), false);
            $col = $this->getColumnByDaysV09($remainingDays);
            // 16200NV
            if ($acc16200NV) {
                $balanceNV = $this->getSpecificBalance($contract->id, $doc->id, $acc16200NV, $dateStr, 'active');
                if ($balanceNV > 0) {
                    logger()->info('16200NV DEBUG', [
                        'doc_id' => $doc->id,
                        'contract_id' => $contract->id,
                        'remaining_days' => $remainingDays,
                        'column' => $col,
                        'balanceNV' => $balanceNV,
                    ]);

                    $value = $balanceNV;

                    $prevNV = (float)$sheet->getCell($col . '31')->getValue();
                    $sheet->setCellValue($col . '31', $prevNV + $value);
                    $prev43 = (float)$sheet->getCell($col . '43')->getValue();
                    $sheet->setCellValue($col . '43', $prev43 + $value);
                }
            }

            // 16201NI
            if ($acc16201NI) {
                $balanceNI = $this->getSpecificBalance($contract->id, $doc->id, $acc16201NI, $dateStr, 'active');
                if ($balanceNI != 0) {
                    $prevNI = (float)$sheet->getCell($col . '37')->getValue();
                    $sheet->setCellValue($col . '37', $prevNI + ($balanceNI / 1000));
                }
            }

            // 39210
            if ($acc39210) {
                $balance39210 = $this->getSpecificBalance($contract->id, $doc->id, $acc39210, $dateStr, 'passive');
                if ($balance39210 > 0) {
                    $prev52 = (float)$sheet->getCell($col . '52')->getValue();
                    $sheet->setCellValue($col . '52', $prev52 + ($balance39210 / 1000));
                }
            }

            // 39102Group
            if (!empty($acc39102Group)) {
                $balance39102 = $this->getSpecificBalance($contract->id, $doc->id, $acc39102Group, $dateStr, 'passive');
                if ($balance39102 > 0) {
                    $prev54 = (float)$sheet->getCell($col . '54')->getValue();
                    $sheet->setCellValue($col . '54', $prev54 + ($balance39102 / 1000));
                }
            }

            // 39200
            if ($acc39200Account) {
                $balance39200 = $this->getSpecificBalance($contract->id, $doc->id, $acc39200Account->id, $dateStr, $acc39200Account->type);
                if ($balance39200 > 0) {
                    $prev59 = (float)$sheet->getCell($col . '59')->getValue();
                    $sheet->setCellValue($col . '59', $prev59 + ($balance39200 / 1000));
                }
            }
        }

        $this->fixPColumnTotals($sheet);

        $fileName = 'v09_export_' . now()->format('Ymd_His') . '.xls';
        $outputPath = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    private function getColumnByDaysV09($days): string
    {
        if ($days <= 0) return 'E';
        if ($days <= 30) return 'F';
        if ($days <= 60) return 'G';
        if ($days <= 90) return 'H';
        if ($days <= 120) return 'I';
        if ($days <= 150) return 'J';
        if ($days <= 180) return 'K';
        if ($days <= 366) return 'L';
        if ($days <= 1096) return 'M';
        return 'N';
    }

    private function getSpecificBalance($contractId, $docId, $accountId, $date, $type = 'active')
    {
        $accIds = is_array($accountId) ? $accountId : [$accountId];
        $accIds = array_filter($accIds);

        if (empty($accIds)) return 0;

        $query = DocumentJournal::where(function ($q) use ($contractId, $docId) {
            $q->where(function ($inner) use ($contractId) {
                $inner->where('journalable_type', Contract::class)
                    ->where('journalable_id', $contractId);
            })->orWhere(function ($inner) use ($docId) {
                $inner->where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $docId);
            });
        })->whereDate('date', '<=', $date);

        $debit = (clone $query)->whereIn('debit_account_id', $accIds)->sum('amount_amd');
        $credit = (clone $query)->whereIn('credit_account_id', $accIds)->sum('amount_amd');
        return ($type === 'active') ? ($debit - $credit) : ($credit - $debit);
    }

    private function getAccountBalance($accountId, $date)
    {
        $debit = DocumentJournal::where('debit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
        $credit = DocumentJournal::where('credit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
        return $debit - $credit;
    }

    private function fixPColumnTotals(Worksheet $sheet): void
    {
        $highestRow = $sheet->getHighestRow();

        for ($row = 1; $row <= $highestRow; $row++) {
            $formula = $sheet->getCell('P' . $row)->getValue();

            if (!is_string($formula) || strncmp($formula, '=', 1) !== 0) {
                continue;
            }

            $correctFormula = preg_replace(
                '/^=SUM\\(C' . $row . ':C' . $row . '\\)$/i',
                '=SUM(C' . $row . ':O' . $row . ')',
                $formula
            );

            if ($correctFormula !== null && $correctFormula !== $formula) {
                $sheet->setCellValue('P' . $row, $correctFormula);
            }
        }
    }
}
