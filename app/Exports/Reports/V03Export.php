<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\ClassificationHistory;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class V03Export
{
    public function export($from, $to)
    {
        // Load XLSX template
        $path = base_path('v03.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        // ---------------------------
        // SHEET 1
        // ---------------------------
        $sheet1 = $spreadsheet->getSheetByName('Sheet1');
        $sheet1->setCellValueExplicit('D10', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);
        $sheet1->setCellValue('D11', ExcelDate::PHPToExcel(Carbon::parse($from)->toDateTime()));
        $sheet1->setCellValue('F11', ExcelDate::PHPToExcel(Carbon::parse($to)->toDateTime()));

        // D16: INT(((highest client total provided - that client total reserve) / 1000) * risk_weight)
        $sheet1->setCellValue('D16', $this->computeD16Value($from, $to));

        // ---------------------------
        // SHEET 2
        // ---------------------------
        $sheet2 = $spreadsheet->getSheetByName('Sheet2');
        $sheet2->setCellValue('C3', ExcelDate::PHPToExcel(Carbon::parse($from)->toDateTime()));
        $sheet2->setCellValue('E3', ExcelDate::PHPToExcel(Carbon::parse($to)->toDateTime()));

        $acc50000 = ChartOfAccount::idByCode('50000');
        $acc52000 = ChartOfAccount::idByCode('52000');
        $acc52001 = ChartOfAccount::idByCode('52001');

        $class6Ids = ChartOfAccount::where('code', 'like', '6%')->pluck('id')->toArray();
        $class7Ids = ChartOfAccount::where('code', 'like', '7%')->pluck('id')->toArray();

        $startDay = Carbon::parse($from)->day;
        $row = 9 + ($startDay - 1);

        $current = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($current->lte($end)) {
            $balance50000 = DocumentJournal::where('debit_account_id', $acc50000)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc50000)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');
            $sum6 = DocumentJournal::whereIn('credit_account_id', $class6Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('debit_account_id', $class6Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');

            $sum7 = DocumentJournal::whereIn('debit_account_id', $class7Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd')
                - DocumentJournal::whereIn('credit_account_id', $class7Ids)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');
            $balance52000 = $sum6 - $sum7;
//            $balance52000 = DocumentJournal::where('debit_account_id', $acc52000)
//                    ->where('date', '<=', $current)
//                    ->sum('amount_amd') * -1
//                + DocumentJournal::where('credit_account_id', $acc52000)
//                    ->where('date', '<=', $current)
//                    ->sum('amount_amd');

            $balance52001 = DocumentJournal::where('debit_account_id', $acc52001)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd') * -1
                + DocumentJournal::where('credit_account_id', $acc52001)
                    ->where('date', '<=', $current)
                    ->sum('amount_amd');

            $finalSum = $balance50000 + $balance52000 + $balance52001;

            $sheet2->setCellValue('B' . $row, $finalSum / 1000);

            $current->addDay();
            $row++;
        }

        // ---------------------------
        // SHEET 3
        // ---------------------------
        $sheet3 = $spreadsheet->getSheetByName('Sheet3');

        $riskColumns = [
            0   => 'B', 10  => 'D', 20  => 'F', 30  => 'H',
            50  => 'J', 75  => 'L', 100 => 'N', 110 => 'P',
            150 => 'R', 225 => 'T',
        ];

        $activeAccountsWithRisk = ChartOfAccount::where('type', 'active')
            ->whereNotNull('risk_weight')
            ->get();

        $acc2100Id = ChartOfAccount::idByCode('2100');
        $acc2101Id = ChartOfAccount::idByCode('2101');
        $acc2200Id = ChartOfAccount::idByCode('2200');
        $acc2211Id = ChartOfAccount::idByCode('2211');

        $current = Carbon::parse($from);
        $end = Carbon::parse($to);
        $row = 8 + ($current->day - 1);

        while ($current->lte($end)) {
            $dailyData = [];
            foreach ($riskColumns as $risk => $col) {
                $dailyData[$risk] = ['amount' => 0, 'reserve' => 0];
            }

            foreach ($activeAccountsWithRisk as $account) {
                $accId = $account->id;
                $riskKey = (int) $account->risk_weight;
                if (!isset($riskColumns[$riskKey])) continue;

                $balance = DocumentJournal::where('debit_account_id', $accId)
                        ->where('date', '<=', $current)
                        ->sum('amount_amd')
                    - DocumentJournal::where('credit_account_id', $accId)
                        ->where('date', '<=', $current)
                        ->sum('amount_amd');

                if ($accId == $acc2100Id) {
                    $subBalance = DocumentJournal::where('debit_account_id', $acc2101Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd')
                        - DocumentJournal::where('credit_account_id', $acc2101Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd');
                    $balance -= $subBalance;
                }

                if ($accId == $acc2200Id) {
                    $subBalance = DocumentJournal::where('debit_account_id', $acc2211Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd')
                        - DocumentJournal::where('credit_account_id', $acc2211Id)
                            ->where('date', '<=', $current)
                            ->sum('amount_amd');
                    $balance -= $subBalance;
                }

                $dailyData[$riskKey]['amount'] += $balance;
                $dailyData[$riskKey]['reserve'] += ($balance * 0.01);
            }

//            $journals = DocumentJournal::with(['journalable.client.classification'])
//                ->where('date', '<=', $current->format('Y-m-d'))
//                ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//                ->get();
////
//            foreach ($journals as $j) {
//                if ($j->journalable->status != 'initial') continue;
//                $client = optional($j->journalable)->client;
//                if (!$client) continue;
//
//                $classification = ClassificationHistory::where('client_id', $client->id)
//                    ->where('date', '<=', $current->format('Y-m-d'))
//                    ->orderBy('date', 'desc')
//                    ->first() ?: $client->classification;
//                //$classification = optional(optional($j->journalable)->client)->classification;
//                if ($classification && isset($riskColumns[(int)$classification->risk_weight])) {
//                    $riskKey = (int)$classification->risk_weight;
//                    $amount = $j->amount_amd;
//
//                    $dailyData[$riskKey]['amount'] += $amount;
//
//                    $reservePercent = $classification->reserve_percent ?? 0;
//                    $dailyData[$riskKey]['reserve'] += ($amount * $reservePercent / 100);
//                }
//            }
            $acc16Ids = ChartOfAccount::where('code', 'like', '16%')
                ->pluck('id')
                ->toArray();

            $debit = DB::table('transactions as t')
                ->join('chart_of_accounts as a', 'a.id', '=', 't.debit_account_id')
                ->whereNull('t.deleted_at')
                ->whereNotNull('t.debit_partner_id')
                ->whereIn('t.debit_account_id', $acc16Ids)
                ->whereDate('t.date', '<=', $current->format('Y-m-d'))
                ->selectRaw("
                    t.debit_partner_id as partner_id,
                    SUM(
                        CASE
                            WHEN a.type IN ('active','expense','off_balance') THEN t.amount_amd
                            ELSE -t.amount_amd
                        END
                    ) as amount
                ")
                ->groupBy('t.debit_partner_id');

            $credit = DB::table('transactions as t')
                ->join('chart_of_accounts as a', 'a.id', '=', 't.credit_account_id')
                ->whereNull('t.deleted_at')
                ->whereNotNull('t.credit_partner_id')
                ->whereIn('t.credit_account_id', $acc16Ids)
                ->whereDate('t.date', '<=', $current->format('Y-m-d'))
                ->selectRaw("
                    t.credit_partner_id as partner_id,
                    SUM(
                        CASE
                            WHEN a.type IN ('active','expense','off_balance') THEN -t.amount_amd
                            ELSE t.amount_amd
                        END
                    ) as amount
                ")
                ->groupBy('t.credit_partner_id');

            $partnerBalances = DB::query()
                ->fromSub($debit->unionAll($credit), 'x')
                ->selectRaw("
                    partner_id,
                    SUM(amount) as balance
                ")
                ->groupBy('partner_id')
                ->having('balance', '>', 0)
                ->get()
                ->keyBy('partner_id');
            foreach ($partnerBalances as $partnerData) {
                $partnerId = $partnerData->partner_id;
                if (!$partnerId) continue;

                $classification = ClassificationHistory::where('client_id', $partnerId)
                    ->where('date', '<=', $current->format('Y-m-d'))
                    ->orderBy('date', 'desc')
                    ->first();

                if (!$classification) {
                    $client = \App\Models\Client::find($partnerId);
                    $classification = $client?->classification;
                }

                if (!$classification) continue;

                $riskKey = (int) $classification->risk_weight;
                if (!isset($riskColumns[$riskKey])) continue;

                $amount = (float) $partnerData->balance;
                $reservePercent = (float) ($classification->reserve_percent ?? 0);

                $dailyData[$riskKey]['amount'] += $amount;
                $dailyData[$riskKey]['reserve'] += ($amount * $reservePercent / 100);
            }
dd($dailyData);
            foreach ($dailyData as $risk => $values) {
                $col = $riskColumns[$risk];
                $sheet3->setCellValue($col . $row, $values['amount'] / 1000);
                $nextCol = ++$col;
                $sheet3->setCellValue($nextCol . $row, $values['reserve'] / 1000);
            }

            $current->addDay();
            $row++;
        }
        // ---------------------------
        // SHEET 6
        // ---------------------------
        $sheet6 = $spreadsheet->getSheetByName('Sheet6');
        $sheet6->setCellValue('E10', '=IF(D10<=4,3,IF(D10=5,3.4,IF(D10=6,3.5,IF(D10=7,3.65,IF(D10=8,3.75,IF(D10=9,3.85,4))))))');

        // ---------------------------
        // SAVE XLS
        // ---------------------------
        $fileName = 'v03_export_' . now()->format('Ymd_His') . '.xlsx';
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    /**
     * D16 formula: client with highest total provided → INT(((provided - reserve) / 1000) * risk_weight).
     * Uses PROVIDE_CONTRACT_AMOUNT journals in date range; status = initial; reserve = amount * classification.reserve_percent/100.
     */
    private function computeD16Value(string $from, string $to): int
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $journals = DocumentJournal::with(['journalable.client.classification'])
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->whereBetween('date', [$start, $end])
            ->get();

        $byClient = [];
        foreach ($journals as $j) {
            $contract = $j->journalable;
            if (!$contract || $contract->status !== 'initial') {
                continue;
            }
            $clientId = $contract->client_id;
            if (!$clientId) {
                continue;
            }
            $classification = optional($contract->client)->classification;
            $reservePercent = $classification ? (float)($classification->reserve_percent ?? 0) : 0;
            $riskWeight = $classification ? (float)($classification->risk_weight ?? 0) : 0;

            if (!isset($byClient[$clientId])) {
                $byClient[$clientId] = ['provided' => 0, 'reserve' => 0, 'risk_weight' => $riskWeight];
            }
            $amount = (float) $j->amount_amd;
            $byClient[$clientId]['provided'] += $amount;
            $byClient[$clientId]['reserve'] += $amount * $reservePercent / 100;
        }

        $maxProvided = 0;
        $best = null;
        foreach ($byClient as $data) {
            if ($data['provided'] > $maxProvided) {
                $maxProvided = $data['provided'];
                $best = $data;
            }
        }

        if (!$best) {
            return 0;
        }

        $provided = $best['provided'];
        $reserve = $best['reserve'];
        $riskWeight = $best['risk_weight'] / 100; // e.g. 75 → 0.75

        return (int) round((($provided - $reserve) / 1000) * $riskWeight);
    }
}
