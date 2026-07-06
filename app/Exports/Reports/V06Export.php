<?php
//
//namespace App\Exports\Reports;
//
//use App\Models\ChartOfAccount;
//use App\Models\ClassificationHistory;
//use App\Models\Contract;
//use App\Models\DocumentJournal;
//use Carbon\Carbon;
//use Illuminate\Support\Facades\DB;
//use PhpOffice\PhpSpreadsheet\Cell\DataType;
//use PhpOffice\PhpSpreadsheet\IOFactory;
//use PhpOffice\PhpSpreadsheet\Writer\Xls;
//
//class V06Export
//{
//    public function export($from, $to)
//    {
//        $path = base_path('v06.xls');
//        $reader = IOFactory::createReader('Xls');
//        $spreadsheet = $reader->load($path);
//
//        $date = Carbon::parse($to)->format('Y-m-d');
//        $dateFrom = Carbon::parse($from)->format('Y-m-d');
//
//        $sheet = $spreadsheet->getSheetByName('Sheet1');
//
//        $docs = DocumentJournal::with(['journalable.payments' => function ($q) use ($date) {
//            // Load payments that were unpaid as of the report date:
//            // either still initial, or completed after the report date.
//            $q->where(function ($q2) use ($date) {
//                $q2->where('status', 'initial')
//                    ->orWhere(function ($q3) use ($date) {
//                        $q3->where('status', 'completed')
//                            ->whereDate('to_date', '>', $date);
//                    });
//            });
//        }])
//            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->whereDate('date', '<=', $date)
//            ->get();
//
//        $carContractCount = 0;
//        $goldContractCount = 0;
//        $category2ContractCount = 0;
//
//        $carContractAmount = 0;
//        $goldContractAmount = 0;
//        $category2ContractAmount = 0;
//        $expiredCarContractAmount = 0;
//        $expiredGoldContractAmount = 0;
//        $expiredCategory2ContractAmount = 0;
//        $onTimeContractAmount = 0;
//
//        $groupsOnTime = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
//        $groupsExpired = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
//        $groupsCar = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
//        $groupsGold = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
//        $groupsCategory2 = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
//
//        $classificationCounts = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
//        $amountsByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
//        $weightedByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
//        $reserveByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
//
//        $onTimeCount = 0;
//        $expiredCount = 0;
//        $acc16200NV = ChartOfAccount::idByCode('16200NV');
//        $acc16201NI = ChartOfAccount::idByCode('16201NI');
//        $acc16200   = ChartOfAccount::idByCode('16200');
//
//        // Use classification as of report end date (not clients.classification_id today).
//        $clientClassAsOf = $this->sheet1ClientClassificationsAsOf($date);
//
//        foreach ($docs as $doc) {
//            $contract = $doc->journalable;
//            if (!$contract || !$contract->client) {
//                continue;
//            }
//
//            $classification = $clientClassAsOf[$contract->client_id] ?? null;
//            if (!$classification) {
//                continue;
//            }
//            // Skip contracts that were already closed before the report date.
//            // A contract closed after $date was still active on the report snapshot.
//            if ($contract->closed_at && Carbon::parse($contract->closed_at)->lt($date)) continue;
//
//            $hasExpiredPayment = $contract->payments
//                ->contains(function ($p) use ($date) {
//                    return Carbon::parse($p->date)->lt($date);
//                });
//
//            if ($hasExpiredPayment) {
//                $expiredCount++;
//            } else {
//                $onTimeCount++;
//            }
//            // Bucket by report snapshot date, not contract creation timestamp.
//            $days = Carbon::parse($contract->deadline)
//                ->diffInDays(Carbon::parse($date));
//
//            $col = $this->getColumnByDays($days);
//
//            $name = $classification->name;
//            if (!isset($amountsByClassification[$name])) {
//                continue;
//            }
//
//            $classificationCounts[$name]++;
//            $net16200NVCredit = DocumentJournal::where(function ($query) use ($contract, $doc) {
//                $query->where(function ($q) use ($contract) {
//                    $q->where('journalable_type', Contract::class)
//                        ->where('journalable_id', $contract->id);
//                })
//                    ->orWhere(function ($q) use ($doc) {
//                        $q->where('journalable_type', DocumentJournal::class)
//                            ->where('journalable_id', $doc->id);
//                    });
//            })
//                ->where('credit_account_id', $acc16200NV)
//                ->whereDate('date', '<=', $date)
//                ->sum('amount_amd');
//            $net16200NV = $doc->amount_amd - $net16200NVCredit;
//
//
//            $net16201NI = DocumentJournal::where(function ($query) use ($contract, $doc) {
//                $query->where(function ($q) use ($contract) {
//                    $q->where('journalable_type', Contract::class)
//                        ->where('journalable_id', $contract->id);
//                })
//                    ->orWhere(function ($q) use ($doc) {
//                        $q->where('journalable_type', DocumentJournal::class)
//                            ->where('journalable_id', $doc->id);
//                    });
//            })
//                ->whereDate('date', '<=', $date)
//                ->selectRaw("SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) -
//                 SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance",
//                    [$acc16201NI, $acc16201NI])
//                ->value('balance');
//
//            $net16200 = 0;
//            if ($acc16200) {
//                $net16200 = DocumentJournal::where(function ($query) use ($contract, $doc) {
//                    $query->where(function ($q) use ($contract) {
//                        $q->where('journalable_type', Contract::class)
//                            ->where('journalable_id', $contract->id);
//                    })
//                        ->orWhere(function ($q) use ($doc) {
//                            $q->where('journalable_type', DocumentJournal::class)
//                                ->where('journalable_id', $doc->id);
//                        });
//                })
//                    ->whereDate('date', '<=', $date)
//                    ->selectRaw("SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) -
//                     SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance",
//                        [$acc16200, $acc16200])
//                    ->value('balance');
//            }
//
//            $amount = $net16200NV + $net16201NI + $net16200;
//            $amountsByClassification[$name] +=  $amount;
//            if ($contract->category){
//                if (in_array($contract->category->name, ['car', 'car-purchase'])) {
//                    $carContractAmount += $amount ;
//                    $groupsCar[$col] += $net16200NV;
//                    $carContractCount++;
//                    if ($hasExpiredPayment) {
//                        $expiredCarContractAmount += $amount;
//                    }
//                }
//                if ($contract->category->name === 'gold') {
//                    $goldContractAmount += $amount ;
//                    $groupsGold[$col] += $net16200NV;
//                    $goldContractCount++;
//                    if ($hasExpiredPayment) {
//                        $expiredGoldContractAmount += $amount;
//                    }
//                }
//            }
//            if ((int) $contract->category_id === 2) {
//                $category2ContractCount++;
//                $category2ContractAmount += $amount;
//                $groupsCategory2[$col] += $net16200NV;
//                if ($hasExpiredPayment) {
//                    $expiredCategory2ContractAmount += $amount;
//                }
//            }
//            if ($hasExpiredPayment) {
//                $groupsExpired[$col] += $net16200NV;
//            } else {
//                $groupsOnTime[$col] += $net16200NV;
//                $onTimeContractAmount += $amount;
//            }
//
//            $interest = DocumentJournal::where('journalable_id', $doc->id)
//                ->whereIn('document_type', [DocumentJournal::INTEREST_RATE_AMOUNT, DocumentJournal::EFFECTIVE_RATE_AMOUNT])
//                ->where('date', '<', $date)
//                ->sum('amount_amd');
//
//            $weightedByClassification[$name] += $interest;
//            $reserve_percent = $classification->reserve_percent ?? 0;
//            $reserveByClassification[$name] += $amount * $reserve_percent / 100;
//        }
//
//        $rowsOnTime = [15, 16];
//        foreach ($rowsOnTime as $row) {
//            foreach ($groupsOnTime as $col => $value) {
//                $sheet->setCellValue($col . $row, $value/1000);
//                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
//            }
//        }
//        $sheet->setCellValue('P15', $onTimeContractAmount / 1000);
//        $sheet->getStyle('P15')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('P16', $onTimeContractAmount / 1000);
//        $sheet->getStyle('P16')->getNumberFormat()->setFormatCode('#,##0');
//        $rowsExpired = [21, 22];
//        foreach ($rowsExpired as $row) {
//            foreach ($groupsExpired as $col => $value) {
//                $sheet->setCellValue($col . $row, $value/1000);
//                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
//            }
//        }
//        $sheet->setCellValue('P21', ($expiredCarContractAmount + $expiredGoldContractAmount + $expiredCategory2ContractAmount) / 1000);
//        $sheet->getStyle('P21')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('P22', ($expiredCarContractAmount + $expiredGoldContractAmount + $expiredCategory2ContractAmount) / 1000);
//        $sheet->getStyle('P22')->getNumberFormat()->setFormatCode('#,##0');
//
//        $sheet->setCellValue('R15', $onTimeCount);
//        $sheet->setCellValue('R16', $onTimeCount);
//        $sheet->setCellValue('R21', $expiredCount);
//        $sheet->setCellValue('R22', $expiredCount);
//
//        $rowsCar = [110];
//        $rowsTotal = [108];
//
//        foreach ($groupsCar as $col => $value) {
//            foreach ($rowsCar as $row) {
//                $sheet->setCellValue($col . $row, $value / 1000);
//                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
//            }
//        }
//
//        foreach ($groupsGold as $col => $value) {
//            $sheet->setCellValue($col . '112', $value / 1000);
//            $sheet->getStyle($col . '112')->getNumberFormat()->setFormatCode('#,##0');
//        }
//        foreach ($groupsCategory2 as $col => $value) {
//            $sheet->setCellValue($col . '113', $value / 1000);
//            $sheet->getStyle($col . '113')->getNumberFormat()->setFormatCode('#,##0');
//        }
//
//        foreach ($groupsCar as $col => $value) {
//            $goldValue = $groupsGold[$col] ?? 0;
//            $category2Value = $groupsCategory2[$col] ?? 0;
//            foreach ($rowsTotal as $row) {
//                $sheet->setCellValue($col . $row, ($value + $goldValue + $category2Value)/1000);
//                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
//            }
//        }
//        $carContractAmount = $carContractAmount/1000;
//        $goldContractAmount = $goldContractAmount/1000;
//        $category2Thousands = $category2ContractAmount / 1000;
//        $sheet->setCellValue('P108', $carContractAmount + $goldContractAmount + $category2Thousands);
//        $sheet->getStyle('P108')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('P109',0);
//        $sheet->getStyle('P109')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('P110',$carContractAmount);
//        $sheet->getStyle('P110')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('P111', 0);
//        $sheet->getStyle('P111')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('P112', $goldContractAmount);
//        $sheet->getStyle('P112')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('P113', $category2Thousands);
//        $sheet->getStyle('P113')->getNumberFormat()->setFormatCode('#,##0');
//
//        $sheet->setCellValue('R108', $carContractCount + $goldContractCount + $category2ContractCount);
//        $sheet->getStyle('R108')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('R109',0);
//        $sheet->getStyle('R109')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('R110',$carContractCount);
//        $sheet->getStyle('R110')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('R111', 0);
//        $sheet->getStyle('R111')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('R112', $goldContractCount);
//        $sheet->getStyle('R112')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('R113', $category2ContractCount);
//        $sheet->getStyle('R113')->getNumberFormat()->setFormatCode('#,##0');
//
//        $rows = [125, 126, 127, 128, 129];
//        $classificationKeys = ['standard', 'monitored', 'substandard', 'suspicious', 'loss'];
//        foreach ($rows as $index => $row) {
//            $key = $classificationKeys[$index];
//            $amountByClass = ($amountsByClassification[$key] ?? 0);
//            $reserveByClass = ($reserveByClassification[$key] ?? 0);
//
//            // Business rule: monitored reserve must be exactly 10% of monitored amount (D126).
//            if ($key === 'monitored') {
//                $reserveByClass = $amountByClass * 0.10;
//            }
//
//            $sheet->setCellValue('B' . $row, ($classificationCounts[$key] ?? 0));
//            $sheet->setCellValue('D' . $row, $amountByClass / 1000);
//            $sheet->setCellValue('F' . $row, $reserveByClass / 1000);
//            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0');
//            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
//            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0');
//            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
//        }
//
//        $acc10210Ids = ChartOfAccount::where('code', 'like', '10210%')->pluck('id');
//
//        $accCount = $acc10210Ids->count();
//        $balance10210 = 0;
//
//        if ($accCount > 0) {
//            $balance10210 = DocumentJournal::whereIn('debit_account_id', $acc10210Ids)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd')
//                - DocumentJournal::whereIn('credit_account_id', $acc10210Ids)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd');
//        }
//
//        $sheet->setCellValue('J125', $accCount);
//        $sheet->getStyle('J125')->getNumberFormat()->setFormatCode('#,##0');
//
//        $sheet->setCellValue('L125', $balance10210 / 1000);
//        $sheet->getStyle('L125')->getNumberFormat()->setFormatCode('#,##0');
//        $acc15300Ids = ChartOfAccount::where('code', 'like', '15300%')->pluck('id');
//
//        $balance15300 = 0;
//        if ($acc15300Ids) {
//            $balance15300 =  DocumentJournal::whereIn('credit_account_id', $acc15300Ids)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd')
//                - DocumentJournal::whereIn('debit_account_id', $acc15300Ids)
//                    ->whereDate('date', '<=', $date)
//                    ->sum('amount_amd');
//
//        }
//        $sheet->setCellValue('N125', $balance15300 / 1000);
//        $sheet->getStyle('N125')->getNumberFormat()->setFormatCode('#,##0');
//
//        $acc19331 = ChartOfAccount::idByCode('19331');
//        $balance19331 = 0;
//        $debitPartnersCount = 0;
//        if ($acc19331) {
//            $balance19331 = DocumentJournal::where('debit_account_id', $acc19331)
//                ->whereDate('date', '<=', $date)
//                ->sum('amount_amd');
//            $debitPartnersCount = DocumentJournal::where('debit_account_id', $acc19331)
//                ->whereDate('date', '<=', $date)
//                ->distinct('debit_partner_id')
//                ->count('debit_partner_id');
//        }
//        $sheet->setCellValue('R125', $debitPartnersCount);
//        $sheet->getStyle('R125')->getNumberFormat()->setFormatCode('#,##0');
//        $sheet->setCellValue('T125', $balance19331 / 1000);
//        $sheet->getStyle('T125')->getNumberFormat()->setFormatCode('#,##0');
//
//        $acc19400PC = ChartOfAccount::idByCode('19400PC');
//        $balance19400PC = 0;
//        if ($acc19400PC) {
//            $balance19400PC = DocumentJournal::where('debit_account_id', $acc19400PC)
//                ->whereDate('date', '<=', $date)
//                ->sum('amount_amd');
//        }
//        $sheet->setCellValue('X125', $balance19400PC / 1000);
//        $sheet->getStyle('X125')->getNumberFormat()->setFormatCode('#,##0');
//
//        $sheet->setCellValueExplicit('D5', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
//        $sheet->setCellValue('D7', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($from));
//        $sheet->getStyle('D7')->getNumberFormat()->setFormatCode('dd/mm/yy');
//
//        $sheet->setCellValue('F7', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($to));
//        $sheet->getStyle('F7')->getNumberFormat()->setFormatCode('dd/mm/yy');
//
//        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
//        $rowCar = 89;
//        $rowGold = 91;
//        $rowCategory2 = 92;
//
//        // Sheet2 column B: snapshot as of day before report `from`, using effective class on that snapshot date.
//        $sheet2SnapshotDate = Carbon::parse($from)->subDay()->format('Y-m-d');
//        $sheet2ClientClassById = $this->sheet2ClientClassificationsAsOf($sheet2SnapshotDate);
//
//        $goldAmountBefore = $this->sumSheet2ColumnBNetByCategory('gold', $sheet2SnapshotDate, $sheet2ClientClassById);
//        $carAmountBefore = $this->sumSheet2ColumnBNetByCategory('car', $sheet2SnapshotDate, $sheet2ClientClassById);
//        $category2AmountBefore = $this->sumSheet2ColumnBNetByCategory('category2', $sheet2SnapshotDate, $sheet2ClientClassById);
//
//        $sheet2ReportDate = Carbon::parse($to)->format('Y-m-d');
//        // Sheet2 column D: period change (not closing balance) for clients in class 3/4/5 at report end.
//        $sheet2ClientClassByIdTo = $this->sheet2ClientClassificationsAsOf($sheet2ReportDate);
//
//        $goldPeriodChange = $this->sumSheet2ColumnDPeriodChangeByCategory(
//            'gold',
//            $dateFrom,
//            $date,
//            $sheet2ClientClassByIdTo
//        );
//        $carPeriodChange = $this->sumSheet2ColumnDPeriodChangeByCategory(
//            'car',
//            $dateFrom,
//            $date,
//            $sheet2ClientClassByIdTo
//        );
//        $category2PeriodChange = $this->sumSheet2ColumnDPeriodChangeByCategory(
//            'category2',
//            $dateFrom,
//            $date,
//            $sheet2ClientClassByIdTo
//        );
//        $totalPeriodChange = $carPeriodChange + $goldPeriodChange + $category2PeriodChange;
//
//        $sheet2->setCellValue("B{$rowCar}", $carAmountBefore /1000);
//        $sheet2->setCellValue("B{$rowGold}", $goldAmountBefore/1000);
//        $sheet2->setCellValue("B{$rowCategory2}", $category2AmountBefore / 1000);
//        $sheet2->setCellValue("B87", ($carAmountBefore + $goldAmountBefore + $category2AmountBefore) / 1000);
//
//        foreach ([6, 7, 87] as $summaryRow) {
//            $sheet2->setCellValue("D{$summaryRow}", $totalPeriodChange / 1000);
//            $sheet2->getStyle("D{$summaryRow}")->getNumberFormat()->setFormatCode('#,##0');
//        }
//        $sheet2->setCellValue("D{$rowCar}", $carPeriodChange / 1000);
//        $sheet2->setCellValue("D{$rowGold}", $goldPeriodChange / 1000);
//        $sheet2->setCellValue("D{$rowCategory2}", $category2PeriodChange / 1000);
//        foreach (["D{$rowCar}", "D{$rowGold}", "D{$rowCategory2}"] as $cell) {
//            $sheet2->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0');
//        }
//
//        $acc86000 = ChartOfAccount::idByCode('86000');
//        $acc860001 = ChartOfAccount::idByCode('86001') ?: ChartOfAccount::idByCode('860001');
//
//        // Column H is opening balance at period start (before date_from).
//        $balance86000 = $this->balanceAccountBefore($acc86000, $dateFrom);
//        $balance860001 = $this->balanceAccountBefore($acc860001, $dateFrom);
//
//        $sheet2->setCellValue("H89", ($balance86000 + $balance860001) / 1000);
//        $sheet2->setCellValue("H91", 0);
//        $sheet2->setCellValue("H87", ($balance86000 + $balance860001) / 1000);
//
//        // Column J: 86000 debits, off-balance incoming document types (incl. legacy spellings in DB).
//        $offBalanceIncomingTypes = $this->sheet2OffBalanceIncomingDocumentTypes();
//        $debitCar860 = $this->sumSheet286000TurnoverByCategory(
//            'car',
//            'debit_account_id',
//            $dateFrom,
//            $date,
//            [$acc86000],
//            $offBalanceIncomingTypes
//        );
//        $debitGold860 = $this->sumSheet286000TurnoverByCategory(
//            'gold',
//            'debit_account_id',
//            $dateFrom,
//            $date,
//            [$acc86000],
//            $offBalanceIncomingTypes
//        );
//        $debitCategory2860 = $this->sumSheet286000TurnoverByCategory(
//            'category2',
//            'debit_account_id',
//            $dateFrom,
//            $date,
//            [$acc86000],
//            $offBalanceIncomingTypes
//        );
//
//        // Column P: 86000+86001 debits for loss penalty / nominal / effective interest document types.
//        $lossDocTypes = [
//            DocumentJournal::LOSS_PENALTY,
//            DocumentJournal::LOSS_INTEREST_NOMINAL,
//            DocumentJournal::LOSS_INTEREST_EFFECTIVE,
//        ];
//        $lossCar860 = $this->sumSheet286000TurnoverByCategory(
//            'car',
//            'debit_account_id',
//            $dateFrom,
//            $date,
//            array_values(array_filter([$acc86000, $acc860001])),
//            $lossDocTypes
//        );
//        $lossGold860 = $this->sumSheet286000TurnoverByCategory(
//            'gold',
//            'debit_account_id',
//            $dateFrom,
//            $date,
//            array_values(array_filter([$acc86000, $acc860001])),
//            $lossDocTypes
//        );
//        $lossCategory2860 = $this->sumSheet286000TurnoverByCategory(
//            'category2',
//            'debit_account_id',
//            $dateFrom,
//            $date,
//            array_values(array_filter([$acc86000, $acc860001])),
//            $lossDocTypes
//        );
//
//        // Column L: 86000+86001 credits in period (all document types), same category split.
//        $creditCar860 = $this->sumSheet286000TurnoverByCategory(
//            'car',
//            'credit_account_id',
//            $dateFrom,
//            $date,
//            array_values(array_filter([$acc86000, $acc860001])),
//            null
//        );
//        $creditGold860 = $this->sumSheet286000TurnoverByCategory(
//            'gold',
//            'credit_account_id',
//            $dateFrom,
//            $date,
//            array_values(array_filter([$acc86000, $acc860001])),
//            null
//        );
//        $creditCategory2860 = $this->sumSheet286000TurnoverByCategory(
//            'category2',
//            'credit_account_id',
//            $dateFrom,
//            $date,
//            array_values(array_filter([$acc86000, $acc860001])),
//            null
//        );
//
//        $sheet2->setCellValue("J{$rowCar}", $debitCar860 / 1000);
//        $sheet2->setCellValue("J{$rowGold}", $debitGold860 / 1000);
//        $sheet2->setCellValue("J{$rowCategory2}", $debitCategory2860 / 1000);
//        $sheet2->setCellValue('J87', ($debitCar860 + $debitGold860 + $debitCategory2860) / 1000);
//
//        $sheet2->setCellValue("L{$rowCar}", $creditCar860 / 1000);
//        $sheet2->setCellValue("L{$rowGold}", $creditGold860 / 1000);
//        $sheet2->setCellValue("L{$rowCategory2}", $creditCategory2860 / 1000);
//        $sheet2->setCellValue('L87', ($creditCar860 + $creditGold860 + $creditCategory2860) / 1000);
//
//        $sheet2->setCellValue("P{$rowCar}", $lossCar860 / 1000);
//        $sheet2->setCellValue("P{$rowGold}", $lossGold860 / 1000);
//        $sheet2->setCellValue("P{$rowCategory2}", $lossCategory2860 / 1000);
//        $sheet2->setCellValue('P87', ($lossCar860 + $lossGold860 + $lossCategory2860) / 1000);
//
//        foreach ([
//            'J87', "J{$rowCar}", "J{$rowGold}", "J{$rowCategory2}",
//            'L87', "L{$rowCar}", "L{$rowGold}", "L{$rowCategory2}",
//            'P87', "P{$rowCar}", "P{$rowGold}", "P{$rowCategory2}",
//        ] as $cell) {
//            $sheet2->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0');
//        }
//
//        // Column R: left to Excel template formulas (H+J−L per row).
//
//        $fileName = 'v06_export_' . now()->format('Ymd_His') . '.xls';
//        $savePath = storage_path('app/public/' . $fileName);
//
//        $writer = new Xls($spreadsheet);
//        $writer->save($savePath);
//
//        return $savePath;
//    }
//
//
//    /**
//     * Sheet1 rows 125–130: effective class per client on $snapshotDate (report `to` date).
//     * Same effective-date rule as CorrectAllClientReservesJob / Sheet2 — not clients.classification_id.
//     *
//     * @return array<int, object{name: string, reserve_percent: float}> client_id => snapshot class
//     */
//    public function sheet1ClientClassificationsAsOf(string $snapshotDate): array
//    {
//        $maxDates = DB::table('classification_histories')
//            ->select('client_id', DB::raw('MAX(`date`) as max_date'))
//            ->whereDate('date', '<=', $snapshotDate)
//            ->whereNull('deleted_at')
//            ->groupBy('client_id');
//
//        $latestRowIds = DB::table('classification_histories as ch')
//            ->joinSub($maxDates, 'md', function ($join) {
//                $join->on('ch.client_id', '=', 'md.client_id')
//                    ->on('ch.date', '=', 'md.max_date');
//            })
//            ->whereNull('ch.deleted_at')
//            ->groupBy('ch.client_id')
//            ->select('ch.client_id', DB::raw('MAX(ch.id) as latest_id'));
//
//        $rows = DB::table('classification_histories as ch')
//            ->joinSub($latestRowIds, 'lr', function ($join) {
//                $join->on('ch.id', '=', 'lr.latest_id');
//            })
//            ->join('clients_classification as cc', 'cc.id', '=', 'ch.classification_id')
//            ->select([
//                'ch.client_id',
//                'cc.name as classification_name',
//                DB::raw('COALESCE(ch.reserve_percent, cc.reserve_percent) as reserve_percent'),
//            ])
//            ->get();
//
//        $out = [];
//        foreach ($rows as $row) {
//            $out[(int) $row->client_id] = (object) [
//                'name' => (string) $row->classification_name,
//                'reserve_percent' => (float) $row->reserve_percent,
//            ];
//        }
//
//        return $out;
//    }
//
//    /**
//     * Sheet2 column B: map client_id => effective classification_id (3, 4, or 5) on $snapshotDate.
//     * Effective class = the most recent classification_histories row with date on or before that day
//     * (e.g. …→4→1 means class 1 on snapshot — client is omitted here because latest is not 3/4/5).
//     *
//     * @return array<int, int> client_id => classification_id
//     */
//    private function sheet2ClientClassificationsAsOf(string $snapshotDate): array
//    {
//        $maxDates = DB::table('classification_histories')
//            ->select('client_id', DB::raw('MAX(`date`) as max_date'))
//            ->whereDate('date', '<=', $snapshotDate)
//            ->whereNull('deleted_at')
//            ->groupBy('client_id');
//
//        $latestRowIds = DB::table('classification_histories as ch')
//            ->joinSub($maxDates, 'md', function ($join) {
//                $join->on('ch.client_id', '=', 'md.client_id')
//                    ->on('ch.date', '=', 'md.max_date');
//            })
//            ->whereNull('ch.deleted_at')
//            ->groupBy('ch.client_id')
//            ->select('ch.client_id', DB::raw('MAX(ch.id) as latest_id'));
//
//        return DB::table('classification_histories as ch')
//            ->joinSub($latestRowIds, 'lr', function ($join) {
//                $join->on('ch.id', '=', 'lr.latest_id');
//            })
//            ->whereIn('ch.classification_id', [3, 4, 5])
//            ->pluck('ch.classification_id', 'ch.client_id')
//            ->all();
//    }
//
//    /**
//     * Sheet2 column D: map client_id => last classification_id (3/4/5) inside report period [$dateFrom, $dateTo].
//     *
//     * @return array<int, int> client_id => classification_id
//     */
//    private function sheet2ClientClassificationsBetween(string $dateFrom, string $dateTo): array
//    {
//        $maxDates = DB::table('classification_histories')
//            ->select('client_id', DB::raw('MAX(`date`) as max_date'))
//            ->whereBetween(DB::raw('DATE(`date`)'), [$dateFrom, $dateTo])
//            ->whereNull('deleted_at')
//            ->groupBy('client_id');
//
//        $latestRowIds = DB::table('classification_histories as ch')
//            ->joinSub($maxDates, 'md', function ($join) {
//                $join->on('ch.client_id', '=', 'md.client_id')
//                    ->on('ch.date', '=', 'md.max_date');
//            })
//            ->whereNull('ch.deleted_at')
//            ->groupBy('ch.client_id')
//            ->select('ch.client_id', DB::raw('MAX(ch.id) as latest_id'));
//
//        return DB::table('classification_histories as ch')
//            ->joinSub($latestRowIds, 'lr', function ($join) {
//                $join->on('ch.id', '=', 'lr.latest_id');
//            })
//            ->whereIn('ch.classification_id', [3, 4, 5])
//            ->pluck('ch.classification_id', 'ch.client_id')
//            ->all();
//    }
//
//    /**
//     * Calendar date of the most recent classification_histories row (on or before $snapshotDate) where the
//     * client was set to $classificationId (journal nets run through the day before this date).
//     */
//    private function lastClientClassificationAssignmentDate(int $clientId, int $classificationId, string $snapshotDate): ?string
//    {
//        $d = ClassificationHistory::query()
//            ->where('client_id', $clientId)
//            ->where('classification_id', $classificationId)
//            ->whereNull('deleted_at')
//            ->whereDate('date', '<=', $snapshotDate)
//            ->orderByDesc('date')
//            ->orderByDesc('id')
//            ->value('date');
//
//        return $d ? Carbon::parse($d)->format('Y-m-d') : null;
//    }
//
//    /**
//     * Journal as-of date for Sheet2 column B: day before the last time (on or before snapshot) the client
//     * was assigned their current effective class (3, 4, or 5).
//     */
//    private function sheet2JournalAsOfDate(int $classificationId, string $snapshotDate, int $clientId): string
//    {
//        if (!in_array($classificationId, [3, 4, 5], true)) {
//            return $snapshotDate;
//        }
//
//        $assigned = $this->lastClientClassificationAssignmentDate($clientId, $classificationId, $snapshotDate);
//        if (!$assigned) {
//            return $snapshotDate;
//        }
//
//        return Carbon::parse($assigned)->format('Y-m-d');
//    }
//
//    /**
//     * Sheet2 column B: by car/gold/category_id=2, journals through the day before the last assignment to effective class 3/4/5.
//     * Class 3–4: net162 (16200NV + 16201NI + 16200). Class 5: net86000 + net86001.
//     */
//    private function sumSheet2ColumnBNetByCategory(string $categoryName, string $snapshotDate, array $clientIdToClass): float
//    {
//        if ($clientIdToClass === []) {
//            return 0.0;
//        }
//
//        $clientIds = array_keys($clientIdToClass);
//
//        $contracts = Contract::query()
//            ->with('category')
//            ->whereIn('client_id', $clientIds)
//            ->where('status', 'initial')
//            ->whereDate('date', '<=', $snapshotDate)
//            ->where(function ($q) use ($snapshotDate) {
//                $q->whereNull('closed_at')
//                    ->orWhereDate('closed_at', '>=', $snapshotDate);
//            })
//            ->when($categoryName === 'category2', function ($q) {
//                $q->where('category_id', 2);
//            }, function ($q) use ($categoryName) {
//                $q->whereHas('category', function ($q2) use ($categoryName) {
//                    if ($categoryName === 'car') {
//                        $q2->whereIn('name', ['car', 'car-purchase']);
//                    } else {
//                        $q2->where('name', 'gold');
//                    }
//                });
//            })
//            ->get();
//
//        if ($contracts->isEmpty()) {
//            return 0.0;
//        }
//
//        $provideByContractId = DocumentJournal::query()
//            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->where('journalable_type', Contract::class)
//            ->whereIn('journalable_id', $contracts->pluck('id'))
//            ->get()
//            ->keyBy('journalable_id');
//
//        $acc16200NV = ChartOfAccount::idByCode('16200NV');
//        $acc16201NI = ChartOfAccount::idByCode('16201NI');
//        $acc16200 = ChartOfAccount::idByCode('16200');
//        $acc86000 = ChartOfAccount::idByCode('86000');
//        $acc86001 = ChartOfAccount::idByCode('86001');
//
//        $sum = 0.0;
//        foreach ($contracts as $contract) {
//            $doc = $provideByContractId->get($contract->id);
//            if (!$doc) {
//                continue;
//            }
//            $classId = (int) ($clientIdToClass[$contract->client_id] ?? 0);
//            if (!in_array($classId, [3, 4, 5], true)) {
//                continue;
//            }
//            $asOf = $snapshotDate;
//            if (Carbon::parse($contract->date)->format('Y-m-d') > $asOf) {
//                continue;
//            }
//            if ($classId === 5) {
//                $sum += $this->contractNet86000Plus86001AtDate($doc, $contract, $asOf, $acc86000, $acc86001);
//            } elseif (in_array($classId, [3, 4], true)) {
//                $sum += $this->contractNet162AtDate($doc, $contract, $asOf, $acc16200NV, $acc16201NI, $acc16200);
//            }
//        }
//
//        return (float) $sum;
//    }
//
//    /**
//     * Sheet2 column D: net portfolio change during [$dateFrom, $dateTo] for clients in class 3/4/5 at report end.
//     * Change = balance at $dateTo minus opening balance for that row (same as column B at period start).
//     * Clients not yet in class 3/4/5 on the day before $dateFrom contribute 0 opening — not their pre-downgrade balance.
//     */
//    private function sumSheet2ColumnDPeriodChangeByCategory(
//        string $categoryName,
//        string $dateFrom,
//        string $dateTo,
//        array $clientIdToClassAtTo
//    ): float {
//        if ($clientIdToClassAtTo === []) {
//            return 0.0;
//        }
//
//        $openSnapshotDate = Carbon::parse($dateFrom)->subDay()->format('Y-m-d');
//        $contracts = $this->sheet2ContractsForCategory($categoryName, array_keys($clientIdToClassAtTo), $dateTo);
//
//        if ($contracts->isEmpty()) {
//            return 0.0;
//        }
//
//        $provideByContractId = DocumentJournal::query()
//            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->where('journalable_type', Contract::class)
//            ->whereIn('journalable_id', $contracts->pluck('id'))
//            ->get()
//            ->keyBy('journalable_id');
//
//        $change = 0.0;
//        foreach ($contracts as $contract) {
//            $doc = $provideByContractId->get($contract->id);
//            if (!$doc) {
//                continue;
//            }
//
//            $classAtTo = (int) ($clientIdToClassAtTo[$contract->client_id] ?? 0);
//            if (!in_array($classAtTo, [3, 4, 5], true)) {
//                continue;
//            }
//
//            if (Carbon::parse($contract->date)->format('Y-m-d') > $dateTo) {
//                continue;
//            }
//
//            $classAtOpen = $this->clientClassificationIdAsOf((int) $contract->client_id, $openSnapshotDate);
//
//            // Opening for D matches column B: only clients already in 3/4/5 at period start have a non-zero base.
//            $openNet = 0.0;
//            if (in_array($classAtOpen, [3, 4, 5], true)) {
//                $openNet = $this->contractPortfolioNetAtDate(
//                    $doc,
//                    $contract,
//                    $openSnapshotDate,
//                    (int) $classAtOpen
//                );
//            }
//
//            $closeNet = $this->contractPortfolioNetAtDate($doc, $contract, $dateTo, $classAtTo);
//            $change += $closeNet - $openNet;
//        }
//
//        return (float) $change;
//    }
//
//    /**
//     * Effective classification_id for a client on or before $snapshotDate.
//     */
//    private function clientClassificationIdAsOf(int $clientId, string $snapshotDate): ?int
//    {
//        $row = ClassificationHistory::query()
//            ->where('client_id', $clientId)
//            ->whereNull('deleted_at')
//            ->whereDate('date', '<=', $snapshotDate)
//            ->orderByDesc('date')
//            ->orderByDesc('id')
//            ->first();
//
//        return $row ? (int) $row->classification_id : null;
//    }
//
//    /**
//     * Active contracts for Sheet2 car / gold / category2 and given client ids.
//     */
//    private function sheet2ContractsForCategory(string $categoryName, array $clientIds, string $asOfDate)
//    {
//        return Contract::query()
//            ->with('category')
//            ->whereIn('client_id', $clientIds)
//            ->where('status', 'initial')
//            ->whereDate('date', '<=', $asOfDate)
//            ->where(function ($q) use ($asOfDate) {
//                $q->whereNull('closed_at')
//                    ->orWhereDate('closed_at', '>=', $asOfDate);
//            })
//            ->when($categoryName === 'category2', function ($q) {
//                $q->where('category_id', 2);
//            }, function ($q) use ($categoryName) {
//                $q->whereHas('category', function ($q2) use ($categoryName) {
//                    if ($categoryName === 'car') {
//                        $q2->whereIn('name', ['car', 'car-purchase']);
//                    } else {
//                        $q2->where('name', 'gold');
//                    }
//                });
//            })
//            ->get();
//    }
//
//    /**
//     * Portfolio net at a date: class 5 → 86000+86001; otherwise 16200NV+16201NI+16200 (partner-aware scope).
//     */
//    private function contractPortfolioNetAtDate(
//        DocumentJournal $provideDoc,
//        Contract $contract,
//        string $asOfDate,
//        int $classificationId
//    ): float {
//        $acc16200NV = ChartOfAccount::idByCode('16200NV');
//        $acc16201NI = ChartOfAccount::idByCode('16201NI');
//        $acc16200 = ChartOfAccount::idByCode('16200');
//        $acc86000 = ChartOfAccount::idByCode('86000');
//        $acc86001 = ChartOfAccount::idByCode('86001');
//
//        if ($classificationId === 5) {
//            return $this->contractNet86000Plus86001AtDate(
//                $provideDoc,
//                $contract,
//                $asOfDate,
//                $acc86000,
//                $acc86001
//            );
//        }
//
//        return $this->contractNet162AtDate(
//            $provideDoc,
//            $contract,
//            $asOfDate,
//            $acc16200NV,
//            $acc16201NI,
//            $acc16200
//        );
//    }
//
//    /**
//     * Scope portfolio journals to a contract: journalable link, contract_id, or debit/credit partner.
//     * When a partner is set on a row, it must match the contract client (fixes mis-linked journalable_id).
//     */
//    private function applyPortfolioJournalScope(
//        $query,
//        Contract $contract,
//        DocumentJournal $provideDoc,
//        array $portfolioAccountIds
//    ): void {
//        $clientId = (int) $contract->client_id;
//        $contractId = (int) $contract->id;
//        $provideDocId = (int) $provideDoc->id;
//        $accountIds = array_values(array_filter($portfolioAccountIds));
//
//        $query->where(function ($outer) use ($clientId, $contractId, $provideDocId, $accountIds) {
//            $outer->where('contract_id', $contractId)
//                ->orWhere(function ($partner) use ($clientId, $contractId, $accountIds) {
//                    $partner->where(function ($p) use ($clientId) {
//                        $p->where('debit_partner_id', $clientId)
//                            ->orWhere('credit_partner_id', $clientId);
//                    });
//                    if ($accountIds !== []) {
//                        $partner->where(function ($a) use ($accountIds) {
//                            $a->whereIn('debit_account_id', $accountIds)
//                                ->orWhereIn('credit_account_id', $accountIds);
//                        });
//                    }
//                    $partner->where(function ($c) use ($contractId) {
//                        $c->whereNull('contract_id')
//                            ->orWhere('contract_id', $contractId);
//                    });
//                })
//                ->orWhere(function ($journalable) use ($clientId, $contractId, $provideDocId) {
//                    $journalable->where(function ($q) use ($contractId, $provideDocId) {
//                        $q->where(function ($q2) use ($contractId) {
//                            $q2->where('journalable_type', Contract::class)
//                                ->where('journalable_id', $contractId);
//                        })
//                            ->orWhere(function ($q2) use ($provideDocId) {
//                                $q2->where('journalable_type', DocumentJournal::class)
//                                    ->where('journalable_id', $provideDocId);
//                            });
//                    })
//                        ->where(function ($q) use ($clientId) {
//                            $q->where(function ($q2) {
//                                $q2->whereNull('debit_partner_id')
//                                    ->whereNull('credit_partner_id');
//                            })
//                                ->orWhere('debit_partner_id', $clientId)
//                                ->orWhere('credit_partner_id', $clientId);
//                        })
//                        ->where(function ($c) use ($contractId) {
//                            $c->whereNull('contract_id')
//                                ->orWhere('contract_id', $contractId);
//                        });
//                });
//        });
//    }
//
//    /**
//     * Net162 for Sheet2 (classification ids 3–4): net16200NV + net16201NI + net16200 through $asOfDate (same rules as Sheet1).
//     */
//    private function contractNet162AtDate(
//        DocumentJournal $doc,
//        Contract $contract,
//        string $asOfDate,
//        $acc16200NV,
//        $acc16201NI,
//        $acc16200
//    ): float {
//        $portfolioAccounts = array_filter([$acc16200NV, $acc16201NI, $acc16200]);
//
//        $net16200NVCredit = DocumentJournal::query()
//            ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
//                $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
//            })
//            ->where('credit_account_id', $acc16200NV)
//            ->whereDate('date', '<=', $asOfDate)
//            ->sum('amount_amd');
//        $net16200NV = (float) $doc->amount_amd - (float) $net16200NVCredit;
//
//        $net16201NI = (float) (DocumentJournal::query()
//            ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
//                $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
//            })
//            ->whereDate('date', '<=', $asOfDate)
//            ->selectRaw(
//                'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
//                [$acc16201NI, $acc16201NI]
//            )
//            ->value('balance') ?? 0);
//
//        $net16200 = 0.0;
//        if ($acc16200) {
//            $net16200 = (float) (DocumentJournal::query()
//                ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
//                    $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
//                })
//                ->whereDate('date', '<=', $asOfDate)
//                ->selectRaw(
//                    'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
//                    [$acc16200, $acc16200]
//                )
//                ->value('balance') ?? 0);
//        }
//
//        return $net16200NV + $net16201NI + $net16200;
//    }
//
//    /**
//     * Sheet2 column B for classification 5 (loss): loss expense nets debit − credit on 86000 + 86001
//     * (partner-aware journal scope).
//     */
//    private function contractNet86000Plus86001AtDate(
//        DocumentJournal $doc,
//        Contract $contract,
//        string $asOfDate,
//        $acc86000,
//        $acc86001
//    ): float {
//        $portfolioAccounts = array_filter([$acc86000, $acc86001]);
//
//        $net86000 = 0.0;
//        if ($acc86000) {
//            $net86000 = (float) (DocumentJournal::query()
//                ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
//                    $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
//                })
//                ->whereDate('date', '<=', $asOfDate)
//                ->selectRaw(
//                    'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
//                    [$acc86000, $acc86000]
//                )
//                ->value('balance') ?? 0);
//        }
//
//        $net86001 = 0.0;
//        if ($acc86001) {
//            $net86001 = (float) (DocumentJournal::query()
//                ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
//                    $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
//                })
//                ->whereDate('date', '<=', $asOfDate)
//                ->selectRaw(
//                    'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
//                    [$acc86001, $acc86001]
//                )
//                ->value('balance') ?? 0);
//        }
//
//        return $net86000 + $net86001;
//    }
//
//    /**
//     * Sum provided amounts for contracts with given category between $dateFrom and $dateTo
//     */
//    private function sumByCategoryBetween(string $categoryName, string $dateFrom, string $dateTo): float
//    {
//        return DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->whereHasMorph('journalable', [Contract::class], function ($q) use ($categoryName) {
//                $q->whereHas('client.classification', function ($q2) {
//                    $q2->whereNotIn('name', ['standard', 'monitored']);
//                });
//                if ($categoryName === 'category2') {
//                    $q->where('category_id', 2);
//                } else {
//                    $q->whereHas('category', function ($q3) use ($categoryName) {
//                        if ($categoryName === 'car') {
//                            $q3->whereIn('name', ['car', 'car-purchase']);
//                        } else {
//                            $q3->where('name', $categoryName);
//                        }
//                    });
//                }
//            })
//            ->whereBetween('date', [$dateFrom, $dateTo])
//            ->sum('amount_amd');
//    }
//
//    /**
//     * Sum amounts for given account column (debit_account_id or credit_account_id) before date
//     */
//    private function sumAccountBefore($accountId, string $column, string $dateFrom): float
//    {
//        if (!$accountId) return 0;
//        return DocumentJournal::where($column, $accountId)
//            // Opening balance at period start must exclude same-day movements.
//            ->whereDate('date', '<', $dateFrom)
//            ->sum('amount_amd');
//    }
//
//    /**
//     * Document types treated as off-balance incoming for Sheet2 column J.
//     * Includes canonical OFF_BALANCE_INCOMING and legacy rows (e.g. loss reserve postings).
//     *
//     * @return array<string>
//     */
//    private function sheet2OffBalanceIncomingDocumentTypes(): array
//    {
//        return array_values(array_unique([
//            DocumentJournal::OFF_BALANCE_INCOMING,
//            'Արտաբալանս մուտքային',
//            'Արտաբալանս Մուտքային',
//        ]));
//    }
//
//    /**
//     * Sheet2 columns J/L/P: sum debits or credits on given 86000-family accounts in period, by car / gold / category2.
//     * Optional $documentTypes limits rows (e.g. OFF_BALANCE_INCOMING for J, loss accruals for P).
//     * Category from contract_id, journalable link, comment ("contract #N"), or debit/credit partner.
//     *
//     * @param  array<int|null>  $accountIds
//     * @param  array<string>|null  $documentTypes
//     */
//    private function sumSheet286000TurnoverByCategory(
//        string $categoryName,
//        string $amountColumn,
//        string $dateFrom,
//        string $dateTo,
//        array $accountIds,
//        ?array $documentTypes = null
//    ): float {
//        if (!in_array($amountColumn, ['debit_account_id', 'credit_account_id'], true)) {
//            return 0.0;
//        }
//
//        $accountIds = array_values(array_filter($accountIds));
//        if ($accountIds === []) {
//            return 0.0;
//        }
//
//        $sum = 0.0;
//        $query = DocumentJournal::query()
//            ->whereBetween('date', [$dateFrom, $dateTo])
//            ->whereIn($amountColumn, $accountIds);
//
//        if ($documentTypes !== null && $documentTypes !== []) {
//            $query->whereIn('document_type', $documentTypes);
//        }
//
//        $rows = $query->get();
//
//        foreach ($rows as $row) {
//            if ($this->sheet2JournalCategoryName($row) !== $categoryName) {
//                continue;
//            }
//            $sum += (float) $row->amount_amd;
//        }
//
//        return $sum;
//    }
//
//    /**
//     * Resolve car / gold / category2 for an 86000/86001 journal row.
//     */
//    private function sheet2JournalCategoryName(DocumentJournal $row): ?string
//    {
//        if (preg_match('/contract #(\d+)/i', (string) $row->comment, $matches)) {
//            $fromComment = $this->sheet2CategoryNameForContractId((int) $matches[1]);
//            if ($fromComment !== null) {
//                return $fromComment;
//            }
//        }
//
//        if ($row->contract_id) {
//            $fromContractId = $this->sheet2CategoryNameForContractId((int) $row->contract_id);
//            if ($fromContractId !== null) {
//                return $fromContractId;
//            }
//        }
//
//        if ($row->journalable_type === Contract::class && $row->journalable_id) {
//            $fromJournalable = $this->sheet2CategoryNameForContractId((int) $row->journalable_id);
//            if ($fromJournalable !== null) {
//                return $fromJournalable;
//            }
//        }
//
//        if ($row->journalable_type === DocumentJournal::class && $row->journalable_id) {
//            $parent = DocumentJournal::query()->find($row->journalable_id);
//            if ($parent && $parent->journalable_type === Contract::class && $parent->journalable_id) {
//                $fromParent = $this->sheet2CategoryNameForContractId((int) $parent->journalable_id);
//                if ($fromParent !== null) {
//                    return $fromParent;
//                }
//            }
//        }
//
//        $partnerId = (int) ($row->debit_partner_id ?: $row->credit_partner_id ?: 0);
//        if ($partnerId > 0) {
//            $contractIds = Contract::query()
//                ->where('client_id', $partnerId)
//                ->pluck('id');
//            foreach ($contractIds as $contractId) {
//                $fromPartner = $this->sheet2CategoryNameForContractId((int) $contractId);
//                if ($fromPartner !== null) {
//                    return $fromPartner;
//                }
//            }
//        }
//
//        return null;
//    }
//
//    /**
//     * @return 'car'|'gold'|'category2'|null
//     */
//    private function sheet2CategoryNameForContractId(int $contractId): ?string
//    {
//        static $cache = [];
//
//        if (array_key_exists($contractId, $cache)) {
//            return $cache[$contractId];
//        }
//
//        $contract = Contract::query()->with('category')->find($contractId);
//        if (!$contract) {
//            return $cache[$contractId] = null;
//        }
//
//        if ((int) $contract->category_id === 2) {
//            return $cache[$contractId] = 'category2';
//        }
//
//        $name = $contract->category?->name;
//        if (in_array($name, ['car', 'car-purchase'], true)) {
//            return $cache[$contractId] = 'car';
//        }
//        if ($name === 'gold') {
//            return $cache[$contractId] = 'gold';
//        }
//
//        return $cache[$contractId] = null;
//    }
//
//    /**
//     * Opening balance for one account before period start (debit - credit).
//     */
//    private function balanceAccountBefore($accountId, string $dateFrom): float
//    {
//        if (!$accountId) {
//            return 0;
//        }
//
//        $debit = DocumentJournal::where('debit_account_id', $accountId)
//            ->whereDate('date', '<', $dateFrom)
//            ->sum('amount_amd');
//
//        $credit = DocumentJournal::where('credit_account_id', $accountId)
//            ->whereDate('date', '<', $dateFrom)
//            ->sum('amount_amd');
//
//        return (float) $debit - (float) $credit;
//    }
//
//    /**
//     * Sum amounts for given account column (debit_account_id or credit_account_id) between dates
//     */
//    private function sumAccountBetween($accountId, string $column, string $dateFrom, string $dateTo): float
//    {
//        if (!$accountId) return 0;
//        return DocumentJournal::where($column, $accountId)
//            ->whereBetween('date', [$dateFrom, $dateTo])
//            ->sum('amount_amd');
//    }
//
//    private function getColumnByDays($days): string
//    {
//        if ($days <= 90) return 'B';
//        if ($days <= 180) return 'D';
//        if ($days <= 270) return 'F';
//        if ($days <= 366) return 'H';
//        if ($days <= 1826) return 'J';
//        return 'L';
//    }
//}


namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\ClassificationHistory;
use App\Models\Contract;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

        $docs = DocumentJournal::with(['journalable.payments' => function ($q) use ($date) {
            // Load payments that were unpaid as of the report date:
            // either still initial, or completed after the report date.
            $q->where(function ($q2) use ($date) {
                $q2->where('status', 'initial')
                    ->orWhere(function ($q3) use ($date) {
                        $q3->where('status', 'completed')
                            ->whereDate('to_date', '>', $date);
                    });
            });
        }])
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereDate('date', '<=', $date)
            ->get();

        $carContractCount = 0;
        $goldContractCount = 0;
        $category2ContractCount = 0;

        $carContractAmount = 0;
        $goldContractAmount = 0;
        $category2ContractAmount = 0;
        $expiredCarContractAmount = 0;
        $expiredGoldContractAmount = 0;
        $expiredCategory2ContractAmount = 0;
        $onTimeContractAmount = 0;

        $groupsOnTime = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
        $groupsExpired = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
        $groupsCar = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
        $groupsGold = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];
        $groupsCategory2 = ['B' => 0, 'D' => 0, 'F' => 0, 'H' => 0, 'J' => 0, 'L' => 0];

        $classificationCounts = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
        $amountsByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
        $weightedByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];
        $reserveByClassification = ['standard' => 0, 'monitored' => 0, 'substandard' => 0, 'suspicious' => 0, 'loss' => 0];

        $onTimeCount = 0;
        $expiredCount = 0;
        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');
        $acc16200 = ChartOfAccount::idByCode('16200');

        // Use classification as of report end date (not clients.classification_id today).
        $clientClassAsOf = $this->sheet1ClientClassificationsAsOf($date);

        foreach ($docs as $doc) {
            $contract = $doc->journalable;
            if (!$contract || !$contract->client) {
                continue;
            }

            $classification = $clientClassAsOf[$contract->client_id] ?? null;
            if (!$classification) {
                continue;
            }
            // Skip contracts that were already closed before the report date.
            // A contract closed after $date was still active on the report snapshot.
            if ($contract->closed_at && Carbon::parse($contract->closed_at)->lt($date)) continue;

            $hasExpiredPayment = $contract->payments
                ->contains(function ($p) use ($date) {
                    return Carbon::parse($p->date)->lt($date);
                });

            if ($hasExpiredPayment) {
                $expiredCount++;
            } else {
                $onTimeCount++;
            }
            // Bucket by report snapshot date, not contract creation timestamp.
            $days = Carbon::parse($contract->deadline)
                ->diffInDays(Carbon::parse($date));

            $col = $this->getColumnByDays($days);

            $name = $classification->name;
            if (!isset($amountsByClassification[$name])) {
                continue;
            }

            $classificationCounts[$name]++;
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

            $net16200 = 0;
            if ($acc16200) {
                $net16200 = DocumentJournal::where(function ($query) use ($contract, $doc) {
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
                        [$acc16200, $acc16200])
                    ->value('balance');
            }

            $amount = $net16200NV + $net16201NI + $net16200;
            $amountsByClassification[$name] += $amount;
            if ($contract->category) {
                if (in_array($contract->category->name, ['car', 'car-purchase'])) {
                    $carContractAmount += $amount;
                    $groupsCar[$col] += $net16200NV;
                    $carContractCount++;
                    if ($hasExpiredPayment) {
                        $expiredCarContractAmount += $amount;
                    }
                }
                if ($contract->category->name === 'gold') {
                    $goldContractAmount += $amount;
                    $groupsGold[$col] += $net16200NV;
                    $goldContractCount++;
                    if ($hasExpiredPayment) {
                        $expiredGoldContractAmount += $amount;
                    }
                }
            }
            if ((int)$contract->category_id === 2) {
                $category2ContractCount++;
                $category2ContractAmount += $amount;
                $groupsCategory2[$col] += $net16200NV;
                if ($hasExpiredPayment) {
                    $expiredCategory2ContractAmount += $amount;
                }
            }
            if ($hasExpiredPayment) {
                $groupsExpired[$col] += $net16200NV;
            } else {
                $groupsOnTime[$col] += $net16200NV;
                $onTimeContractAmount += $amount;
            }

            $interest = DocumentJournal::where('journalable_id', $doc->id)
                ->whereIn('document_type', [DocumentJournal::INTEREST_RATE_AMOUNT, DocumentJournal::EFFECTIVE_RATE_AMOUNT])
                ->where('date', '<', $date)
                ->sum('amount_amd');

            $weightedByClassification[$name] += $interest;
            $reserve_percent = $classification->reserve_percent ?? 0;
            $reserveByClassification[$name] += $amount * $reserve_percent / 100;
        }

        $rowsOnTime = [15, 16];
        foreach ($rowsOnTime as $row) {
            foreach ($groupsOnTime as $col => $value) {
                $sheet->setCellValue($col . $row, $value / 1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }
        $sheet->setCellValue('P15', $onTimeContractAmount / 1000);
        $sheet->getStyle('P15')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P16', $onTimeContractAmount / 1000);
        $sheet->getStyle('P16')->getNumberFormat()->setFormatCode('#,##0');
        $rowsExpired = [21, 22];
        foreach ($rowsExpired as $row) {
            foreach ($groupsExpired as $col => $value) {
                $sheet->setCellValue($col . $row, $value / 1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }
        $sheet->setCellValue('P21', ($expiredCarContractAmount + $expiredGoldContractAmount + $expiredCategory2ContractAmount) / 1000);
        $sheet->getStyle('P21')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P22', ($expiredCarContractAmount + $expiredGoldContractAmount + $expiredCategory2ContractAmount) / 1000);
        $sheet->getStyle('P22')->getNumberFormat()->setFormatCode('#,##0');

        $sheet->setCellValue('R15', $onTimeCount);
        $sheet->setCellValue('R16', $onTimeCount);
        $sheet->setCellValue('R21', $expiredCount);
        $sheet->setCellValue('R22', $expiredCount);

        $rowsCar = [110];
        $rowsTotal = [108];

        foreach ($groupsCar as $col => $value) {
            foreach ($rowsCar as $row) {
                $sheet->setCellValue($col . $row, $value / 1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }

        foreach ($groupsGold as $col => $value) {
            $sheet->setCellValue($col . '112', $value / 1000);
            $sheet->getStyle($col . '112')->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach ($groupsCategory2 as $col => $value) {
            $sheet->setCellValue($col . '113', $value / 1000);
            $sheet->getStyle($col . '113')->getNumberFormat()->setFormatCode('#,##0');
        }

        foreach ($groupsCar as $col => $value) {
            $goldValue = $groupsGold[$col] ?? 0;
            $category2Value = $groupsCategory2[$col] ?? 0;
            foreach ($rowsTotal as $row) {
                $sheet->setCellValue($col . $row, ($value + $goldValue + $category2Value) / 1000);
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
        }
        $carContractAmount = $carContractAmount / 1000;
        $goldContractAmount = $goldContractAmount / 1000;
        $category2Thousands = $category2ContractAmount / 1000;
        $sheet->setCellValue('P108', $carContractAmount + $goldContractAmount + $category2Thousands);
        $sheet->getStyle('P108')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P109', 0);
        $sheet->getStyle('P109')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P110', $carContractAmount);
        $sheet->getStyle('P110')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P111', 0);
        $sheet->getStyle('P111')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P112', $goldContractAmount);
        $sheet->getStyle('P112')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('P113', $category2Thousands);
        $sheet->getStyle('P113')->getNumberFormat()->setFormatCode('#,##0');

        $sheet->setCellValue('R108', $carContractCount + $goldContractCount + $category2ContractCount);
        $sheet->getStyle('R108')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('R109', 0);
        $sheet->getStyle('R109')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('R110', $carContractCount);
        $sheet->getStyle('R110')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('R111', 0);
        $sheet->getStyle('R111')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('R112', $goldContractCount);
        $sheet->getStyle('R112')->getNumberFormat()->setFormatCode('#,##0');
        $sheet->setCellValue('R113', $category2ContractCount);
        $sheet->getStyle('R113')->getNumberFormat()->setFormatCode('#,##0');

        $rows = [125, 126, 127, 128, 129];
        $classificationKeys = ['standard', 'monitored', 'substandard', 'suspicious', 'loss'];
        foreach ($rows as $index => $row) {
            $key = $classificationKeys[$index];
            $amountByClass = ($amountsByClassification[$key] ?? 0);
            $reserveByClass = ($reserveByClassification[$key] ?? 0);

            // Business rule: monitored reserve must be exactly 10% of monitored amount (D126).
            if ($key === 'monitored') {
                $reserveByClass = $amountByClass * 0.10;
            }

            $sheet->setCellValue('B' . $row, ($classificationCounts[$key] ?? 0));
            $sheet->setCellValue('D' . $row, $amountByClass / 1000);
            $sheet->setCellValue('F' . $row, $reserveByClass / 1000);
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
        }

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
            $balance15300 = DocumentJournal::whereIn('credit_account_id', $acc15300Ids)
                    ->whereDate('date', '<=', $date)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('debit_account_id', $acc15300Ids)
                    ->whereDate('date', '<=', $date)
                    ->sum('amount_amd');

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
                ->distinct('debit_partner_id')
                ->count('debit_partner_id');
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
        $sheet->getStyle('D5')->getFont()->setName('Sylfaen');
        $sheet->setCellValue('D7', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($from));
        $sheet->getStyle('D7')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $sheet->setCellValue('F7', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($to));
        $sheet->getStyle('F7')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
        $rowCar = 89;
        $rowGold = 91;
        $rowCategory2 = 92;

        // Sheet2 column B: snapshot as of day before report `from`, using effective class on that snapshot date.
        $sheet2SnapshotDate = Carbon::parse($from)->subDay()->format('Y-m-d');
        $sheet2ClientClassById = $this->sheet2ClientClassificationsAsOf($sheet2SnapshotDate);

        $goldAmountBefore = $this->sumSheet2ColumnBNetByCategory('gold', $sheet2SnapshotDate, $sheet2ClientClassById);
        $carAmountBefore = $this->sumSheet2ColumnBNetByCategory('car', $sheet2SnapshotDate, $sheet2ClientClassById);
        $category2AmountBefore = $this->sumSheet2ColumnBNetByCategory('category2', $sheet2SnapshotDate, $sheet2ClientClassById);

        $sheet2ReportDate = Carbon::parse($to)->format('Y-m-d');
        // Sheet2 column D: period change (not closing balance) for clients in class 3/4/5 at report end.
        $sheet2ClientClassByIdTo = $this->sheet2ClientClassificationsAsOf($sheet2ReportDate);

        $goldPeriodChange = $this->sumSheet2ColumnDPeriodChangeByCategory(
            'gold',
            $dateFrom,
            $date,
            $sheet2ClientClassByIdTo
        );
        $carPeriodChange = $this->sumSheet2ColumnDPeriodChangeByCategory(
            'car',
            $dateFrom,
            $date,
            $sheet2ClientClassByIdTo
        );
        $category2PeriodChange = $this->sumSheet2ColumnDPeriodChangeByCategory(
            'category2',
            $dateFrom,
            $date,
            $sheet2ClientClassByIdTo
        );
        $totalPeriodChange = $carPeriodChange + $goldPeriodChange + $category2PeriodChange;

        $sheet2->setCellValue("B{$rowCar}", $carAmountBefore / 1000);
        $sheet2->setCellValue("B{$rowGold}", $goldAmountBefore / 1000);
        $sheet2->setCellValue("B{$rowCategory2}", $category2AmountBefore / 1000);
        $sheet2->setCellValue("B87", ($carAmountBefore + $goldAmountBefore + $category2AmountBefore) / 1000);

        foreach ([6, 7, 87] as $summaryRow) {
            $sheet2->setCellValue("D{$summaryRow}", $totalPeriodChange / 1000);
            $sheet2->getStyle("D{$summaryRow}")->getNumberFormat()->setFormatCode('#,##0');
        }
        $sheet2->setCellValue("D{$rowCar}", $carPeriodChange / 1000);
        $sheet2->setCellValue("D{$rowGold}", $goldPeriodChange / 1000);
        $sheet2->setCellValue("D{$rowCategory2}", $category2PeriodChange / 1000);
        foreach (["D{$rowCar}", "D{$rowGold}", "D{$rowCategory2}"] as $cell) {
            $sheet2->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0');
        }

        $acc86000 = ChartOfAccount::idByCode('86000');
        $acc860001 = ChartOfAccount::idByCode('86001') ?: ChartOfAccount::idByCode('860001');
        $acc86000Family = array_values(array_filter([$acc86000, $acc860001]));

        // Column H is opening balance at period start (before date_from), split car/gold/category2
        // the same way columns J/L/P are split (category resolved via sheet2JournalCategoryName).
        $balanceCar86000 = $this->sumSheet286000BalanceBeforeByCategory('car', $dateFrom, $acc86000Family);
        $balanceGold86000 = $this->sumSheet286000BalanceBeforeByCategory('gold', $dateFrom, $acc86000Family);
        $balanceCategory286000 = $this->sumSheet286000BalanceBeforeByCategory('category2', $dateFrom, $acc86000Family);

        $sheet2->setCellValue("H{$rowCar}", $balanceCar86000 / 1000);
        $sheet2->setCellValue("H{$rowGold}", $balanceGold86000 / 1000);
        $sheet2->setCellValue("H{$rowCategory2}", $balanceCategory286000 / 1000);
        $sheet2->setCellValue('H87', ($balanceCar86000 + $balanceGold86000 + $balanceCategory286000) / 1000);

        // Column J: 86000+86001 debits, off-balance incoming AND real write-off document types
        // (incl. legacy spellings in DB) — write-offs post to both 86000 (principal) and 86001 (interest).
        $columnJDocumentTypes = $this->sheet2ColumnJDocumentTypes();
        $debitCar860 = $this->sumSheet286000TurnoverByCategory(
            'car',
            'debit_account_id',
            $dateFrom,
            $date,
            $acc86000Family,
            $columnJDocumentTypes
        );
        $debitGold860 = $this->sumSheet286000TurnoverByCategory(
            'gold',
            'debit_account_id',
            $dateFrom,
            $date,
            $acc86000Family,
            $columnJDocumentTypes
        );
        $debitCategory2860 = $this->sumSheet286000TurnoverByCategory(
            'category2',
            'debit_account_id',
            $dateFrom,
            $date,
            $acc86000Family,
            $columnJDocumentTypes
        );

        // Column P: 86000+86001 debits for loss penalty / nominal / effective interest document types.
        $lossDocTypes = [
            DocumentJournal::LOSS_PENALTY,
            DocumentJournal::LOSS_INTEREST_NOMINAL,
            DocumentJournal::LOSS_INTEREST_EFFECTIVE,
        ];
        $lossCar860 = $this->sumSheet286000TurnoverByCategory(
            'car',
            'debit_account_id',
            $dateFrom,
            $date,
            array_values(array_filter([$acc86000, $acc860001])),
            $lossDocTypes
        );
        $lossGold860 = $this->sumSheet286000TurnoverByCategory(
            'gold',
            'debit_account_id',
            $dateFrom,
            $date,
            array_values(array_filter([$acc86000, $acc860001])),
            $lossDocTypes
        );
        $lossCategory2860 = $this->sumSheet286000TurnoverByCategory(
            'category2',
            'debit_account_id',
            $dateFrom,
            $date,
            array_values(array_filter([$acc86000, $acc860001])),
            $lossDocTypes
        );

        // Column L: 86000+86001 credits in period (all document types), same category split.
        $creditCar860 = $this->sumSheet286000TurnoverByCategory(
            'car',
            'credit_account_id',
            $dateFrom,
            $date,
            array_values(array_filter([$acc86000, $acc860001])),
            null
        );
        $creditGold860 = $this->sumSheet286000TurnoverByCategory(
            'gold',
            'credit_account_id',
            $dateFrom,
            $date,
            array_values(array_filter([$acc86000, $acc860001])),
            null
        );
        $creditCategory2860 = $this->sumSheet286000TurnoverByCategory(
            'category2',
            'credit_account_id',
            $dateFrom,
            $date,
            array_values(array_filter([$acc86000, $acc860001])),
            null
        );

        $sheet2->setCellValue("J{$rowCar}", $debitCar860 / 1000);
        $sheet2->setCellValue("J{$rowGold}", $debitGold860 / 1000);
        $sheet2->setCellValue("J{$rowCategory2}", $debitCategory2860 / 1000);
        $sheet2->setCellValue('J87', ($debitCar860 + $debitGold860 + $debitCategory2860) / 1000);

        $sheet2->setCellValue("L{$rowCar}", $creditCar860 / 1000);
        $sheet2->setCellValue("L{$rowGold}", $creditGold860 / 1000);
        $sheet2->setCellValue("L{$rowCategory2}", $creditCategory2860 / 1000);
        $sheet2->setCellValue('L87', ($creditCar860 + $creditGold860 + $creditCategory2860) / 1000);

        $sheet2->setCellValue("P{$rowCar}", $lossCar860 / 1000);
        $sheet2->setCellValue("P{$rowGold}", $lossGold860 / 1000);
        $sheet2->setCellValue("P{$rowCategory2}", $lossCategory2860 / 1000);
        $sheet2->setCellValue('P87', ($lossCar860 + $lossGold860 + $lossCategory2860) / 1000);

        foreach ([
                     'J87', "J{$rowCar}", "J{$rowGold}", "J{$rowCategory2}",
                     'L87', "L{$rowCar}", "L{$rowGold}", "L{$rowCategory2}",
                     'P87', "P{$rowCar}", "P{$rowGold}", "P{$rowCategory2}",
                 ] as $cell) {
            $sheet2->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0');
        }

        // Column R: left to Excel template formulas (H+J−L per row).

        $fileName = 'v06_export_' . now()->format('Ymd_His') . '.xls';
        $savePath = storage_path('app/public/' . $fileName);

        $writer = new Xls($spreadsheet);
        $writer->save($savePath);

        return $savePath;
    }


    /**
     * Sheet1 rows 125–130: effective class per client on $snapshotDate (report `to` date).
     * Same effective-date rule as CorrectAllClientReservesJob / Sheet2 — not clients.classification_id.
     *
     * @return array<int, object{name: string, reserve_percent: float}> client_id => snapshot class
     */
    public function sheet1ClientClassificationsAsOf(string $snapshotDate): array
    {
        $maxDates = DB::table('classification_histories')
            ->select('client_id', DB::raw('MAX(`date`) as max_date'))
            ->whereDate('date', '<=', $snapshotDate)
            ->whereNull('deleted_at')
            ->groupBy('client_id');

        $latestRowIds = DB::table('classification_histories as ch')
            ->joinSub($maxDates, 'md', function ($join) {
                $join->on('ch.client_id', '=', 'md.client_id')
                    ->on('ch.date', '=', 'md.max_date');
            })
            ->whereNull('ch.deleted_at')
            ->groupBy('ch.client_id')
            ->select('ch.client_id', DB::raw('MAX(ch.id) as latest_id'));

        $rows = DB::table('classification_histories as ch')
            ->joinSub($latestRowIds, 'lr', function ($join) {
                $join->on('ch.id', '=', 'lr.latest_id');
            })
            ->join('clients_classification as cc', 'cc.id', '=', 'ch.classification_id')
            ->select([
                'ch.client_id',
                'cc.name as classification_name',
                DB::raw('COALESCE(ch.reserve_percent, cc.reserve_percent) as reserve_percent'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->client_id] = (object)[
                'name' => (string)$row->classification_name,
                'reserve_percent' => (float)$row->reserve_percent,
            ];
        }

        return $out;
    }

    /**
     * Sheet2 column B: map client_id => effective classification_id (3, 4, or 5) on $snapshotDate.
     * Effective class = the most recent classification_histories row with date on or before that day
     * (e.g. …→4→1 means class 1 on snapshot — client is omitted here because latest is not 3/4/5).
     *
     * @return array<int, int> client_id => classification_id
     */
    private function sheet2ClientClassificationsAsOf(string $snapshotDate): array
    {
        $maxDates = DB::table('classification_histories')
            ->select('client_id', DB::raw('MAX(`date`) as max_date'))
            ->whereDate('date', '<=', $snapshotDate)
            ->whereNull('deleted_at')
            ->groupBy('client_id');

        $latestRowIds = DB::table('classification_histories as ch')
            ->joinSub($maxDates, 'md', function ($join) {
                $join->on('ch.client_id', '=', 'md.client_id')
                    ->on('ch.date', '=', 'md.max_date');
            })
            ->whereNull('ch.deleted_at')
            ->groupBy('ch.client_id')
            ->select('ch.client_id', DB::raw('MAX(ch.id) as latest_id'));

        return DB::table('classification_histories as ch')
            ->joinSub($latestRowIds, 'lr', function ($join) {
                $join->on('ch.id', '=', 'lr.latest_id');
            })
            ->whereIn('ch.classification_id', [3, 4, 5])
            ->pluck('ch.classification_id', 'ch.client_id')
            ->all();
    }

    /**
     * Sheet2 column D: map client_id => last classification_id (3/4/5) inside report period [$dateFrom, $dateTo].
     *
     * @return array<int, int> client_id => classification_id
     */
    private function sheet2ClientClassificationsBetween(string $dateFrom, string $dateTo): array
    {
        $maxDates = DB::table('classification_histories')
            ->select('client_id', DB::raw('MAX(`date`) as max_date'))
            ->whereBetween(DB::raw('DATE(`date`)'), [$dateFrom, $dateTo])
            ->whereNull('deleted_at')
            ->groupBy('client_id');

        $latestRowIds = DB::table('classification_histories as ch')
            ->joinSub($maxDates, 'md', function ($join) {
                $join->on('ch.client_id', '=', 'md.client_id')
                    ->on('ch.date', '=', 'md.max_date');
            })
            ->whereNull('ch.deleted_at')
            ->groupBy('ch.client_id')
            ->select('ch.client_id', DB::raw('MAX(ch.id) as latest_id'));

        return DB::table('classification_histories as ch')
            ->joinSub($latestRowIds, 'lr', function ($join) {
                $join->on('ch.id', '=', 'lr.latest_id');
            })
            ->whereIn('ch.classification_id', [3, 4, 5])
            ->pluck('ch.classification_id', 'ch.client_id')
            ->all();
    }

    /**
     * Calendar date of the most recent classification_histories row (on or before $snapshotDate) where the
     * client was set to $classificationId (journal nets run through the day before this date).
     */
    private function lastClientClassificationAssignmentDate(int $clientId, int $classificationId, string $snapshotDate): ?string
    {
        $d = ClassificationHistory::query()
            ->where('client_id', $clientId)
            ->where('classification_id', $classificationId)
            ->whereNull('deleted_at')
            ->whereDate('date', '<=', $snapshotDate)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->value('date');

        return $d ? Carbon::parse($d)->format('Y-m-d') : null;
    }

    /**
     * Journal as-of date for Sheet2 column B: day before the last time (on or before snapshot) the client
     * was assigned their current effective class (3, 4, or 5).
     */
    private function sheet2JournalAsOfDate(int $classificationId, string $snapshotDate, int $clientId): string
    {
        if (!in_array($classificationId, [3, 4, 5], true)) {
            return $snapshotDate;
        }

        $assigned = $this->lastClientClassificationAssignmentDate($clientId, $classificationId, $snapshotDate);
        if (!$assigned) {
            return $snapshotDate;
        }

        return Carbon::parse($assigned)->format('Y-m-d');
    }

    /**
     * Sheet2 column B: by car/gold/category_id=2, journals through the day before the last assignment to effective class 3/4/5.
     * Class 3–4: net162 (16200NV + 16201NI + 16200). Class 5: net86000 + net86001.
     */
    private function sumSheet2ColumnBNetByCategory(string $categoryName, string $snapshotDate, array $clientIdToClass): float
    {
        if ($clientIdToClass === []) {
            return 0.0;
        }

        $clientIds = array_keys($clientIdToClass);

        $contracts = Contract::query()
            ->with('category')
            ->whereIn('client_id', $clientIds)
            ->where('status', 'initial')
            ->whereDate('date', '<=', $snapshotDate)
            ->where(function ($q) use ($snapshotDate) {
                $q->whereNull('closed_at')
                    ->orWhereDate('closed_at', '>=', $snapshotDate);
            })
            ->when($categoryName === 'category2', function ($q) {
                $q->where('category_id', 2);
            }, function ($q) use ($categoryName) {
                $q->whereHas('category', function ($q2) use ($categoryName) {
                    if ($categoryName === 'car') {
                        $q2->whereIn('name', ['car', 'car-purchase']);
                    } else {
                        $q2->where('name', 'gold');
                    }
                });
            })
            ->get();

        if ($contracts->isEmpty()) {
            return 0.0;
        }

        $provideByContractId = DocumentJournal::query()
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->where('journalable_type', Contract::class)
            ->whereIn('journalable_id', $contracts->pluck('id'))
            ->get()
            ->keyBy('journalable_id');

        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');
        $acc16200 = ChartOfAccount::idByCode('16200');
        $acc86000 = ChartOfAccount::idByCode('86000');
        $acc86001 = ChartOfAccount::idByCode('86001');

        $sum = 0.0;
        foreach ($contracts as $contract) {
            $doc = $provideByContractId->get($contract->id);
            if (!$doc) {
                continue;
            }
            $classId = (int)($clientIdToClass[$contract->client_id] ?? 0);
            if (!in_array($classId, [3, 4, 5], true)) {
                continue;
            }
            $asOf = $snapshotDate;
            if (Carbon::parse($contract->date)->format('Y-m-d') > $asOf) {
                continue;
            }
            if ($classId === 5) {
                $sum += $this->contractNet86000Plus86001AtDate($doc, $contract, $asOf, $acc86000, $acc86001);
            } elseif (in_array($classId, [3, 4], true)) {
                $sum += $this->contractNet162AtDate($doc, $contract, $asOf, $acc16200NV, $acc16201NI, $acc16200);
            }
        }

        return (float)$sum;
    }

    /**
     * Sheet2 column D: net portfolio change during [$dateFrom, $dateTo] for clients in class 3/4/5 at report end.
     * Change = balance at $dateTo minus opening balance for that row (same as column B at period start).
     * Clients not yet in class 3/4/5 on the day before $dateFrom contribute 0 opening — not their pre-downgrade balance.
     */
    private function sumSheet2ColumnDPeriodChangeByCategory(
        string $categoryName,
        string $dateFrom,
        string $dateTo,
        array  $clientIdToClassAtTo
    ): float
    {
        if ($clientIdToClassAtTo === []) {
            return 0.0;
        }

        $openSnapshotDate = Carbon::parse($dateFrom)->subDay()->format('Y-m-d');
        $contracts = $this->sheet2ContractsForCategory($categoryName, array_keys($clientIdToClassAtTo), $dateTo);

        if ($contracts->isEmpty()) {
            return 0.0;
        }

        $provideByContractId = DocumentJournal::query()
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->where('journalable_type', Contract::class)
            ->whereIn('journalable_id', $contracts->pluck('id'))
            ->get()
            ->keyBy('journalable_id');

        $change = 0.0;
        foreach ($contracts as $contract) {
            $doc = $provideByContractId->get($contract->id);
            if (!$doc) {
                continue;
            }

            $classAtTo = (int)($clientIdToClassAtTo[$contract->client_id] ?? 0);
            if (!in_array($classAtTo, [3, 4, 5], true)) {
                continue;
            }

            if (Carbon::parse($contract->date)->format('Y-m-d') > $dateTo) {
                continue;
            }

            $classAtOpen = $this->clientClassificationIdAsOf((int)$contract->client_id, $openSnapshotDate);

            // Opening for D matches column B: only clients already in 3/4/5 at period start have a non-zero base.
            $openNet = 0.0;
            if (in_array($classAtOpen, [3, 4, 5], true)) {
                $openNet = $this->contractPortfolioNetAtDate(
                    $doc,
                    $contract,
                    $openSnapshotDate,
                    (int)$classAtOpen
                );
            }

            $closeNet = $this->contractPortfolioNetAtDate($doc, $contract, $dateTo, $classAtTo);
            $change += $closeNet - $openNet;
        }

        return (float)$change;
    }

    /**
     * Effective classification_id for a client on or before $snapshotDate.
     */
    private function clientClassificationIdAsOf(int $clientId, string $snapshotDate): ?int
    {
        $row = ClassificationHistory::query()
            ->where('client_id', $clientId)
            ->whereNull('deleted_at')
            ->whereDate('date', '<=', $snapshotDate)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        return $row ? (int)$row->classification_id : null;
    }

    /**
     * Active contracts for Sheet2 car / gold / category2 and given client ids.
     */
    private function sheet2ContractsForCategory(string $categoryName, array $clientIds, string $asOfDate)
    {
        return Contract::query()
            ->with('category')
            ->whereIn('client_id', $clientIds)
            ->where('status', 'initial')
            ->whereDate('date', '<=', $asOfDate)
            ->where(function ($q) use ($asOfDate) {
                $q->whereNull('closed_at')
                    ->orWhereDate('closed_at', '>=', $asOfDate);
            })
            ->when($categoryName === 'category2', function ($q) {
                $q->where('category_id', 2);
            }, function ($q) use ($categoryName) {
                $q->whereHas('category', function ($q2) use ($categoryName) {
                    if ($categoryName === 'car') {
                        $q2->whereIn('name', ['car', 'car-purchase']);
                    } else {
                        $q2->where('name', 'gold');
                    }
                });
            })
            ->get();
    }

    /**
     * Portfolio net at a date: class 5 → 86000+86001; otherwise 16200NV+16201NI+16200 (partner-aware scope).
     */
    private function contractPortfolioNetAtDate(
        DocumentJournal $provideDoc,
        Contract        $contract,
        string          $asOfDate,
        int             $classificationId
    ): float
    {
        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');
        $acc16200 = ChartOfAccount::idByCode('16200');
        $acc86000 = ChartOfAccount::idByCode('86000');
        $acc86001 = ChartOfAccount::idByCode('86001');

        if ($classificationId === 5) {
            return $this->contractNet86000Plus86001AtDate(
                $provideDoc,
                $contract,
                $asOfDate,
                $acc86000,
                $acc86001
            );
        }

        return $this->contractNet162AtDate(
            $provideDoc,
            $contract,
            $asOfDate,
            $acc16200NV,
            $acc16201NI,
            $acc16200
        );
    }

    /**
     * Scope portfolio journals to a contract: journalable link, contract_id, or debit/credit partner.
     * When a partner is set on a row, it must match the contract client (fixes mis-linked journalable_id).
     */
    private function applyPortfolioJournalScope(
        $query,
        Contract $contract,
        DocumentJournal $provideDoc,
        array $portfolioAccountIds
    ): void
    {
        $clientId = (int)$contract->client_id;
        $contractId = (int)$contract->id;
        $provideDocId = (int)$provideDoc->id;
        $accountIds = array_values(array_filter($portfolioAccountIds));

        $query->where(function ($outer) use ($clientId, $contractId, $provideDocId, $accountIds) {
            $outer->where('contract_id', $contractId)
                ->orWhere(function ($partner) use ($clientId, $contractId, $accountIds) {
                    $partner->where(function ($p) use ($clientId) {
                        $p->where('debit_partner_id', $clientId)
                            ->orWhere('credit_partner_id', $clientId);
                    });
                    if ($accountIds !== []) {
                        $partner->where(function ($a) use ($accountIds) {
                            $a->whereIn('debit_account_id', $accountIds)
                                ->orWhereIn('credit_account_id', $accountIds);
                        });
                    }
                    $partner->where(function ($c) use ($contractId) {
                        $c->whereNull('contract_id')
                            ->orWhere('contract_id', $contractId);
                    });
                })
                ->orWhere(function ($journalable) use ($clientId, $contractId, $provideDocId) {
                    $journalable->where(function ($q) use ($contractId, $provideDocId) {
                        $q->where(function ($q2) use ($contractId) {
                            $q2->where('journalable_type', Contract::class)
                                ->where('journalable_id', $contractId);
                        })
                            ->orWhere(function ($q2) use ($provideDocId) {
                                $q2->where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $provideDocId);
                            });
                    })
                        ->where(function ($q) use ($clientId) {
                            $q->where(function ($q2) {
                                $q2->whereNull('debit_partner_id')
                                    ->whereNull('credit_partner_id');
                            })
                                ->orWhere('debit_partner_id', $clientId)
                                ->orWhere('credit_partner_id', $clientId);
                        })
                        ->where(function ($c) use ($contractId) {
                            $c->whereNull('contract_id')
                                ->orWhere('contract_id', $contractId);
                        });
                });
        });
    }

    /**
     * Net162 for Sheet2 (classification ids 3–4): net16200NV + net16201NI + net16200 through $asOfDate (same rules as Sheet1).
     */
    private function contractNet162AtDate(
        DocumentJournal $doc,
        Contract        $contract,
        string          $asOfDate,
                        $acc16200NV,
                        $acc16201NI,
                        $acc16200
    ): float
    {
        $portfolioAccounts = array_filter([$acc16200NV, $acc16201NI, $acc16200]);

        $net16200NVCredit = DocumentJournal::query()
            ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
                $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
            })
            ->where('credit_account_id', $acc16200NV)
            ->whereDate('date', '<=', $asOfDate)
            ->sum('amount_amd');
        $net16200NV = (float)$doc->amount_amd - (float)$net16200NVCredit;

        $net16201NI = (float)(DocumentJournal::query()
            ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
                $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
            })
            ->whereDate('date', '<=', $asOfDate)
            ->selectRaw(
                'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
                [$acc16201NI, $acc16201NI]
            )
            ->value('balance') ?? 0);

        $net16200 = 0.0;
        if ($acc16200) {
            $net16200 = (float)(DocumentJournal::query()
                ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
                    $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
                })
                ->whereDate('date', '<=', $asOfDate)
                ->selectRaw(
                    'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
                    [$acc16200, $acc16200]
                )
                ->value('balance') ?? 0);
        }

        return $net16200NV + $net16201NI + $net16200;
    }

    /**
     * Sheet2 column B for classification 5 (loss): loss expense nets debit − credit on 86000 + 86001
     * (partner-aware journal scope).
     */
    private function contractNet86000Plus86001AtDate(
        DocumentJournal $doc,
        Contract        $contract,
        string          $asOfDate,
                        $acc86000,
                        $acc86001
    ): float
    {
        $portfolioAccounts = array_filter([$acc86000, $acc86001]);

        $net86000 = 0.0;
        if ($acc86000) {
            $net86000 = (float)(DocumentJournal::query()
                ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
                    $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
                })
                ->whereDate('date', '<=', $asOfDate)
                ->selectRaw(
                    'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
                    [$acc86000, $acc86000]
                )
                ->value('balance') ?? 0);
        }

        $net86001 = 0.0;
        if ($acc86001) {
            $net86001 = (float)(DocumentJournal::query()
                ->where(function ($query) use ($contract, $doc, $portfolioAccounts) {
                    $this->applyPortfolioJournalScope($query, $contract, $doc, $portfolioAccounts);
                })
                ->whereDate('date', '<=', $asOfDate)
                ->selectRaw(
                    'SUM(CASE WHEN debit_account_id = ? THEN amount_amd ELSE 0 END) - SUM(CASE WHEN credit_account_id = ? THEN amount_amd ELSE 0 END) as balance',
                    [$acc86001, $acc86001]
                )
                ->value('balance') ?? 0);
        }

        return $net86000 + $net86001;
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
                if ($categoryName === 'category2') {
                    $q->where('category_id', 2);
                } else {
                    $q->whereHas('category', function ($q3) use ($categoryName) {
                        if ($categoryName === 'car') {
                            $q3->whereIn('name', ['car', 'car-purchase']);
                        } else {
                            $q3->where('name', $categoryName);
                        }
                    });
                }
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
            // Opening balance at period start must exclude same-day movements.
            ->whereDate('date', '<', $dateFrom)
            ->sum('amount_amd');
    }

    /**
     * Document types counted as incoming into the 86000/86001 loss accounts for Sheet2 column J:
     * off-balance incoming postings (incl. legacy spellings in DB) plus the real write-off
     * document types (principal write-off to 86000, interest write-off to 86001).
     *
     * @return array<string>
     */
    private function sheet2ColumnJDocumentTypes(): array
    {
        return array_values(array_unique([
            DocumentJournal::OFF_BALANCE_INCOMING,
            'Արտաբալանս մուտքային',
            'Արտաբալանս Մուտքային',
            DocumentJournal::LOSS_RESERVE_AMOUNT,
            DocumentJournal::LOSS_RESERVE,
        ]));
    }

    /**
     * Sheet2 columns J/L/P: sum debits or credits on given 86000-family accounts in period, by car / gold / category2.
     * Optional $documentTypes limits rows (e.g. OFF_BALANCE_INCOMING for J, loss accruals for P).
     * Category from contract_id, journalable link, comment ("contract #N"), or debit/credit partner.
     *
     * @param array<int|null> $accountIds
     * @param array<string>|null $documentTypes
     */
    private function sumSheet286000TurnoverByCategory(
        string $categoryName,
        string $amountColumn,
        string $dateFrom,
        string $dateTo,
        array  $accountIds,
        ?array $documentTypes = null
    ): float
    {
        if (!in_array($amountColumn, ['debit_account_id', 'credit_account_id'], true)) {
            return 0.0;
        }

        $accountIds = array_values(array_filter($accountIds));
        if ($accountIds === []) {
            return 0.0;
        }

        $sum = 0.0;
        $query = DocumentJournal::query()
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->whereIn($amountColumn, $accountIds);

        if ($documentTypes !== null && $documentTypes !== []) {
            $query->whereIn('document_type', $documentTypes);
        }

        $rows = $query->get();

        foreach ($rows as $row) {
            if ($this->sheet2JournalCategoryName($row) !== $categoryName) {
                continue;
            }
            $sum += (float)$row->amount_amd;
        }

        return $sum;
    }

    /**
     * Sheet2 column H: net balance (debit − credit) on given 86000-family accounts before $dateFrom,
     * split by car / gold / category2 using the same category resolution as columns J/L/P.
     *
     * @param array<int|null> $accountIds
     */
    private function sumSheet286000BalanceBeforeByCategory(
        string $categoryName,
        string $dateFrom,
        array  $accountIds
    ): float
    {
        $accountIds = array_values(array_filter($accountIds));
        if ($accountIds === []) {
            return 0.0;
        }

        $rows = DocumentJournal::query()
            ->whereDate('date', '<', $dateFrom)
            ->where(function ($q) use ($accountIds) {
                $q->whereIn('debit_account_id', $accountIds)
                    ->orWhereIn('credit_account_id', $accountIds);
            })
            ->get();

        $sum = 0.0;
        foreach ($rows as $row) {
            if ($this->sheet2JournalCategoryName($row) !== $categoryName) {
                continue;
            }
            if (in_array($row->debit_account_id, $accountIds, true)) {
                $sum += (float)$row->amount_amd;
            }
            if (in_array($row->credit_account_id, $accountIds, true)) {
                $sum -= (float)$row->amount_amd;
            }
        }

        return $sum;
    }

    /**
     * Resolve car / gold / category2 for an 86000/86001 journal row.
     */
    private function sheet2JournalCategoryName(DocumentJournal $row): ?string
    {
        if (preg_match('/contract #(\d+)/i', (string)$row->comment, $matches)) {
            $fromComment = $this->sheet2CategoryNameForContractId((int)$matches[1]);
            if ($fromComment !== null) {
                return $fromComment;
            }
        }

        if ($row->contract_id) {
            $fromContractId = $this->sheet2CategoryNameForContractId((int)$row->contract_id);
            if ($fromContractId !== null) {
                return $fromContractId;
            }
        }

        if ($row->journalable_type === Contract::class && $row->journalable_id) {
            $fromJournalable = $this->sheet2CategoryNameForContractId((int)$row->journalable_id);
            if ($fromJournalable !== null) {
                return $fromJournalable;
            }
        }

        if ($row->journalable_type === DocumentJournal::class && $row->journalable_id) {
            $parent = DocumentJournal::query()->find($row->journalable_id);
            if ($parent && $parent->journalable_type === Contract::class && $parent->journalable_id) {
                $fromParent = $this->sheet2CategoryNameForContractId((int)$parent->journalable_id);
                if ($fromParent !== null) {
                    return $fromParent;
                }
            }
        }

        $partnerId = (int)($row->debit_partner_id ?: $row->credit_partner_id ?: 0);
        if ($partnerId > 0) {
            $contractIds = Contract::query()
                ->where('client_id', $partnerId)
                ->pluck('id');
            foreach ($contractIds as $contractId) {
                $fromPartner = $this->sheet2CategoryNameForContractId((int)$contractId);
                if ($fromPartner !== null) {
                    return $fromPartner;
                }
            }
        }

        return null;
    }

    /**
     * @return 'car'|'gold'|'category2'|null
     */
    private function sheet2CategoryNameForContractId(int $contractId): ?string
    {
        static $cache = [];

        if (array_key_exists($contractId, $cache)) {
            return $cache[$contractId];
        }

        $contract = Contract::query()->with('category')->find($contractId);
        if (!$contract) {
            return $cache[$contractId] = null;
        }

        if ((int)$contract->category_id === 2) {
            return $cache[$contractId] = 'category2';
        }

        $name = $contract->category?->name;
        if (in_array($name, ['car', 'car-purchase'], true)) {
            return $cache[$contractId] = 'car';
        }
        if ($name === 'gold') {
            return $cache[$contractId] = 'gold';
        }

        return $cache[$contractId] = null;
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
