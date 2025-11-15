<?php
//
//namespace App\Exports;
//
//use App\Models\LoanNdm;
//use Carbon\Carbon;
//use PhpOffice\PhpSpreadsheet\Spreadsheet;
//use PhpOffice\PhpSpreadsheet\Writer\Xls;
//use PhpOffice\PhpSpreadsheet\IOFactory;
//
//class V17Export
//{
//    public function export($from, $to)
//    {
//        $path = base_path('v17.XLS');
//
//        $reader = IOFactory::createReader('Xls');
//        $spreadsheet = $reader->load($path);
//
//        $sheet = $spreadsheet->getSheetByName('Sheet1');
//
//        $start = Carbon::parse($from)->startOfDay();
//        $end   = Carbon::parse($to)->endOfDay();
//
//        $ndms = LoanNdm::whereBetween('disbursement_date', [$start, $end])->get();
//
//        $totalAmount = $ndms->sum('amount');
//
//        $weighted = $ndms->sum(function($n) {
//            return $n->amount * ($n->interest_rate / 100);
//        });
//
//        $middleRate = $totalAmount > 0
//            ? round(($weighted / $totalAmount) * 100, 2)
//            : 0;
//
//        $days = Carbon::parse($ndms->first()->repayment_end_date)
//            ->diffInDays(Carbon::parse($ndms->first()->disbursement_date));
//
//        if ($days <= 0) {
//            $colAmount = 'C';
//            $colRate   = 'D';
//        } elseif ($days <= 15) {
//            $colAmount = 'E';
//            $colRate   = 'F';
//        } elseif ($days <= 30) {
//            $colAmount = 'G';
//            $colRate   = 'H';
//        } elseif ($days <= 60) {
//            $colAmount = 'I';
//            $colRate   = 'J';
//        } elseif ($days <= 90) {
//            $colAmount = 'K';
//            $colRate   = 'L';
//        } elseif ($days <= 180) {
//            $colAmount = 'M';
//            $colRate   = 'N';
//        } elseif ($days <= 365) {
//            $colAmount = 'O';
//            $colRate   = 'P';
//        } else {
//            $colAmount = 'Q';
//            $colRate   = 'R';
//        }
//
//        $sheet->setCellValue($colAmount . '23', $totalAmount);
//        $sheet->setCellValue($colRate   . '23', $middleRate);
//        $fileName = 'v17_export_' . now()->format('Ymd_His') . '.xls';
//        $path = storage_path('app/public/' . $fileName);
//
//        $writer = new Xls($spreadsheet);
//        $writer->save($path);
//
//        return $path;
//    }
//}


namespace App\Exports;

use App\Models\LoanNdm;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class V17Export
{
    public function export($from, $to)
    {
        $path = base_path('v17.XLS');
        $reader = IOFactory::createReader('Xls');
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName('Sheet1');

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $ndms = LoanNdm::whereBetween('disbursement_date', [$start, $end])->get();

        $groups = [
            'C' => ['amount' => 0, 'weighted' => 0],
            'E' => ['amount' => 0, 'weighted' => 0], // <=15
            'G' => ['amount' => 0, 'weighted' => 0], // 16-30
            'I' => ['amount' => 0, 'weighted' => 0], // 31-60
            'K' => ['amount' => 0, 'weighted' => 0], // 61-90
            'M' => ['amount' => 0, 'weighted' => 0], // 91-180
            'O' => ['amount' => 0, 'weighted' => 0], // 181-365
            'Q' => ['amount' => 0, 'weighted' => 0], // >365
        ];

        foreach ($ndms as $n) {
            $days = Carbon::parse($n->repayment_end_date)
                ->diffInDays(Carbon::parse($n->disbursement_date));

            if ($days <= 0) {
                $col = 'C';
            } elseif ($days <= 15) {
                $col = 'E';
            } elseif ($days <= 30) {
                $col = 'G';
            } elseif ($days <= 60) {
                $col = 'I';
            } elseif ($days <= 90) {
                $col = 'K';
            } elseif ($days <= 180) {
                $col = 'M';
            } elseif ($days <= 365) {
                $col = 'O';
            } else {
                $col = 'Q';
            }

            $groups[$col]['amount'] += $n->amount;
            $groups[$col]['weighted'] += $n->amount * ($n->interest_rate / 100);
        }

        foreach ($groups as $col => $data) {
            $sheet->setCellValue($col . '23', $data['amount']);
            $rateCol = chr(ord($col) + 1); // C->D, E->F, ...
            $middleRate = $data['amount'] > 0
                ? round(($data['weighted'] / $data['amount']) * 100, 2)
                : 0;
            $sheet->setCellValue($rateCol . '23', $middleRate);
        }

        $fileName = 'v17_export_' . now()->format('Ymd_His') . '.xls';
        $path = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($path);

        return $path;
    }
}
