<?php
////
////namespace App\Exports;
////
////use App\Models\LoanNdm;
////use Carbon\Carbon;
////use PhpOffice\PhpSpreadsheet\Spreadsheet;
////use PhpOffice\PhpSpreadsheet\Writer\Xls;
////use PhpOffice\PhpSpreadsheet\IOFactory;
////
////class V17Export
////{
////    public function export($from, $to)
////    {
////        $path = base_path('v17.XLS');
////
////        $reader = IOFactory::createReader('Xls');
////        $spreadsheet = $reader->load($path);
////
////        $sheet = $spreadsheet->getSheetByName('Sheet1');
////
////        $start = Carbon::parse($from)->startOfDay();
////        $end   = Carbon::parse($to)->endOfDay();
////
////        $ndms = LoanNdm::whereBetween('disbursement_date', [$start, $end])->get();
////
////        $totalAmount = $ndms->sum('amount');
////
////        $weighted = $ndms->sum(function($n) {
////            return $n->amount * ($n->interest_rate / 100);
////        });
////
////        $middleRate = $totalAmount > 0
////            ? round(($weighted / $totalAmount) * 100, 2)
////            : 0;
////
////        $days = Carbon::parse($ndms->first()->repayment_end_date)
////            ->diffInDays(Carbon::parse($ndms->first()->disbursement_date));
////
////        if ($days <= 0) {
////            $colAmount = 'C';
////            $colRate   = 'D';
////        } elseif ($days <= 15) {
////            $colAmount = 'E';
////            $colRate   = 'F';
////        } elseif ($days <= 30) {
////            $colAmount = 'G';
////            $colRate   = 'H';
////        } elseif ($days <= 60) {
////            $colAmount = 'I';
////            $colRate   = 'J';
////        } elseif ($days <= 90) {
////            $colAmount = 'K';
////            $colRate   = 'L';
////        } elseif ($days <= 180) {
////            $colAmount = 'M';
////            $colRate   = 'N';
////        } elseif ($days <= 365) {
////            $colAmount = 'O';
////            $colRate   = 'P';
////        } else {
////            $colAmount = 'Q';
////            $colRate   = 'R';
////        }
////
////        $sheet->setCellValue($colAmount . '23', $totalAmount);
////        $sheet->setCellValue($colRate   . '23', $middleRate);
////        $fileName = 'v17_export_' . now()->format('Ymd_His') . '.xls';
////        $path = storage_path('app/public/' . $fileName);
////
////        $writer = new Xls($spreadsheet);
////        $writer->save($path);
////
////        return $path;
////    }
////}
//
//
//namespace App\Exports;
//
//use App\Models\LoanNdm;
//use Carbon\Carbon;
//use PhpOffice\PhpSpreadsheet\IOFactory;
//use PhpOffice\PhpSpreadsheet\Writer\Xls;
//
//class V17Export
//{
//    public function export($from, $to)
//    {
//        $path = base_path('v17.XLS');
//        $reader = IOFactory::createReader('Xls');
//        $spreadsheet = $reader->load($path);
//        $sheet = $spreadsheet->getSheetByName('Sheet1');
//
//        $start = Carbon::parse($from)->startOfDay();
//        $end = Carbon::parse($to)->endOfDay();
//
//        $ndms = LoanNdm::whereBetween('disbursement_date', [$start, $end])->get();
//
//        $groups = [
//            'C' => ['amount' => 0, 'weighted' => 0],
//            'E' => ['amount' => 0, 'weighted' => 0], // <=15
//            'G' => ['amount' => 0, 'weighted' => 0], // 16-30
//            'I' => ['amount' => 0, 'weighted' => 0], // 31-60
//            'K' => ['amount' => 0, 'weighted' => 0], // 61-90
//            'M' => ['amount' => 0, 'weighted' => 0], // 91-180
//            'O' => ['amount' => 0, 'weighted' => 0], // 181-365
//            'Q' => ['amount' => 0, 'weighted' => 0], // >365
//        ];
//
//        foreach ($ndms as $n) {
//            $days = Carbon::parse($n->repayment_end_date)
//                ->diffInDays(Carbon::parse($n->disbursement_date));
//
//            if ($days <= 0) {
//                $col = 'C';
//            } elseif ($days <= 15) {
//                $col = 'E';
//            } elseif ($days <= 30) {
//                $col = 'G';
//            } elseif ($days <= 60) {
//                $col = 'I';
//            } elseif ($days <= 90) {
//                $col = 'K';
//            } elseif ($days <= 180) {
//                $col = 'M';
//            } elseif ($days <= 365) {
//                $col = 'O';
//            } else {
//                $col = 'Q';
//            }
//
//            $groups[$col]['amount'] += $n->amount;
//            $groups[$col]['weighted'] += $n->amount * ($n->interest_rate / 100);
//        }
//
//        foreach ($groups as $col => $data) {
//            $sheet->setCellValue($col . '23', $data['amount']);
//            $rateCol = chr(ord($col) + 1); // C->D, E->F, ...
//            $middleRate = $data['amount'] > 0
//                ? round(($data['weighted'] / $data['amount']) * 100, 2)
//                : 0;
//            $sheet->setCellValue($rateCol . '23', $middleRate);
//        }
//
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
use App\Models\DocumentJournal;
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

        $start = Carbon::parse($from)->startOfDay();
        $end   = Carbon::parse($to)->endOfDay();

        // ---------- sheet 1 ----------
        $sheet1 = $spreadsheet->getSheetByName('Sheet1');
