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
dd($docs);
        $groups = [
            'B' => 0, // <= 90
            'D' => 0, // 91–180
            'F' => 0, // 181–270
            'H' => 0, // 271–365
            'J' => 0, // 1–5
            'L' => 0, // >5
        ];

        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract) continue;

            $hasExpiredPayment = $contract->payments
                ->contains(function ($p) use ($date) {
                    return Carbon::parse($p->date)->lt($date);
                });

            if ($hasExpiredPayment) {
                continue;
            }

            $days = Carbon::parse($contract->deadline)
                ->diffInDays(Carbon::parse($contract->date));

            $col = $this->getColumnByDays($days);

            $groups[$col] += $doc->amount_amd;
        }

        $row = 15;
        foreach ($groups as $col => $value) {
            $sheet->setCellValue($col . $row, $value / 1000);
            $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
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
