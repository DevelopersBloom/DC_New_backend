<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class V06Export
{
    public function export($from, $to)
    {
        $path = base_path('v06.xls');
        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($path);

        $date = Carbon::parse($to)->format('Y-m-d');
        $dateFrom = Carbon::parse($from)->format('Y-m-d');

        $sheet = $spreadsheet->getSheetByName('Sheet1');

        $docs = DocumentJournal::with(['journalable.payments' => function ($q) {
            $q->where('status', 'initial');
        }])
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereDate('date', '<=', $date)
            ->get();
        $carContractCount = 0;
        $goldContractCount = 0;
        $electronicsContractCount = 0;

        $groupsOnTime = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
        $groupsExpired = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
        $groupsCar = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
        $groupsGold = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];

        $classificationCounts = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
        $amountsByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
        $weightedByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
        $reserveByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];

        $onTimeCount = 0;
        $expiredCount = 0;
        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');

        $balance16200NV = Transaction::where('debit_account_id', $acc16200NV)
                ->whereDate('date', '<=', $date)
                ->sum('amount_amd')
            -
            Transaction::where('credit_account_id', $acc16200NV)
                ->whereDate('date', '<=', $date)
                ->sum('amount_amd');
        $balance16201NI = Transaction::where('debit_account_id', $acc16201NI)
                ->whereDate('date', '<=', $date)
                ->sum('amount_amd')
            -
            Transaction::where('credit_account_id', $acc16201NI)
                ->whereDate('date', '<=', $date)
                ->sum('amount_amd');
        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract || !$contract->client || !$contract->client->classification || $contract->status != 'initial') continue;

            $hasExpiredPayment = $contract->payments
                ->contains(function ($p) use ($date) {
                    return Carbon::parse($p->date)->lt($date);
                });

            if ($hasExpiredPayment) {
                $expiredCount++;
            } else {
                $onTimeCount++;
            }
            $dateProvided = $doc->created_at->format('Y-m-d');
            $days = Carbon::parse($contract->deadline)
                ->diffInDays(Carbon::parse($dateProvided));

            $col = $this->getColumnByDays($days);
            $providedAmount = Contract::where('id',$doc->journalable->id)->select('provided_amount')->first()->provided_amount;

            if ($contract->category && $contract->category->name === 'car') {
                $groupsCar[$col] += $providedAmount;
                $carContractCount++;
//                    $doc->amount_amd;
            }

            if ($contract->category && $contract->category->name === 'gold') {
                $groupsGold[$col] += $providedAmount;//$doc->amount_amd;
                $goldContractCount++;
            }

            if ($contract->category && $contract->category->name === 'electronics') {
                $electronicsContractCount++;
            }

            if ($hasExpiredPayment) {
                $groupsExpired[$col] += $providedAmount;//$doc->amount_amd;
            } else {
                $groupsOnTime[$col] += $providedAmount;//$doc->amount_amd;
            }

            $name = $contract->client->classification->name;
            if (!isset($amountsByClassification[$name])) continue;

            $classificationCounts[$name]++;
            $providedAmount = Contract::where('id', $contract->id)->value('provided_amount') ?? 0;

            $net16200NVCredit = DocumentJournal::where(function ($query) use ($contract, $doc) {
                $query->where(function ($q) use ($contract) {
                    $q->where('journalable_type', Contract::class)
                        ->where('journalable_id', $contract->id);
                })
                    ->orWhere(function ($q) use ($doc) {
                        $q->where('journalable_type', DocumentJournal::class)
                            ->where('journalable_id', $doc->id);
                    });
            })
                ->where('credit_account_id', $acc16200NV)
                ->whereDate('date', '<=', $date)
                ->sum('amount_amd');
            $net16200NV = $doc->amount_amd - $net16200NVCredit;


            $net16201NI = DocumentJournal::where(function ($query) use ($contract, $doc) {
                $query->where(function ($q) use ($contract) {
                    $q->where('journalable_type', Contract::class)
                        ->where('journalable_id', $contract->id);
                })
                    ->orWhere(function ($q) use ($doc) {
                        $q->where('journalable_type', DocumentJournal::class)
                            ->where('journalable_id', $doc->id);
                    });
            })
                ->whereDate('date', '<=', $date)
                ->selectRaw("SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) -
                 SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance",
                    [$acc16201NI, $acc16201NI])
                ->value('balance');
