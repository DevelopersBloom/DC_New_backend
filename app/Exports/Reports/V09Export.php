<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\LoanNdm;
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
        $sheet->getStyle('B10')->getFont()->setName('Sylfaen');
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

        // Section 2 (liabilities). Amounts in thousand AMD.
        // 2.4 Liabilities to participants (33512NV): per-agreement balance, bucketed by maturity.
        foreach ($this->participantLoanBuckets($dateStr, $toDate) as $col => $amount) {
            $sheet->setCellValue($col . '50', $amount / 1000);
        }
        // 2.6 Legal entities: external creditor debts.
        $sheet->setCellValue('F52', $this->passiveBalance(['39210'], $dateStr) / 1000);
        // 2.7 Government: taxes payable incl. income tax withheld on rent (3910204).
        $sheet->setCellValue('F53', $this->passiveBalance(['3910201', '3910202', '3910203', '3910204'], $dateStr) / 1000);
        // 2.13 Other: salaries payable + prepayments received from clients.
        $sheet->setCellValue('F59', $this->passiveBalance(['39200', '39220'], $dateStr) / 1000);

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
        }

        $this->fixPColumnTotals($sheet);

        $fileName = 'v09_export_' . now()->format('Ymd_His') . '.xls';
        $outputPath = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($outputPath);

        return $outputPath;
    }

    /**
     * Sum of the passive (credit - debit) balances of the given account codes at $date.
     */
    private function passiveBalance(array $codes, string $date): float
    {
        $total = 0.0;
        foreach (ChartOfAccount::whereIn('code', $codes)->pluck('id') as $accountId) {
            $total += $this->getAccountBalance($accountId, $date, 'passive');
        }
        return $total;
    }

    /**
     * Row 2.4: balance of 33512NV per loan agreement (loan_ndm) as of $date, bucketed by the days
     * left to maturity_date (no maturity_date => column E, on demand). Agreements with a zero
     * balance are skipped.
     *
     * An entry is matched to an agreement when it is journalable to the LoanNdm itself or to a
     * DocumentJournal that is journalable to the LoanNdm. Any other entry, or a mismatch with the
     * ledger balance, throws instead of guessing.
     *
     * @return array<string, float> [column => AMD]
     */
    public function participantLoanBuckets(string $date, Carbon $toDate): array
    {
        $accountId = ChartOfAccount::idByCode('33512NV');
        if (!$accountId) {
            return [];
        }

        $entries = DocumentJournal::where(function ($q) use ($accountId) {
            $q->where('debit_account_id', $accountId)->orWhere('credit_account_id', $accountId);
        })->whereDate('date', '<=', $date)->get();

        $balances = [];
        $unmatched = [];
        foreach ($entries as $entry) {
            $loanId = null;
            if ($entry->journalable_type === LoanNdm::class) {
                $loanId = $entry->journalable_id;
            } elseif ($entry->journalable_type === DocumentJournal::class) {
                $parent = DocumentJournal::find($entry->journalable_id);
                if ($parent && $parent->journalable_type === LoanNdm::class) {
                    $loanId = $parent->journalable_id;
                }
            }
            if ($loanId === null) {
                $unmatched[] = "#{$entry->id} ({$entry->date}, {$entry->amount_amd}, {$entry->journalable_type}#{$entry->journalable_id})";
                continue;
            }
            $sign = $entry->credit_account_id == $accountId ? 1 : -1;
            $balances[$loanId] = ($balances[$loanId] ?? 0.0) + $sign * (float)$entry->amount_amd;
        }

        if ($unmatched !== []) {
            throw new \RuntimeException('Form 9 row 2.4: 33512NV entries not linked to a loan agreement: ' . implode(', ', $unmatched));
        }

        $ledger = $this->getAccountBalance($accountId, $date, 'passive');
        if (abs(array_sum($balances) - $ledger) > 0.005) {
            throw new \RuntimeException('Form 9 row 2.4: agreements sum ' . array_sum($balances) . ' differs from 33512NV balance ' . $ledger);
        }

        $loans = LoanNdm::whereIn('id', array_keys($balances))->get()->keyBy('id');
        $buckets = [];
        foreach ($balances as $loanId => $balance) {
            if (abs($balance) < 0.005) {
                continue;
            }
            $loan = $loans[$loanId] ?? null;
            if (!$loan) {
                throw new \RuntimeException("Form 9 row 2.4: loan agreement #{$loanId} is deleted but has a 33512NV balance of {$balance}");
            }
            $col = $loan->maturity_date
                ? $this->getColumnByDaysV09($toDate->copy()->startOfDay()->diffInDays($loan->maturity_date->copy()->startOfDay(), false))
                : 'E';
            $buckets[$col] = ($buckets[$col] ?? 0.0) + $balance;
        }

        return $buckets;
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
