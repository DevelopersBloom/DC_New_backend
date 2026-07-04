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
        $balance10000 = $acc10000 ? $this->getAccountBalance($acc10000, $dateStr) : 0;
        $acc10001 = ChartOfAccount::idByCode('10001');
        $balance10001 = $acc10001 ? $this->getAccountBalance($acc10001, $dateStr) : 0;
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

        $acc39210 = ChartOfAccount::idByCode('39210');
        $balance39210 = $acc39210 ? $this->getAccountBalance($acc39210, $dateStr, 'passive') : 0;
        $sheet->setCellValue('F52', $balance39210 / 1000);

        $acc39102Group = ChartOfAccount::whereIn('code', ['3910201', '3910202', '3910203'])->pluck('id')->toArray();

        $balance39102 = 0;
        foreach ($acc39102Group as $accId) {
            $balance39102 += $this->getAccountBalance($accId, $dateStr, 'passive');
        }

        $sheet->setCellValue('F53', $balance39102 / 1000);


        $acc39200Account = ChartOfAccount::where('code', '39200')->first();

        $balance39200 = $acc39200Account
            ? $this->getAccountBalance($acc39200Account->id, $dateStr, $acc39200Account->type)
            : 0;

        $sheet->setCellValue('F59', $balance39200 / 1000);

        $docs = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereDate('date', '<=', $dateStr)
            ->get();

        // Multi-disbursement contracts have several provide-docs, all journalable to the same
        // Contract. Group by contract so getSpecificBalance() (which is already contract-scoped)
        // runs once per contract instead of once per disbursement, avoiding double counting.
        $docsByContract = $docs->groupBy('journalable_id');

        // Non-performing (classification 3/4) contracts split row 31/37 into overdue (C) vs.
        // not-yet-due (D) instead of the E–N remaining-days buckets. Class 5 is excluded: its
        // balance is already written off to 86000/86001 and needs no handling here.
        $classificationsAsOf = (new V06Export())->sheet1ClientClassificationsAsOf($dateStr);
        $nonPerformingNames = ['substandard', 'suspicious'];

        $acc39210 = ChartOfAccount::idByCode('39210');
        $acc39102Group = ChartOfAccount::whereIn('code', ['3910201', '3910202', '3910203'])->pluck('id')->toArray();
        $acc39200Account = ChartOfAccount::where('code', '39200')->first();
        foreach ($docsByContract as $contractDocs) {
            $doc = $contractDocs->first();
            $contract = $doc->journalable;
            if (!$contract) continue;
            // Compare against the report date, not today's status — a contract closed after
            // the report date was still open on the report snapshot.
            if ($contract->closed_at && Carbon::parse($contract->closed_at)->lt($dateStr)) continue;
            $date = Carbon::parse($contract->date);
            $remainingDays = $toDate->diffInDays(Carbon::parse($contract->deadline), false);
            $col = $this->getColumnByDaysV09($remainingDays);
            $docIds = $contractDocs->pluck('id')->all();

            $classification = $classificationsAsOf[$contract->client_id] ?? null;
            $isNonPerforming = $classification && in_array($classification->name, $nonPerformingNames, true);

            $overduePrincipal = 0.0;
            $overdueInterest = 0.0;
            if ($isNonPerforming) {
                [$overduePrincipal, $overdueInterest] = $this->overdueScheduleAmounts($contract, $dateStr);
            }

            // 16200NV
            if ($acc16200NV) {
                $balanceNV = $this->getSpecificBalance($contract->id, $docIds, $acc16200NV, $dateStr, 'active');
                if ($balanceNV > 0) {
                    if ($isNonPerforming) {
                        // Cap at the ledger balance so a data glitch in the schedule can't push C above it.
                        $overdue = min($overduePrincipal, $balanceNV);
                        $cValue = $overdue / 1000;
                        $dValue = ($balanceNV - $overdue) / 1000;

                        $prevC31 = (float)$sheet->getCell('C31')->getValue();
                        $sheet->setCellValue('C31', $prevC31 + $cValue);
                        $prevD31 = (float)$sheet->getCell('D31')->getValue();
                        $sheet->setCellValue('D31', $prevD31 + $dValue);
                        // Row 43 is the fixed-rate memo of row 31.
                        $prevC43 = (float)$sheet->getCell('C43')->getValue();
                        $sheet->setCellValue('C43', $prevC43 + $cValue);
                        $prevD43 = (float)$sheet->getCell('D43')->getValue();
                        $sheet->setCellValue('D43', $prevD43 + $dValue);
                    } else {
                        $value = $balanceNV / 1000;

                        $prevNV = (float)$sheet->getCell($col . '31')->getValue();
                        $sheet->setCellValue($col . '31', $prevNV + $value);
                        $prev43 = (float)$sheet->getCell($col . '43')->getValue();
                        $sheet->setCellValue($col . '43', $prev43 + $value);
                    }
                }
            }

            // 16201NI
            if ($acc16201NI) {
                $balanceNI = $this->getSpecificBalance($contract->id, $docIds, $acc16201NI, $dateStr, 'active');
                if ($balanceNI != 0) {
                    if ($isNonPerforming) {
                        $overdue = min($overdueInterest, $balanceNI);
                        $cValue = $overdue / 1000;
                        $dValue = ($balanceNI - $overdue) / 1000;

                        $prevC37 = (float)$sheet->getCell('C37')->getValue();
                        $sheet->setCellValue('C37', $prevC37 + $cValue);
                        $prevD37 = (float)$sheet->getCell('D37')->getValue();
                        $sheet->setCellValue('D37', $prevD37 + $dValue);
                    } else {
                        $prevNI = (float)$sheet->getCell($col . '37')->getValue();
                        $sheet->setCellValue($col . '37', $prevNI + ($balanceNI / 1000));
                    }
                }
            }

            // 39210
//            if ($acc39210) {
//                $balance39210 = $this->getSpecificBalance($contract->id, $doc->id, $acc39210, $dateStr, 'passive');
//                if ($balance39210 > 0) {
//                    $prev52 = (float)$sheet->getCell($col . '52')->getValue();
//                    $sheet->setCellValue($col . '52', $prev52 + ($balance39210 / 1000));
//                }
//            }
//
//            // 39102Group
//            if (!empty($acc39102Group)) {
//                $balance39102 = $this->getSpecificBalance($contract->id, $doc->id, $acc39102Group, $dateStr, 'passive');
//                if ($balance39102 > 0) {
//                    $prev54 = (float)$sheet->getCell($col . '54')->getValue();
//                    $sheet->setCellValue($col . '54', $prev54 + ($balance39102 / 1000));
//                }
//            }
//
//            // 39200
//            if ($acc39200Account) {
//                $balance39200 = $this->getSpecificBalance($contract->id, $doc->id, $acc39200Account->id, $dateStr, $acc39200Account->type);
//                if ($balance39200 > 0) {
//                    $prev59 = (float)$sheet->getCell($col . '59')->getValue();
//                    $sheet->setCellValue($col . '59', $prev59 + ($balance39200 / 1000));
//                }
//            }
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

    private function getSpecificBalance($contractId, $docIds, $accountId, $date, $type = 'active')
    {
        $docIds = array_filter((array)$docIds);

        $query = DocumentJournal::where(function ($q) use ($contractId, $docIds) {
            $q->where(function ($inner) use ($contractId) {
                $inner->where('journalable_type', Contract::class)
                    ->where('journalable_id', $contractId);
            });
            if ($docIds !== []) {
                $q->orWhere(function ($inner) use ($docIds) {
                    $inner->where('journalable_type', DocumentJournal::class)
                        ->whereIn('journalable_id', $docIds);
                });
            }
        })->whereDate('date', '<=', $date);

        $debit = (clone $query)->where('debit_account_id', $accountId)->sum('amount_amd');
        $credit = (clone $query)->where('credit_account_id', $accountId)->sum('amount_amd');
        return ($type === 'active') ? ($debit - $credit) : ($credit - $debit);
    }

    private function getAccountBalance($accountId, $date, $type = 'active')
    {
        $debit = DocumentJournal::where('debit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
        $credit = DocumentJournal::where('credit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
        return ($type == 'active') ? $debit - $credit : $credit - $debit;
    }

    /**
     * Overdue portion of the payment schedule as of the report date: installments still
     * outstanding on that date (status='initial', or 'completed' with to_date after it —
     * same "unpaid" definition as V06 Sheet1) whose due date already passed.
     *
     * @return array{0: float, 1: float} [overduePrincipal, overdueInterest]
     */
    private function overdueScheduleAmounts(Contract $contract, string $reportDate): array
    {
        $overdue = $contract->payments()
            ->where(function ($q) use ($reportDate) {
                $q->where('status', 'initial')
                    ->orWhere(function ($q2) use ($reportDate) {
                        $q2->where('status', 'completed')
                            ->whereDate('to_date', '>', $reportDate);
                    });
            })
            ->whereDate('date', '<', $reportDate)
            ->selectRaw('COALESCE(SUM(principal_payment), 0) as principal, COALESCE(SUM(interest_payment), 0) as interest')
            ->first();

        return [
            (float)($overdue->principal ?? 0),
            (float)($overdue->interest ?? 0),
        ];
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
