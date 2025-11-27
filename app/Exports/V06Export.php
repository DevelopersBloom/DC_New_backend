<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use App\Models\Contract;
use Carbon\Carbon;
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
            ->whereDate('date','<', $date)
            ->get();

        $groupsOnTime = [
            'B' => 0,
            'D' => 0,
            'F' => 0,
            'H' => 0,
            'J' => 0,
            'L' => 0,
        ];

        $groupsExpired = [
            'B' => 0,
            'D' => 0,
            'F' => 0,
            'H' => 0,
            'J' => 0,
            'L' => 0,
        ];
        $groupsCar= [
            'B' => 0,
            'D' => 0,
            'F' => 0,
            'H' => 0,
            'J' => 0,
            'L' => 0,
        ];
        $groupsGold = [
            'B' => 0,
            'D' => 0,
            'F' => 0,
            'H' => 0,
            'J' => 0,
            'L' => 0,
        ];
        $classificationCounts = [
            'standard'      => 0,
            'monitored'     => 0,
            'substandard'   => 0,
            'suspicious'    => 0,
            'loss'          => 0,
        ];
        $amountsByClassification = [
            'standard'      => 0,
            'monitored'     => 0,
            'substandard'   => 0,
            'suspicious'    => 0,
            'loss'          => 0,
        ];
        $weightedByClassification = [
            'standard'      => 0,
            'monitored'     => 0,
            'substandard'   => 0,
            'suspicious'    => 0,
            'loss'          => 0,
        ];
        $reserveByClassification = [
            'standard'      => 0,
            'monitored'     => 0,
            'substandard'   => 0,
            'suspicious'    => 0,
            'loss'          => 0,
        ];

        $onTimeCount = 0;
        $expiredCount = 0;

        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract || !$contract->client || !$contract->client->classification) continue;

            $hasExpiredPayment = $contract->payments
                ->contains(function ($p) use ($date) {
                    return Carbon::parse($p->date)->lt($date);
                });

            if ($hasExpiredPayment) {
                $expiredCount++;
            } else {
                $onTimeCount++;
            }
            $days = Carbon::parse($contract->deadline)
                ->diffInDays(Carbon::parse($contract->date));

            $col = $this->getColumnByDays($days);
            if ($contract->category && $contract->category->name === 'car') {
                $groupsCar[$col] += $doc->amount_amd;
            }

            if ($contract->category && $contract->category->name === 'gold') {
                $groupsGold[$col] += $doc->amount_amd;
            }


            if ($hasExpiredPayment) {
                $groupsExpired[$col] += $doc->amount_amd;
            } else {
                $groupsOnTime[$col] += $doc->amount_amd;
            }

            $name = $contract->client->classification->name;
            if (!isset($amountsByClassification[$name])) continue;

            $classificationCounts[$name]++;
            $amountsByClassification[$name] += $doc->amount_amd;

            $interest = DocumentJournal::where('journalable_id', $doc->id)
                ->whereIn('document_type', [DocumentJournal::INTEREST_RATE_AMOUNT, DocumentJournal::EFFECTIVE_RATE_AMOUNT])
                ->where('date','<',$date)
                ->sum('amount_amd');

            $weightedByClassification[$name] += $interest;
            $reserve_percent = $contract->client->classification->reserve_percent ?? 0;
            $reserveByClassification[$name] += $contract->mother * $reserve_percent / 100;
        }

        $rowsOnTime = [15, 16];
        foreach ($rowsOnTime as $row) {
            foreach ($groupsOnTime as $col => $value) {
                $sheet->setCellValue($col . $row, $value);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        $rowsExpired = [21, 22];
        foreach ($rowsExpired as $row) {
            foreach ($groupsExpired as $col => $value) {
                $sheet->setCellValue($col . $row, $value);
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
                $sheet->setCellValue($col . $row, $value);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        foreach ($groupsGold as $col => $value) {
            foreach ($rowsGold as $row) {
                $sheet->setCellValue($col . $row, $value);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        foreach ($groupsCar as $col => $value) {
            $goldValue = $groupsGold[$col] ?? 0;
            foreach ($rowsTotal as $row) {
                $sheet->setCellValue($col . $row, $value + $goldValue);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        $rows = [125, 126, 127, 128, 129];
        $classificationKeys = ['standard', 'monitored', 'substandard', 'suspicious', 'loss'];

        foreach ($rows as $index => $row) {
            $key = $classificationKeys[$index];
            $sheet->setCellValue('D' . $row, $amountsByClassification[$key]+$weightedByClassification[$key]);
            $sheet->setCellValue('F' . $row, $reserveByClassification[$key]);
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');

        }
        $acc10210 = ChartOfAccount::idByCode('10210');
        $accCount = 0;
        $balance10210 = 0;
        if ($acc10210){
            $accCount++;
            $balance10210 = DocumentJournal::where('debit_account_id', $acc10210)
                    ->whereDate('date', '<', $date)
                    ->sum('amount_amd')
                - DocumentJournal::where('credit_account_id', $acc10210)
                    ->whereDate('date',  '<', $date)
                    ->sum('amount_amd');
        }
        $sheet->setCellValue('J125', $accCount);
        $sheet->getStyle('J125')->getNumberFormat()->setFormatCode('#,##0');

        $sheet->setCellValue('L125', $balance10210);
        $sheet->getStyle('L125')->getNumberFormat()->setFormatCode('#,##0');

        $acc15300 = ChartOfAccount::idByCode('15300');
        $balance15300 = 0;
        if ($acc15300) {
            $balance15300 = DocumentJournal::where('credit_account_id', $acc15300)
                ->whereDate('date', '<', $date)
                ->sum('amount_amd');
        }
        $sheet->setCellValue('N125', $balance15300);
        $sheet->getStyle('N125')->getNumberFormat()->setFormatCode('#,##0');

        $acc19331 = ChartOfAccount::idByCode('19331');
        $balance19331 = 0;
        $debitPartnersCount = 0;
        if ($acc19331) {
            $balance19331 = DocumentJournal::where('debit_account_id', $acc19331)
                ->whereDate('date', '<', $date)
                ->sum('amount_amd');
            $debitPartnersCount = DocumentJournal::where('debit_account_id', $acc19331)
                ->whereDate('date', '<', $date)
                ->distinct('partner_id')
                ->count('partner_id');
        }
        $sheet->setCellValue('R125', $debitPartnersCount);
        $sheet->getStyle('R125')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('T125', $balance19331);
        $sheet->getStyle('T125')->getNumberFormat()->setFormatCode('#,##0');

        $acc19400PC = ChartOfAccount::idByCode('19400PC');
        $balance19400PC = 0;
        if ($acc19400PC) {
            $balance19400PC = DocumentJournal::where('debit_account_id', $acc19400PC)
                ->whereDate('date', '<', $date)
                ->sum('amount_amd');
        }
        $sheet->setCellValue('X125', $balance19400PC);
        $sheet->getStyle('X125')->getNumberFormat()->setFormatCode('#,##0');

        $sheet2 = $spreadsheet->getSheetByName('Sheet2');

        $rowCar  = 89;
        $rowGold = 91;

        $goldAmountBefore = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereHasMorph(
                'journalable',
                [Contract::class],
                function ($q) {
                    $q->whereHas('client.classification', function ($q2) {
                        $q2->whereNotIn('name', ['standard', 'monitored']);
                    });
                    $q->whereHas('category', function ($q3) {
                        $q3->where('name', 'gold');
                    });
                }
            )
            ->whereDate('date', '<', $dateFrom)
            ->sum('amount_amd');
        $carAmountBefore = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereHasMorph(
                'journalable',
                [Contract::class],
                function ($q) {
                    $q->whereHas('client.classification', function ($q2) {
                        $q2->whereNotIn('name', ['standard', 'monitored']);
                    });
                    $q->whereHas('category', function ($q3) {
                        $q3->where('name', 'car');
                    });
                }
            )
            ->whereDate('date', '<', $dateFrom)
            ->sum('amount_amd');

        $carAmountBetween = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereHasMorph(
                'journalable',
                [Contract::class],
                function ($q) {
                    $q->whereHas('client.classification', function ($q2) {
                        $q2->whereNotIn('name', ['standard', 'monitored']);
                    });
                    $q->whereHas('category', function ($q3) {
                        $q3->where('name', 'car');
                    });
                }
            )
            ->whereBetween('date', [$dateFrom, $date])
            ->sum('amount_amd');

        $goldAmountBetween = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereHasMorph(
                'journalable',
                [Contract::class],
                function ($q) {
                    $q->whereHas('client.classification', function ($q2) {
                        $q2->whereNotIn('name', ['standard', 'monitored']);
                    });
                    $q->whereHas('category', function ($q3) {
                        $q3->where('name', 'gold');
                    });
                }
            )
            ->whereBetween('date', [$dateFrom, $date])
            ->sum('amount_amd');


        $sheet2->setCellValue("B{$rowCar}", $carAmountBefore);
        $sheet2->setCellValue("B{$rowGold}", $goldAmountBefore);

        $sheet2->setCellValue("C{$rowCar}", $carAmountBetween);
        $sheet2->setCellValue("C{$rowGold}", $goldAmountBetween);


        $fileName = 'v06_export_' . now()->format('Ymd_His') . '.xls';
        $savePath = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($savePath);

        return $savePath;
    }

    private function getColumnByDays($days)
    {
        if ($days <= 90) return 'B';
        if ($days <= 180) return 'D';
        if ($days <= 270) return 'F';
        if ($days <= 365) return 'H';
        if ($days <= 1825) return 'J';
        return 'L';
    }
}
