<?php

//
//
//namespace App\Exports\Reports;
//
//use App\Models\ChartOfAccount;
//use App\Models\Contract;
//use App\Models\DocumentJournal;
//use Carbon\Carbon;
//use Illuminate\Support\Collection;
//use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
//use PhpOffice\PhpSpreadsheet\Cell\DataType;
//use PhpOffice\PhpSpreadsheet\IOFactory;
//use PhpOffice\PhpSpreadsheet\Writer\Xls;
//use PhpOffice\PhpSpreadsheet\Shared\Date;
//
//class V09Export
//{
//    /** @var array<int, DocumentJournal> */
//    private array $v09JournalParentCache = [];
//
//    public function export($from, $to)
//    {
//        $templatePath = base_path('v09.xls');
//        $reader = IOFactory::createReader('Xls');
//        $spreadsheet = $reader->load($templatePath);
//        $sheet = $spreadsheet->getSheetByName('Sheet1');
//
//        $fromDate = Carbon::parse($from);
//        $toDate = Carbon::parse($to);
//        $dateStr = $toDate->format('Y-m-d');
//
//        $sheet->setCellValueExplicit('B10', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
//        $sheet->setCellValue('C11', Date::PHPToExcel($fromDate));
//        $sheet->setCellValue('E11', Date::PHPToExcel($toDate));
//        $sheet->getStyle('C11:E11')->getNumberFormat()->setFormatCode('dd/mm/yy');
//
//        $acc16200NV = ChartOfAccount::idByCode('16200NV');
//        $acc16201NI = ChartOfAccount::idByCode('16201NI');
//
//        $acc10000 = ChartOfAccount::idByCode('10000');
//        $balance10000 = $acc10000 ? $this->getAccountBalance($acc10000,$dateStr) : 0;
//        $acc10001 = ChartOfAccount::idByCode('10001');
//        $balance10001 = $acc10001 ? $this->getAccountBalance($acc10001,$dateStr) : 0;
//        $cashBalance = $balance10000 + $balance10001;
//        $sheet->setCellValue('E17', $cashBalance / 1000);
//
//        $bankAccount102101 = ChartOfAccount::idByCode('102101');
//        $bankBalance102101 = $bankAccount102101 ? $this->getAccountBalance($bankAccount102101, $dateStr) : 0;
//        $bankAccount102102 = ChartOfAccount::idByCode('102102');
//        $bankBalance102102 = $bankAccount102102 ? $this->getAccountBalance($bankAccount102102, $dateStr) : 0;
//        $bankBalance = $bankBalance102101 + $bankBalance102102;
//        $sheet->setCellValue('E19', $bankBalance / 1000);
//
//        $sheet->setCellValue('E44', ($cashBalance + $bankBalance) / 1000);
//
//        $acc19000 = ChartOfAccount::idByCode('19000');
//        $balance19000 = $acc19000 ? $this->getAccountBalance($acc19000, $dateStr) : 0;
//        $sheet->setCellValue('F22', $balance19000 / 1000);
//        $sheet->setCellValue('F44', $balance19000 / 1000);
//
//        $docs = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->whereDate('date', '<=', $dateStr)
//            ->get();
//
//        $acc39210 = ChartOfAccount::idByCode('39210');
//        $acc39102Group = ChartOfAccount::whereIn('code', ['3910201', '3910202', '3910203'])->pluck('id')->toArray();
//        $acc39200Account = ChartOfAccount::where('code', '39200')->first();
//
//        $this->fillRow31And43Contract16200Nv($sheet, $acc16200NV, $toDate, $dateStr);
//
//        foreach ($docs as $doc) {
//            $contract = $doc->journalable;
//            if (!$contract || $contract->status != 'initial') continue;
//            $remainingDays = $this->remainingDaysUntilDeadline($toDate, $contract->deadline);
//            $col = $this->getColumnByDaysV09($remainingDays);
//
//            // 16201NI
//            if ($acc16201NI) {
//                $balanceNI = $this->getSpecificBalance($contract->id, $doc->id, $acc16201NI, $dateStr, 'active');
//                if ($balanceNI != 0) {
//                    $prevNI = (float)$sheet->getCell($col . '37')->getValue();
//                    $sheet->setCellValue($col . '37', $prevNI + ($balanceNI / 1000));
//                }
//            }
//
//            // 39210
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
//        }
//
//        $this->fixPColumnTotals($sheet);
//
//        $fileName = 'v09_export_' . now()->format('Ymd_His') . '.xls';
//        $outputPath = storage_path('app/public/' . $fileName);
//        $writer = new Xls($spreadsheet);
//        $writer->save($outputPath);
//
//        return $outputPath;
//    }
//
//    /**
//     * Row 31 / 43: one pass per contract — full 16200NV net on the contract journal tree, bucketed by
//     * days from report "to" date until contract deadline (no PROVIDE_CONTRACT_AMOUNT iteration).
//     */
//    private function fillRow31And43Contract16200Nv(
//        Worksheet $sheet,
//        ?int $acc16200NV,
//        Carbon $reportTo,
//        string $dateStr
//    ): void {
//        if (!$acc16200NV) {
//            return;
//        }
//
//        $balancesByContract = $this->collect16200NvNetByContractId($acc16200NV, $dateStr);
//        $positiveIds = array_keys(array_filter($balancesByContract, static fn (float $b): bool => $b > 0));
//
//        if ($positiveIds === []) {
//            return;
//        }
//
//        $contracts = Contract::query()
//            ->whereIn('id', $positiveIds)
//            ->get(['id', 'deadline']);
//
//        foreach ($contracts as $contract) {
//            $balance = $balancesByContract[$contract->id] ?? 0.0;
//            if ($balance <= 0 || $contract->deadline === null) {
//                continue;
//            }
//
//            $remainingDays = $this->remainingDaysUntilDeadline($reportTo, $contract->deadline);
//            $col = $this->getColumnByDaysV09($remainingDays);
//            $value = $balance / 1000;
//
//            $prevNV = (float) $sheet->getCell($col . '31')->getValue();
//            $sheet->setCellValue($col . '31', $prevNV + $value);
//
//            $prev43 = (float) $sheet->getCell($col . '43')->getValue();
//            $sheet->setCellValue($col . '43', $prev43 + $value);
//        }
//    }
//
//    /**
//     * Net 16200NV (debit − credit) on documents_journal, grouped by owning contract.
//     * Includes lines on the contract morph and on any nested DocumentJournal chain that resolves to that contract.
//     */
//    private function collect16200NvNetByContractId(int $accountId, string $dateStr): array
//    {
//        $this->v09JournalParentCache = [];
//
//        $lines = DocumentJournal::query()
//            ->whereDate('date', '<=', $dateStr)
//            ->where(function ($q) use ($accountId) {
//                $q->where('debit_account_id', $accountId)
//                    ->orWhere('credit_account_id', $accountId);
//            })
//            ->get(['id', 'journalable_type', 'journalable_id', 'debit_account_id', 'credit_account_id', 'amount_amd']);
//
//        $this->prefetchJournalParentsForLines($lines);
//
//        $balances = [];
//
//        foreach ($lines as $line) {
//            $contractId = $this->resolveContractIdForJournalable($line->journalable_type, (int) $line->journalable_id);
//            if ($contractId === null) {
//                continue;
//            }
//
//            $debit = $line->debit_account_id == $accountId ? (float) $line->amount_amd : 0.0;
//            $credit = $line->credit_account_id == $accountId ? (float) $line->amount_amd : 0.0;
//            $balances[$contractId] = ($balances[$contractId] ?? 0.0) + ($debit - $credit);
//        }
//
//        return $balances;
//    }
//
//    private function remainingDaysUntilDeadline(Carbon $reportTo, $deadline): int
//    {
//        $reportDay = $reportTo->copy()->startOfDay();
//        $deadlineDay = Carbon::parse($deadline)->startOfDay();
//
//        return (int) $reportDay->diffInDays($deadlineDay, false);
//    }
//
//    private function morphIsContract(?string $type): bool
//    {
//        return $type !== null && is_a($type, Contract::class, true);
//    }
//
//    private function morphIsDocumentJournal(?string $type): bool
//    {
//        return $type !== null && is_a($type, DocumentJournal::class, true);
//    }
//
//    /**
//     * Load every DocumentJournal row needed to walk journalable_id chains down to Contract.
//     */
//    private function prefetchJournalParentsForLines(Collection $lines): void
//    {
//        $pending = [];
//
//        foreach ($lines as $line) {
//            if ($this->morphIsDocumentJournal($line->journalable_type)) {
//                $pending[(int) $line->journalable_id] = true;
//            }
//        }
//
//        $guard = 0;
//        while ($pending !== [] && $guard++ < 500) {
//            $batch = array_keys(array_diff_key($pending, $this->v09JournalParentCache));
//            $pending = [];
//
//            if ($batch === []) {
//                break;
//            }
//
//            $rows = DocumentJournal::query()
//                ->whereIn('id', $batch)
//                ->get(['id', 'journalable_type', 'journalable_id']);
//
//            foreach ($rows as $row) {
//                $this->v09JournalParentCache[$row->id] = $row;
//                if ($this->morphIsDocumentJournal($row->journalable_type)) {
//                    $pending[(int) $row->journalable_id] = true;
//                }
//            }
//        }
//    }
//
//    private function resolveContractIdForJournalable(?string $type, int $id): ?int
//    {
//        if ($this->morphIsContract($type)) {
//            return $id;
//        }
//
//        if (!$this->morphIsDocumentJournal($type)) {
//            return null;
//        }
//
//        $currentId = $id;
//        for ($i = 0; $i < 200; $i++) {
//            if (!isset($this->v09JournalParentCache[$currentId])) {
//                return null;
//            }
//
//            $parent = $this->v09JournalParentCache[$currentId];
//            $pType = $parent->journalable_type;
//            $pId = (int) $parent->journalable_id;
//
//            if ($this->morphIsContract($pType)) {
//                return $pId;
//            }
//
//            if ($this->morphIsDocumentJournal($pType)) {
//                $currentId = $pId;
//                continue;
//            }
//
//            return null;
//        }
//
//        return null;
//    }
//
//    private function getColumnByDaysV09($days): string
//    {
//        if ($days <= 0) return 'E';
//        if ($days <= 30) return 'F';
//        if ($days <= 60) return 'G';
//        if ($days <= 90) return 'H';
//        if ($days <= 120) return 'I';
//        if ($days <= 150) return 'J';
//        if ($days <= 180) return 'K';
//        if ($days <= 366) return 'L';
//        if ($days <= 1096) return 'M';
//        return 'N';
//    }
//
//    private function getSpecificBalance($contractId, $docId, $accountId, $date, $type = 'active')
//    {
//        $accIds = is_array($accountId) ? $accountId : [$accountId];
//        $accIds = array_filter($accIds);
//
//        if (empty($accIds)) return 0;
//
//        $query = DocumentJournal::where(function ($q) use ($contractId, $docId) {
//            $q->where(function ($inner) use ($contractId) {
//                $inner->where('journalable_type', Contract::class)
//                    ->where('journalable_id', $contractId);
//            })->orWhere(function ($inner) use ($docId) {
//                $inner->where('journalable_type', DocumentJournal::class)
//                    ->where('journalable_id', $docId);
//            });
//        })->whereDate('date', '<=', $date);
//
//        $debit = (clone $query)->whereIn('debit_account_id', $accIds)->sum('amount_amd');
//        $credit = (clone $query)->whereIn('credit_account_id', $accIds)->sum('amount_amd');
//
//        return ($type === 'active') ? ($debit - $credit) : ($credit - $debit);
//    }
//
//    private function getAccountBalance($accountId, $date)
//    {
//        $debit = DocumentJournal::where('debit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
//        $credit = DocumentJournal::where('credit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
//        return $debit - $credit;
//    }
//
//    private function fixPColumnTotals(Worksheet $sheet): void
//    {
//        $highestRow = $sheet->getHighestRow();
//
//        for ($row = 1; $row <= $highestRow; $row++) {
//            $formula = $sheet->getCell('P' . $row)->getValue();
//
//            if (!is_string($formula) || strncmp($formula, '=', 1) !== 0) {
//                continue;
//            }
//
//            $correctFormula = preg_replace(
//                '/^=SUM\\(C' . $row . ':C' . $row . '\\)$/i',
//                '=SUM(C' . $row . ':O' . $row . ')',
//                $formula
//            );
//
//            if ($correctFormula !== null && $correctFormula !== $formula) {
//                $sheet->setCellValue('P' . $row, $correctFormula);
//            }
//        }
//    }
//}
//namespace App\Exports\Reports;
//
//use App\Models\ChartOfAccount;
//use App\Models\Contract;
//use App\Models\DocumentJournal;
//use Carbon\Carbon;
//use Illuminate\Support\Collection;
//use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
//use PhpOffice\PhpSpreadsheet\Cell\DataType;
//use PhpOffice\PhpSpreadsheet\IOFactory;
//use PhpOffice\PhpSpreadsheet\Writer\Xls;
//use PhpOffice\PhpSpreadsheet\Shared\Date;
//
//class V09Export
//{
//    /** @var array<int, DocumentJournal> */
//    private array $v09JournalParentCache = [];
//
//    public function export($from, $to)
//    {
//        $templatePath = base_path('v09.xls');
//        $reader = IOFactory::createReader('Xls');
//        $spreadsheet = $reader->load($templatePath);
//        $sheet = $spreadsheet->getSheetByName('Sheet1');
//
//        $fromDate = Carbon::parse($from);
//        $toDate = Carbon::parse($to);
//        $dateStr = $toDate->format('Y-m-d');
//
//        $sheet->setCellValueExplicit('B10', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
//        $sheet->setCellValue('C11', Date::PHPToExcel($fromDate));
//        $sheet->setCellValue('E11', Date::PHPToExcel($toDate));
//        $sheet->getStyle('C11:E11')->getNumberFormat()->setFormatCode('dd/mm/yy');
//
//        $acc16200NV = ChartOfAccount::idByCode('16200NV');
//        $acc16201NI = ChartOfAccount::idByCode('16201NI');
//
//        $acc10000 = ChartOfAccount::idByCode('10000');
//        $balance10000 = $acc10000 ? $this->getAccountBalance($acc10000,$dateStr) : 0;
//        $acc10001 = ChartOfAccount::idByCode('10001');
//        $balance10001 = $acc10001 ? $this->getAccountBalance($acc10001,$dateStr) : 0;
//        $cashBalance = $balance10000 + $balance10001;
//        $sheet->setCellValue('E17', $cashBalance / 1000);
//
//        $bankAccount102101 = ChartOfAccount::idByCode('102101');
//        $bankBalance102101 = $bankAccount102101 ? $this->getAccountBalance($bankAccount102101, $dateStr) : 0;
//        $bankAccount102102 = ChartOfAccount::idByCode('102102');
//        $bankBalance102102 = $bankAccount102102 ? $this->getAccountBalance($bankAccount102102, $dateStr) : 0;
//        $bankBalance = $bankBalance102101 + $bankBalance102102;
//        $sheet->setCellValue('E19', $bankBalance / 1000);
//
//        $sheet->setCellValue('E44', ($cashBalance + $bankBalance) / 1000);
//
//        $acc19000 = ChartOfAccount::idByCode('19000');
//        $balance19000 = $acc19000 ? $this->getAccountBalance($acc19000, $dateStr) : 0;
//        $sheet->setCellValue('F22', $balance19000 / 1000);
//        $sheet->setCellValue('F44', $balance19000 / 1000);
//
//        $docs = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->whereDate('date', '<=', $dateStr)
//            ->get();
//
//        $acc39210 = ChartOfAccount::idByCode('39210');
//        $acc39102Group = ChartOfAccount::whereIn('code', ['3910201', '3910202', '3910203'])->pluck('id')->toArray();
//        $acc39200Account = ChartOfAccount::where('code', '39200')->first();
//
//        $this->fillRow31And43Contract16200Nv($sheet, $acc16200NV, $toDate, $dateStr);
//
//        foreach ($docs as $doc) {
//            $contract = $doc->journalable;
//            if (!$contract || $contract->status != 'initial') continue;
//            $remainingDays = $this->remainingDaysUntilDeadline($toDate, $contract->deadline);
//            $col = $this->getColumnByDaysV09($remainingDays);
//
//            // 16201NI
//            if ($acc16201NI) {
//                $balanceNI = $this->getSpecificBalance($contract->id, $doc->id, $acc16201NI, $dateStr, 'active');
//                if ($balanceNI != 0) {
//                    $prevNI = (float)$sheet->getCell($col . '37')->getValue();
//                    $sheet->setCellValue($col . '37', $prevNI + ($balanceNI / 1000));
//                }
//            }
//
//            // 39210
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
//        }
//
//        $this->fixPColumnTotals($sheet);
//
//        $fileName = 'v09_export_' . now()->format('Ymd_His') . '.xls';
//        $outputPath = storage_path('app/public/' . $fileName);
//        $writer = new Xls($spreadsheet);
//        $writer->save($outputPath);
//
//        return $outputPath;
//    }
//
//    /**
//     * Row 31 / 43: one pass per contract — full 16200NV net on the contract journal tree, bucketed by
//     * days from report "to" date until contract deadline (no PROVIDE_CONTRACT_AMOUNT iteration).
//     */
//    private function fillRow31And43Contract16200Nv(
//        Worksheet $sheet,
//        ?int $acc16200NV,
//        Carbon $reportTo,
//        string $dateStr
//    ): void {
//        if (!$acc16200NV) {
//            return;
//        }
//
//        $balancesByContract = $this->collect16200NvNetByContractId($acc16200NV, $dateStr);
//        $positiveIds = array_keys(array_filter($balancesByContract, static fn (float $b): bool => $b > 0));
//
//        if ($positiveIds === []) {
//            return;
//        }
//
//        $contracts = Contract::query()
//            ->whereIn('id', $positiveIds)
//            ->get(['id', 'deadline']);
//
//        foreach ($contracts as $contract) {
//            $balance = $balancesByContract[$contract->id] ?? 0.0;
//            if ($balance <= 0 || $contract->deadline === null) {
//                continue;
//            }
//
//            $remainingDays = $this->remainingDaysUntilDeadline($reportTo, $contract->deadline);
//            $col = $this->getColumnByDaysV09($remainingDays);
//            $value = $balance / 1000;
//
//            $prevNV = (float) $sheet->getCell($col . '31')->getValue();
//            $sheet->setCellValue($col . '31', $prevNV + $value);
//
//            $prev43 = (float) $sheet->getCell($col . '43')->getValue();
//            $sheet->setCellValue($col . '43', $prev43 + $value);
//        }
//    }
//
//    /**
//     * Net 16200NV (debit − credit) on documents_journal, grouped by owning contract.
//     * Includes lines on the contract morph and on any nested DocumentJournal chain that resolves to that contract.
//     */
//    private function collect16200NvNetByContractId(int $accountId, string $dateStr): array
//    {
//        $this->v09JournalParentCache = [];
//
//        $lines = DocumentJournal::query()
//            ->whereDate('date', '<=', $dateStr)
//            ->where(function ($q) use ($accountId) {
//                $q->where('debit_account_id', $accountId)
//                    ->orWhere('credit_account_id', $accountId);
//            })
//            ->get(['id', 'journalable_type', 'journalable_id', 'debit_account_id', 'credit_account_id', 'amount_amd']);
//
//        $this->prefetchJournalParentsForLines($lines);
//
//        $balances = [];
//
//        foreach ($lines as $line) {
//            $contractId = $this->resolveContractIdForJournalable($line->journalable_type, (int) $line->journalable_id);
//            if ($contractId === null) {
//                continue;
//            }
//
//            $debit = $line->debit_account_id == $accountId ? (float) $line->amount_amd : 0.0;
//            $credit = $line->credit_account_id == $accountId ? (float) $line->amount_amd : 0.0;
//            $balances[$contractId] = ($balances[$contractId] ?? 0.0) + ($debit - $credit);
//        }
//
//        return $balances;
//    }
//
//    private function remainingDaysUntilDeadline(Carbon $reportTo, $deadline): int
//    {
//        $reportDay = $reportTo->copy()->startOfDay();
//        $deadlineDay = Carbon::parse($deadline)->startOfDay();
//
//        return (int) $reportDay->diffInDays($deadlineDay, false);
//    }
//
//    private function morphIsContract(?string $type): bool
//    {
//        return $type !== null && is_a($type, Contract::class, true);
//    }
//
//    private function morphIsDocumentJournal(?string $type): bool
//    {
//        return $type !== null && is_a($type, DocumentJournal::class, true);
//    }
//
//    /**
//     * Load every DocumentJournal row needed to walk journalable_id chains down to Contract.
//     */
//    private function prefetchJournalParentsForLines(Collection $lines): void
//    {
//        $pending = [];
//
//        foreach ($lines as $line) {
//            if ($this->morphIsDocumentJournal($line->journalable_type)) {
//                $pending[(int) $line->journalable_id] = true;
//            }
//        }
//
//        $guard = 0;
//        while ($pending !== [] && $guard++ < 500) {
//            $batch = array_keys(array_diff_key($pending, $this->v09JournalParentCache));
//            $pending = [];
//
//            if ($batch === []) {
//                break;
//            }
//
//            $rows = DocumentJournal::query()
//                ->whereIn('id', $batch)
//                ->get(['id', 'journalable_type', 'journalable_id']);
//
//            foreach ($rows as $row) {
//                $this->v09JournalParentCache[$row->id] = $row;
//                if ($this->morphIsDocumentJournal($row->journalable_type)) {
//                    $pending[(int) $row->journalable_id] = true;
//                }
//            }
//        }
//    }
//
//    private function resolveContractIdForJournalable(?string $type, int $id): ?int
//    {
//        if ($this->morphIsContract($type)) {
//            return $id;
//        }
//
//        if (!$this->morphIsDocumentJournal($type)) {
//            return null;
//        }
//
//        $currentId = $id;
//        for ($i = 0; $i < 200; $i++) {
//            if (!isset($this->v09JournalParentCache[$currentId])) {
//                return null;
//            }
//
//            $parent = $this->v09JournalParentCache[$currentId];
//            $pType = $parent->journalable_type;
//            $pId = (int) $parent->journalable_id;
//
//            if ($this->morphIsContract($pType)) {
//                return $pId;
//            }
//
//            if ($this->morphIsDocumentJournal($pType)) {
//                $currentId = $pId;
//                continue;
//            }
//
//            return null;
//        }
//
//        return null;
//    }
//
//    private function getColumnByDaysV09($days): string
//    {
//        if ($days <= 0) return 'E';
//        if ($days <= 30) return 'F';
//        if ($days <= 60) return 'G';
//        if ($days <= 90) return 'H';
//        if ($days <= 120) return 'I';
//        if ($days <= 150) return 'J';
//        if ($days <= 180) return 'K';
//        if ($days <= 366) return 'L';
//        if ($days <= 1096) return 'M';
//        return 'N';
//    }
//
//    private function getSpecificBalance($contractId, $docId, $accountId, $date, $type = 'active')
//    {
//        $accIds = is_array($accountId) ? $accountId : [$accountId];
//        $accIds = array_filter($accIds);
//
//        if (empty($accIds)) return 0;
//
//        $query = DocumentJournal::where(function ($q) use ($contractId, $docId) {
//            $q->where(function ($inner) use ($contractId) {
//                $inner->where('journalable_type', Contract::class)
//                    ->where('journalable_id', $contractId);
//            })->orWhere(function ($inner) use ($docId) {
//                $inner->where('journalable_type', DocumentJournal::class)
//                    ->where('journalable_id', $docId);
//            });
//        })->whereDate('date', '<=', $date);
//
//        $debit = (clone $query)->whereIn('debit_account_id', $accIds)->sum('amount_amd');
//        $credit = (clone $query)->whereIn('credit_account_id', $accIds)->sum('amount_amd');
//
//        return ($type === 'active') ? ($debit - $credit) : ($credit - $debit);
//    }
//
//    private function getAccountBalance($accountId, $date)
//    {
//        $debit = DocumentJournal::where('debit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
//        $credit = DocumentJournal::where('credit_account_id', $accountId)->whereDate('date', '<=', $date)->sum('amount_amd');
//        return $debit - $credit;
//    }
//
//    private function fixPColumnTotals(Worksheet $sheet): void
//    {
//        $highestRow = $sheet->getHighestRow();
//
//        for ($row = 1; $row <= $highestRow; $row++) {
//            $formula = $sheet->getCell('P' . $row)->getValue();
//
//            if (!is_string($formula) || strncmp($formula, '=', 1) !== 0) {
//                continue;
//            }
//
//            $correctFormula = preg_replace(
//                '/^=SUM\\(C' . $row . ':C' . $row . '\\)$/i',
//                '=SUM(C' . $row . ':O' . $row . ')',
//                $formula
//            );
//
//            if ($correctFormula !== null && $correctFormula !== $formula) {
//                $sheet->setCellValue('P' . $row, $correctFormula);
//            }
//        }
//    }
//}


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

        $query = DocumentJournal::where(function ($q) use ($contractId, $docId) {
            $q->where(function ($inner) use ($contractId) {
                $inner->where('journalable_type', Contract::class)
                    ->where('journalable_id', $contractId);
            })->orWhere(function ($inner) use ($docId) {
                $inner->where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $docId);
            });
        })->whereDate('date', '<=', $date);

        $debit = (clone $query)->where('debit_account_id', $accountId)->sum('amount_amd');
        $credit = (clone $query)->where('credit_account_id', $accountId)->sum('amount_amd');
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