//            $net16201NI = DocumentJournal::where('journalable_type', DocumentJournal::class)
//                    ->where('journalable_id', $doc->id)
//                    ->where('debit_account_id', $acc16201NI)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd')
//                -
//                DocumentJournal::where('journalable_type', DocumentJournal::class)
//                    ->where('journalable_id', $doc->id)
//                    ->where('credit_account_id', $acc16201NI)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd');

            $amountsByClassification[$name] += ($net16200NV + $net16201NI);
            $interest = DocumentJournal::where('journalable_id', $doc->id)
                ->whereIn('document_type', [DocumentJournal::INTEREST_RATE_AMOUNT, DocumentJournal::EFFECTIVE_RATE_AMOUNT])
                ->where('date', '<', $date)
                ->sum('amount_amd');

            $weightedByClassification[$name] += $interest;
            $reserve_percent = $contract->client->classification->reserve_percent ?? 0;
            $reserveByClassification[$name] += ($contract->mother ?? 0) * $reserve_percent / 100;
        }

        $rowsOnTime = [15, 16];
        foreach ($rowsOnTime as $row) {
            foreach ($groupsOnTime as $col => $value) {
                $sheet->setCellValue($col . $row, $value/1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }
        $sheet->setCellValue('P15',($balance16200NV + $balance16201NI)/1000);
        $sheet->getStyle('P15')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P16',($balance16200NV + $balance16201NI)/1000);
        $sheet->getStyle('P16')->getNumberFormat()->setFormatCode('#,##0');
        $rowsExpired = [21, 22];
        foreach ($rowsExpired as $row) {
            foreach ($groupsExpired as $col => $value) {
                $sheet->setCellValue($col . $row, $value/1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        $sheet->setCellValue('R15', $onTimeCount);
        $sheet->setCellValue('R16', $onTimeCount);
        $sheet->setCellValue('R21', $expiredCount);
        $sheet->setCellValue('R22', $expiredCount);

        $rowsCar = [110];
        $rowsGold = [112];
        $rowsTotal = [108];

        foreach ($groupsCar as $col => $value) {
            foreach ($rowsCar as $row) {
                $sheet->setCellValue($col . $row, $value / 1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        foreach ($groupsGold as $col => $value) {
            foreach ($rowsGold as $row) {
                $sheet->setCellValue($col . $row, $value / 1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        foreach ($groupsCar as $col => $value) {
            $goldValue = $groupsGold[$col] ?? 0;
            foreach ($rowsTotal as $row) {
                $sheet->setCellValue($col . $row, ($value + $goldValue)/1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        $sheet->setCellValue('P108',($carContractCount + $goldContractCount + $electronicsContractCount));
        $sheet->getStyle('P108')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P109',0);
        $sheet->getStyle('P109')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P110',$carContractCount);
        $sheet->getStyle('P110')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P111', $electronicsContractCount);
        $sheet->getStyle('P111')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P112', $goldContractCount);
        $sheet->getStyle('P112')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P113', 0);
        $sheet->getStyle('P113')->getNumberFormat()->setFormatCode('#,##0');

        $rows = [125, 126, 127, 128, 129];
        $classificationKeys = ['standard', 'monitored', 'substandard', 'suspicious', 'loss'];
        foreach ($rows as $index => $row) {
            $key = $classificationKeys[$index];
            $sheet->setCellValue('B' . $row, ($classificationCounts[$key] ?? 0));
            $sheet->setCellValue('D' . $row, ($amountsByClassification[$key] ?? 0)/1000);
//            $sheet->setCellValue('D' . $row, (($amountsByClassification[$key] ?? 0) + ($weightedByClassification[$key] ?? 0)) / 1000);
            $sheet->setCellValue('F' . $row, ($reserveByClassification[$key] ?? 0) / 1000);
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
        }

//        $acc10210 = ChartOfAccount::idByCode('10210');
//        $accCount = 0;
//        $balance10210 = 0;
//        if ($acc10210) {
//            $accCount++;
//            $balance10210 = DocumentJournal::where('debit_account_id', $acc10210)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd')
//                - DocumentJournal::where('credit_account_id', $acc10210)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd');
//        }
//        $sheet->setCellValue('J125', $accCount);
//        $sheet->getStyle('J125')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('L125', $balance10210);
//        $sheet->getStyle('L125')->getNumberFormat()->setFormatCode('#,##0');
        $acc10210Ids = ChartOfAccount::where('code', 'like', '10210%')->pluck('id');

        $accCount = $acc10210Ids->count();
        $balance10210 = 0;

        if ($accCount > 0) {
            $balance10210 = DocumentJournal::whereIn('debit_account_id', $acc10210Ids)
                    ->whereDate('date', '<=', $date)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('credit_account_id', $acc10210Ids)
                    ->whereDate('date', '<=', $date)
                    ->sum('amount_amd');
        }

        $sheet->setCellValue('J125', $accCount);
        $sheet->getStyle('J125')->getNumberFormat()->setFormatCode('#,##0');

        $sheet->setCellValue('L125', $balance10210 / 1000);
        $sheet->getStyle('L125')->getNumberFormat()->setFormatCode('#,##0');
        $acc15300Ids = ChartOfAccount::where('code', 'like', '15300%')->pluck('id');

        $balance15300 = 0;
        if ($acc15300Ids) {
            $balance15300 =  DocumentJournal::whereIn('credit_account_id', $acc15300Ids)
                    ->whereDate('date', '<=', $date)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('debit_account_id', $acc15300Ids)
                    ->whereDate('date', '<=', $date)
                    ->sum('amount_amd');
//            $balance15300 = DocumentJournal::where('credit_account_id', $acc15300)
//                ->whereDate('date', '<=', $date)
//                ->sum('amount_amd');
        }
        $sheet->setCellValue('N125', $balance15300 / 1000);
        $sheet->getStyle('N125')->getNumberFormat()->setFormatCode('#,##0');

        $acc19331 = ChartOfAccount::idByCode('19331');
        $balance19331 = 0;
        $debitPartnersCount = 0;
        if ($acc19331) {
            $balance19331 = DocumentJournal::where('debit_account_id', $acc19331)
                ->whereDate('date', '<=', $date)
                ->sum('amount_amd');
            $debitPartnersCount = DocumentJournal::where('debit_account_id', $acc19331)
                ->whereDate('date', '<=', $date)
                ->distinct('partner_id')
                ->count('partner_id');
        }
        $sheet->setCellValue('R125', $debitPartnersCount);
        $sheet->getStyle('R125')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('T125', $balance19331 / 1000);
        $sheet->getStyle('T125')->getNumberFormat()->setFormatCode('#,##0');

        $acc19400PC = ChartOfAccount::idByCode('19400PC');
        $balance19400PC = 0;
        if ($acc19400PC) {
            $balance19400PC = DocumentJournal::where('debit_account_id', $acc19400PC)
                ->whereDate('date', '<=', $date)
                ->sum('amount_amd');
        }
        $sheet->setCellValue('X125', $balance19400PC / 1000);
        $sheet->getStyle('X125')->getNumberFormat()->setFormatCode('#,##0');

        $sheet->setCellValueExplicit('D5', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet->setCellValue('D7', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($from));
        $sheet->getStyle('D7')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $sheet->setCellValue('F7', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($to));
        $sheet->getStyle('F7')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
        $rowCar = 89;
        $rowGold = 91;

        $goldAmountBefore = $this->sumByCategoryBefore('gold', $dateFrom);
        $carAmountBefore = $this->sumByCategoryBefore('car', $dateFrom);

        $goldAmountBetween = $this->sumByCategoryBetween('gold', $dateFrom, $date);
        $carAmountBetween = $this->sumByCategoryBetween('car', $dateFrom, $date);

        $sheet2->setCellValue("B{$rowCar}", $carAmountBefore /1000);
        $sheet2->setCellValue("B{$rowGold}", $goldAmountBefore/1000);
        $sheet2->setCellValue("B87", ($carAmountBefore + $goldAmountBefore) / 1000);

        $sheet2->setCellValue("D{$rowCar}", $carAmountBetween / 1000);
        $sheet2->setCellValue("D{$rowGold}", $goldAmountBetween / 1000);
        $sheet2->setCellValue("D87", ($carAmountBetween + $goldAmountBetween) / 1000);

        $acc89860001 = ChartOfAccount::idByCode('89860001');
        $acc91860001 = ChartOfAccount::idByCode('91860001');

        $balance89860001 = $this->sumAccountBefore($acc89860001, 'debit_account_id', $dateFrom);
        $balance91860001 = $this->sumAccountBefore($acc91860001, 'debit_account_id', $dateFrom);

        $sheet2->setCellValue("H89", $balance89860001 / 1000);
        $sheet2->setCellValue("H91", $balance91860001 / 1000);
        $sheet2->setCellValue("H87", ($balance89860001 + $balance91860001) / 1000);

        $balance89860001_J = $this->sumAccountBetween($acc89860001, 'debit_account_id', $dateFrom, $date);
        $balance91860001_J = $this->sumAccountBetween($acc91860001, 'debit_account_id', $dateFrom, $date);

        $credit89860001_J = $this->sumAccountBetween($acc89860001, 'credit_account_id', $dateFrom, $date);
        $credit91860001_J = $this->sumAccountBetween($acc91860001, 'credit_account_id', $dateFrom, $date);

        $sheet2->setCellValue("J89", $balance89860001_J / 1000);
        $sheet2->setCellValue("J91", $balance91860001_J / 1000);
        $sheet2->setCellValue("J87", ($balance89860001_J + $balance91860001_J) / 1000);

        $sheet2->setCellValue("L89", $credit89860001_J / 1000);
        $sheet2->setCellValue("L91", $credit91860001_J / 1000);
        $sheet2->setCellValue("L87", ($credit89860001_J + $credit91860001_J) / 1000);

        $fileName = 'v06_export_' . now()->format('Ymd_His') . '.xls';
        $savePath = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($savePath);

        return $savePath;
    }


    /**
     * Sum provided amounts for contracts with given category before $dateFrom
     */
    private function sumByCategoryBefore(string $categoryName, string $dateFrom): float
    {
        return DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereHasMorph('journalable', [Contract::class], function ($q) use ($categoryName) {
                $q->whereHas('client.classification', function ($q2) {
                    $q2->whereNotIn('name', ['standard', 'monitored']);
                });
                $q->whereHas('category', function ($q3) use ($categoryName) {
                    $q3->where('name', $categoryName);
                });
            })
            ->whereDate('date', '<=', $dateFrom)
            ->sum('amount_amd');
    }

    /**
     * Sum provided amounts for contracts with given category between $dateFrom and $dateTo
     */
    private function sumByCategoryBetween(string $categoryName, string $dateFrom, string $dateTo): float
    {
        return DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereHasMorph('journalable', [Contract::class], function ($q) use ($categoryName) {
                $q->whereHas('client.classification', function ($q2) {
                    $q2->whereNotIn('name', ['standard', 'monitored']);
                });
                $q->whereHas('category', function ($q3) use ($categoryName) {
                    $q3->where('name', $categoryName);
                });
            })
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->sum('amount_amd');
    }

    /**
     * Sum amounts for given account column (debit_account_id or credit_account_id) before date
     */
    private function sumAccountBefore($accountId, string $column, string $dateFrom): float
    {
        if (!$accountId) return 0;
        return DocumentJournal::where($column, $accountId)
            ->whereDate('date', '<=', $dateFrom)
            ->sum('amount_amd');
    }

    /**
     * Sum amounts for given account column (debit_account_id or credit_account_id) between dates
     */
    private function sumAccountBetween($accountId, string $column, string $dateFrom, string $dateTo): float
    {
        if (!$accountId) return 0;
        return DocumentJournal::where($column, $accountId)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->sum('amount_amd');
    }

    private function getColumnByDays($days): string
    {
        if ($days <= 90) return 'B';
        if ($days <= 180) return 'D';
        if ($days <= 270) return 'F';
        if ($days <= 366) return 'H';
        if ($days <= 1826) return 'J';
        return 'L';
    }
}
