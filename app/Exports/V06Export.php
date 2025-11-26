<?php

namespace App\Exports;

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
        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract) continue;

            $hasExpiredPayment = $contract->payments
                ->contains(function ($p) use ($date) {
                    return Carbon::parse($p->date)->lt($date);
                });

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
