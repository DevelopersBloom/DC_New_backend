<?php
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
                $sheet1->setCellValue($col . '23', $data['amount']/1000);
                $sheet1->setCellValue(chr(ord($col) + 1) . '23', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
            }

            // ---------- sheet 2 ----------
            $sheet2 = $spreadsheet->getSheetByName('Sheet2');
            $docsContract = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
                ->whereBetween('date', [$start, $end])
                ->get();

            $groups2 = [
                'B' => ['amount' => 0, 'weighted' => 0],
                'D' => ['amount' => 0, 'weighted' => 0],
                'F' => ['amount' => 0, 'weighted' => 0],
                'H' => ['amount' => 0, 'weighted' => 0],
                'J' => ['amount' => 0, 'weighted' => 0],
                'L' => ['amount' => 0, 'weighted' => 0],
                'N' => ['amount' => 0, 'weighted' => 0],
                'P' => ['amount' => 0, 'weighted' => 0],
            ];


            foreach ($docsContract as $doc) {
                $contract = $doc->journalable_type === 'App\Models\Contract' ? $doc->journalable : null;
                if (!$contract) continue;

                $days = Carbon::parse($contract->deadline)
                    ->diffInDays(Carbon::parse($contract->date));

                $col = $this->getSecondSheetColumnByDays($days);
                $amount = $doc->amount_amd;
                $rate = $contract->interest_rate ? $contract->interest_rate * 365 : 0;

                $groups2[$col]['amount'] += $amount;
                $groups2[$col]['weighted'] += $amount * ($rate / 100);
            }

            foreach ($groups2 as $col => $data) {
                $sheet2->setCellValue($col . '19', $data['amount'] / 1000);
                $sheet2->setCellValue(chr(ord($col)+1) . '19', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
            }
            // ---------- sheet 3 ----------
            $sheet3 = $spreadsheet->getSheetByName('Sheet3');
            $groups3= [
                'C' => ['amount' => 0, 'weighted' => 0],
                'E' => ['amount' => 0, 'weighted' => 0],
                'G' => ['amount' => 0, 'weighted' => 0],
                'I' => ['amount' => 0, 'weighted' => 0],
                'K' => ['amount' => 0, 'weighted' => 0],
                'M' => ['amount' => 0, 'weighted' => 0],
                'O' => ['amount' => 0, 'weighted' => 0],
                'Q' => ['amount' => 0, 'weighted' => 0],
                'S' => ['amount' => 0, 'weighted' => 0],
            ];

            foreach ($docs as $doc) {
                $parentDoc = $doc->journalable;
                $ndm = $doc->parentDoc->journalable;;
                $days = Carbon::parse($ndm->repayment_end_date)
                    ->diffInDays(Carbon::parse($ndm->disbursement_date));

                $col = $this->getForthSheetColumnByDays($days);
                $groups3[$col]['amount'] += $doc->amount_amd;
                $groups3[$col]['weighted'] += $doc->amount_amd * ($ndm->effective_interest_rate / 100);
            }

            foreach ($groups3 as $col => $data) {
                $sheet3->setCellValue($col . '9', $data['amount'] / 1000);
                $sheet3->setCellValue(chr(ord($col) + 1) . '9', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
            }
            $totalAmount = 0;
            foreach ($groups3 as $col => $data) {
                $totalAmount += $data['amount'];
            }
            $sheet3->setCellValue('C46', $totalAmount);
            $sheet3->getStyle('C46')->getNumberFormat()->setFormatCode('#,##0');

            // ---------- sheet 4 ----------
            $sheet4 = $spreadsheet->getSheetByName('Sheet4');

            $groups4= [
                'C' => ['amount' => 0, 'weighted' => 0],
                'E' => ['amount' => 0, 'weighted' => 0],
                'G' => ['amount' => 0, 'weighted' => 0],
                'I' => ['amount' => 0, 'weighted' => 0],
                'K' => ['amount' => 0, 'weighted' => 0],
                'M' => ['amount' => 0, 'weighted' => 0],
                'O' => ['amount' => 0, 'weighted' => 0],
                'Q' => ['amount' => 0, 'weighted' => 0],
                'S' => ['amount' => 0, 'weighted' => 0],
            ];
            foreach ($docsContract as $doc) {
                $contract = $doc->journalable_type === 'App\Models\Contract' ? $doc->journalable : null;
                if (!$contract) continue;

                $days = Carbon::parse($contract->deadline)
                    ->diffInDays(Carbon::parse($contract->date));

                $col = $this->getForthSheetColumnByDays($days);
                $amount = $doc->amount_amd;
                $rate = $contract->effective_daily_rate ? $contract->effective_daily_rate * 365 : 0;

                $groups4[$col]['amount'] += $amount;
                $groups4[$col]['weighted'] += $amount * ($rate / 100);
            }

            foreach ($groups4 as $col => $data) {
                $sheet4->setCellValue($col . '15', $data['amount']/ 1000);
                $sheet4->setCellValue(chr(ord($col) + 1) . '15', $data['amount'] > 0 ? round(($data['weighted'] / $data['amount']) * 100, 2) : 0);
            }
            $totalAmount4 = 0;
            foreach ($groups4 as $col => $data) {
                $totalAmount4 += $data['amount'];
            }

            $sheet4->setCellValue('C87', $totalAmount4);
            $sheet4->getStyle('C87')->getNumberFormat()->setFormatCode('#,##0');

            $fileName = 'v17_export_' . now()->format('Ymd_His') . '.xls';
            $path = storage_path('app/public/' . $fileName);
            $writer = new Xls($spreadsheet);
            $writer->save($path);

            return $path;
        }
        private function getColumnByDays($days)
        {
            if ($days <= 0) return 'C';
            if ($days <= 15) return 'E';
            if ($days <= 30) return 'G';
            if ($days <= 60) return 'I';
            if ($days <= 90) return 'K';
            if ($days <= 180) return 'M';
            if ($days <= 365) return 'O';
            return 'Q';
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
        private function getForthSheetColumnByDays($days)
        {
            if ($days <= 0) return 'C';
            if ($days <= 15) return 'E';
            if ($days <= 30) return 'G';
            if ($days <= 60) return 'I';
            if ($days <= 90) return 'K';
            if ($days <= 180) return 'M';
            if ($days <= 365) return 'O';
            if ($days <= 1805) return 'Q';
            return 'S';
        }
    }