//        $ndms = LoanNdm::whereBetween('disbursement_date', [$start, $end])->get();
        $docs = DocumentJournal::with(['parentDoc.journalable'])
            ->where('document_type', DocumentJournal::LOAN_ATTRACTION)
            ->get();
        $groups1 = [
            'C' => ['amount' => 0, 'weighted' => 0],
            'E' => ['amount' => 0, 'weighted' => 0],
            'G' => ['amount' => 0, 'weighted' => 0],
            'I' => ['amount' => 0, 'weighted' => 0],
            'K' => ['amount' => 0, 'weighted' => 0],
            'M' => ['amount' => 0, 'weighted' => 0],
            'O' => ['amount' => 0, 'weighted' => 0],
            'Q' => ['amount' => 0, 'weighted' => 0],
        ];

        foreach ($docs as $doc) {
            $parentDoc = $doc->journalable;
            $ndm = $doc->parentDoc->journalable;;
            $days = Carbon::parse($ndm->repayment_end_date)
                ->diffInDays(Carbon::parse($ndm->disbursement_date));

            $col = $this->getColumnByDays($days);
            $groups1[$col]['amount'] += $doc->amount_amd;
            $groups1[$col]['weighted'] += $doc->amount_amd * ($ndm->interest_rate / 100);
        }

        foreach ($groups1 as $col => $data) {
            $sheet1->setCellValue($col . '23', $data['amount']);
            $sheet1->setCellValue(chr(ord($col) + 1) . '23', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
        }

        // ---------- sheet 2 ----------
        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
        $docs = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereBetween('date', [$start, $end])
            ->get();

        $groups2 = [
            'C' => ['amount' => 0, 'weighted' => 0],
            'E' => ['amount' => 0, 'weighted' => 0],
            'G' => ['amount' => 0, 'weighted' => 0],
            'I' => ['amount' => 0, 'weighted' => 0],
            'K' => ['amount' => 0, 'weighted' => 0],
            'M' => ['amount' => 0, 'weighted' => 0],
            'O' => ['amount' => 0, 'weighted' => 0],
            'Q' => ['amount' => 0, 'weighted' => 0],
        ];

        foreach ($docs as $doc) {
            $contract = $doc->journalable_type === 'App\Models\Contract' ? $doc->journalable : null;
            if (!$contract) continue;

            $days = Carbon::parse($contract->deadline)
                ->diffInDays(Carbon::parse($contract->date));

            $col = $this->getColumnByDays($days);
            $amount = $contract->provided_amount;
            $rate = $contract->interest_rate ? $contract->interest_rate * 365 : 0;

            $groups2[$col]['amount'] += $amount;
            $groups2[$col]['weighted'] += $amount * ($rate / 100);
        }

        foreach ($groups2 as $col => $data) {
            $sheet2->setCellValue($col . '19', $data['amount']);
            $sheet2->setCellValue(chr(ord($col)) . '19', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
        }
        // ---------- sheet 3 ----------
        $sheet3 = $spreadsheet->getSheetByName('Sheet3');
        $groups3 = [
            'B' => ['amount' => 0, 'weighted' => 0],
            'D' => ['amount' => 0, 'weighted' => 0],
            'F' => ['amount' => 0, 'weighted' => 0],
            'H' => ['amount' => 0, 'weighted' => 0],
            'J' => ['amount' => 0, 'weighted' => 0],
            'L' => ['amount' => 0, 'weighted' => 0],
            'N' => ['amount' => 0, 'weighted' => 0],
            'P' => ['amount' => 0, 'weighted' => 0],
        ];

        foreach ($docs as $doc) {
            $contract = $doc->journalable_type === 'App\Models\Contract' ? $doc->journalable : null;
            if (!$contract) continue;

            $days = Carbon::parse($contract->deadline)
                ->diffInDays(Carbon::parse($contract->date));

            $col = $this->getColumnByDays($days);
            $amount = $contract->provided_amount;
            $rate = $contract->effective_annual_rate ?? 0;
            $groups3[$col]['amount'] += $amount;
            $groups3[$col]['weighted'] += $amount * ($rate / 100);
        }

        foreach ($groups3 as $col => $data) {
            $sheet3->setCellValue($col . '8', $data['amount']);
            $sheet3->setCellValue(chr(ord($col) + 1) . '8', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
        }

        // ---------- sheet 4 ----------
        $sheet4 = $spreadsheet->getSheetByName('Sheet4');
        $groups4 = $groups3;

        foreach ($groups4 as $col => $data) {
            $sheet4->setCellValue($col . '14', $data['amount']);
            $sheet4->setCellValue(chr(ord($col) + 1) . '14', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
        }

        $fileName = 'v17_export_' . now()->format('Ymd_His') . '.xls';
        $path = storage_path('app/public/' . $fileName);
        $writer = new Xls($spreadsheet);
        $writer->save($path);

        return $path;
    }


    private function getSecondSheetColumnByDays($days)
    {
        if ($days <= 0) return 'B';
        if ($days <= 15) return 'D';
        if ($days <= 30) return 'F';
        if ($days <= 60) return 'H';
        if ($days <= 90) return 'J';
        if ($days <= 180) return 'L';
        if ($days <= 365) return 'N';
        return 'P';
    }
}
