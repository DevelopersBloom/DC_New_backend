<?php

namespace App\Exports\Reports;

use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Services\Reports\OverdueScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Form 6 with the corrected "1.3 Overdue assets" row.
 *
 * Everything is produced by the legacy export first (same template, Sheet2, classification rows);
 * this class then rewrites only the cells that depend on what is overdue:
 *
 *  - rows 21/22  B..L  overdue principal per instalment, in the bucket of its days overdue
 *                P     overdue principal + overdue interest incl. unpaid penalties (16200 stays in 1.1)
 *                R     contracts with at least one overdue instalment
 *  - rows 15/16  B..L  the rest of each contract's principal, still in the legacy final-deadline bucket
 *                P, R  total less the overdue part
 *  - rows 108, 110, 112, 113  B..L  same overdue / remainder split; P gets the residual balances of
 *                contracts the legacy loop leaves out (e.g. closed contracts still carrying interest)
 *
 * N and row 14 stay template formulas. Values are written rounded to 3 decimals (thousands of AMD),
 * and the remainder is the rounded total minus the rounded overdue part, so row 14 equals the ledger.
 */
class V06ExportV2 extends V06Export
{
    public const VERSION = 'v2';

    /** @var array<int, array{nv:float,ni:float,adj:float,col:string,car:bool,gold:bool,cat2:bool}> */
    protected array $ledger = [];

    protected function recordContractLedger(Contract $contract, string $col, float $nv, float $ni, float $adjustment): void
    {
        $category = $contract->category;
        $entry = &$this->ledger[$contract->id];
        $entry = [
            'nv' => ($entry['nv'] ?? 0.0) + $nv,
            'ni' => ($entry['ni'] ?? 0.0) + $ni,
            'adj' => ($entry['adj'] ?? 0.0) + $adjustment,
            'col' => $col,
            'car' => $category && in_array($category->name, ['car', 'car-purchase'], true),
            'gold' => $category && $category->name === 'gold',
            'cat2' => (int) $contract->category_id === 2,
        ];
    }

    public function buildSpreadsheet($from, $to): Spreadsheet
    {
        $this->ledger = [];
        $spreadsheet = parent::buildSpreadsheet($from, $to);
        $date = Carbon::parse($to)->format('Y-m-d');

        $this->applyOverdueSplit($spreadsheet, $date);

        $spreadsheet->getProperties()
            ->setDescription('Form 6, logic ' . self::VERSION . ' (overdue assets from payment entries as of the report date)')
            ->setKeywords('V06 logic=' . self::VERSION);

        return $spreadsheet;
    }

    protected function applyOverdueSplit(Spreadsheet $spreadsheet, string $date): void
    {
        $sheet = $spreadsheet->getSheetByName('Sheet1');
        $service = new OverdueScheduleService();
        $cols = ['B', 'D', 'F', 'H', 'J', 'L'];
        $zero = array_fill_keys($cols, 0.0);

        $overdueByContract = $service->overdueAtDate(array_keys($this->ledger), $date, $settledWithGap, $assumedPaid);
        $sinceByContract = array_map(fn ($rows) => $rows[0]['due_date'], $overdueByContract);
        $penalties = $service->unpaidPenaltiesAtDate($sinceByContract, $date);

        $overdue = $zero;                // overdue principal by days-overdue bucket
        $remainder = $zero;              // remaining principal by legacy bucket
        $rows = ['car' => [$zero, $zero], 'gold' => [$zero, $zero], 'cat2' => [$zero, $zero]];   // [overdue, remainder]
        $overdueGross = 0.0;
        $totalGross = 0.0;
        $overdueCount = 0;
        $overdueIds = [];
        $capped = [];

        foreach ($this->ledger as $contractId => $c) {
            $totalGross += $c['nv'] + $c['ni'] + $c['adj'];
            $rec = isset($overdueByContract[$contractId])
                ? OverdueScheduleService::reconcile($overdueByContract[$contractId], $c['nv'], $c['ni'], $c['adj'], $penalties[$contractId] ?? 0.0)
                : null;
            $isOverdue = $rec && $rec['overdue'];

            $contractOverdue = $zero;
            if ($isOverdue) {
                $overdueCount++;
                $overdueIds[] = $contractId;
                $overdueGross += $rec['gross'];
                foreach ($rec['instalments'] as $i) {
                    $contractOverdue[OverdueScheduleService::columnForDays($i['days_overdue'])] += $i['principal'];
                }
                if ($rec['capped']) {
                    $capped[$contractId] = [
                        'schedule_principal' => round($rec['schedule_principal'], 2),
                        'ledger_principal' => round($c['nv'], 2),
                    ];
                }
            }

            $rest = $c['nv'] - ($isOverdue ? $rec['principal'] : 0.0);
            $remainder[$c['col']] += $rest;
            foreach ($cols as $col) {
                $overdue[$col] += $contractOverdue[$col];
            }
            foreach (['car', 'gold', 'cat2'] as $key) {
                if ($c[$key]) {
                    $rows[$key][1][$c['col']] += $rest;
                    foreach ($cols as $col) {
                        $rows[$key][0][$col] += $contractOverdue[$col];
                    }
                }
            }
        }

        $thousands = fn (float $amd): float => round($amd / 1000, 3);
        $put = function (string $cell, float|int $value) use ($sheet) {
            $sheet->setCellValue($cell, $value);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0');
        };

        // Contracts the legacy loop leaves out still carry balances in the ledger (a closed contract
        // with interest or penalties left); they belong in the gross of the term row.
        [$residualTotal, $residualByRow] = $this->unprocessedResiduals($date);

        foreach ($cols as $col) {
            // Column total of all contracts (term + overdue) rounded once; row 15 is that total
            // minus the rounded row 21, so rows 15 + 21 add up to row 108 and to the ledger exactly.
            $columnAmd = $remainder[$col] + $overdue[$col];
            $categoriesAmd = 0.0;
            foreach (['car', 'gold', 'cat2'] as $key) {
                $categoriesAmd += $rows[$key][0][$col] + $rows[$key][1][$col];
            }
            $total = $thousands(abs($categoriesAmd - $columnAmd) < 1 ? $columnAmd : $categoriesAmd);

            $row21 = $thousands($overdue[$col]);
            $row15 = round($total - $row21, 3);
            foreach ([21, 22] as $r) {
                $put($col . $r, $row21);
            }
            foreach ([15, 16] as $r) {
                $put($col . $r, $row15);
            }
            foreach ([110 => 'car', 112 => 'gold', 113 => 'cat2'] as $r => $key) {
                $put($col . $r, $thousands($rows[$key][0][$col] + $rows[$key][1][$col]));
            }
            $put($col . '108', $total);
        }

        $grossOverdue = $thousands($overdueGross);
        $grossTotal = $thousands($totalGross + $residualTotal);
        foreach ([21, 22] as $r) {
            $put('P' . $r, $grossOverdue);
            $put('R' . $r, $overdueCount);
        }
        foreach ([15, 16] as $r) {
            $put('P' . $r, round($grossTotal - $grossOverdue, 3));
            $put('R' . $r, count($this->ledger) - $overdueCount);
        }
        foreach ([108 => null, 110 => 'car', 112 => 'gold', 113 => 'cat2'] as $r => $key) {
            $add = $key === null ? $residualTotal : ($residualByRow[$key] ?? 0.0);
            if (abs($add) > 0.005) {
                $put('P' . $r, round((float) $sheet->getCell('P' . $r)->getValue() + $thousands($add), 3));
            }
        }

        Log::info('V06 v2 report', [
            'date' => $date,
            'contracts' => count($this->ledger),
            'overdue_contracts' => $overdueCount,
            'overdue_principal' => round(array_sum($overdue), 2),
            'residual_gross_of_unprocessed_contracts' => round($residualTotal, 2),
            'principal_capped_at_ledger' => $capped,
            'settled_with_gap' => $settledWithGap,
            'assumed_paid_without_entries' => $assumedPaid,
            'entries_vs_ledger_disagreements' => $this->crossCheck($service, $overdueIds, $date),
        ]);
    }

    /**
     * Ledger gross (16200NV + 16201NI + 16200) of contracts with a disbursement on or before $date
     * that the legacy loop did not include.
     *
     * @return array{0: float, 1: array<string, float>} total, and the part by category row (car/gold/cat2)
     */
    protected function unprocessedResiduals(string $date): array
    {
        $accounts = array_filter([
            ChartOfAccount::idByCode('16200NV'),
            ChartOfAccount::idByCode('16201NI'),
            ChartOfAccount::idByCode('16200'),
        ]);
        if (count($accounts) < 3) {
            return [0.0, []];
        }

        $contractIds = DocumentJournal::where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->where('journalable_type', Contract::class)
            ->whereDate('date', '<=', $date)
            ->pluck('journalable_id')
            ->unique()
            ->diff(array_keys($this->ledger))
            ->values()
            ->all();
        if (!$contractIds) {
            return [0.0, []];
        }

        $in = implode(',', array_map('intval', $accounts));
        $rows = DB::table('documents_journal as j')
            ->leftJoin('documents_journal as parent', function ($join) {
                $join->on('parent.id', '=', 'j.journalable_id')
                    ->where('j.journalable_type', '=', DocumentJournal::class);
            })
            ->whereNull('j.deleted_at')
            ->whereDate('j.date', '<=', $date)
            ->where(fn ($q) => $q->whereIn('j.debit_account_id', $accounts)->orWhereIn('j.credit_account_id', $accounts))
            ->selectRaw(
                "COALESCE(j.contract_id,
                    CASE WHEN j.journalable_type = ? THEN j.journalable_id END,
                    parent.contract_id,
                    CASE WHEN parent.journalable_type = ? THEN parent.journalable_id END) as cid,
                 (CASE WHEN j.debit_account_id IN ($in) THEN j.amount_amd ELSE 0 END)
                 - (CASE WHEN j.credit_account_id IN ($in) THEN j.amount_amd ELSE 0 END) as net",
                [Contract::class, Contract::class]
            );

        $sums = DB::query()->fromSub($rows, 'x')
            ->whereIn('cid', $contractIds)
            ->groupBy('cid')
            ->selectRaw('cid, SUM(net) as net')
            ->pluck('net', 'cid');

        $categories = Contract::with('category')->whereIn('id', $sums->keys())->get()->keyBy('id');
        $total = 0.0;
        $byRow = ['car' => 0.0, 'gold' => 0.0, 'cat2' => 0.0];
        foreach ($sums as $cid => $net) {
            $net = (float) $net;
            $total += $net;
            $contract = $categories[$cid] ?? null;
            if (!$contract) {
                continue;
            }
            $name = $contract->category->name ?? null;
            if (in_array($name, ['car', 'car-purchase'], true)) {
                $byRow['car'] += $net;
            }
            if ($name === 'gold') {
                $byRow['gold'] += $net;
            }
            if ((int) $contract->category_id === 2) {
                $byRow['cat2'] += $net;
            }
        }

        return [$total, $byRow];
    }

    /**
     * Contracts that are overdue by one method and not by the other (payment entries vs ledger
     * repayments), for the accountant. Informational only; the report uses the entries.
     */
    protected function crossCheck(OverdueScheduleService $service, array $entriesOverdueIds, string $date): array
    {
        $ledgerOverdue = $service->ledgerOverdueAtDate(array_keys($this->ledger), $date);

        return [
            'overdue_by_entries_only' => array_values(array_diff($entriesOverdueIds, array_keys($ledgerOverdue))),
            'overdue_by_ledger_only' => array_values(array_diff(array_keys($ledgerOverdue), $entriesOverdueIds)),
        ];
    }
}
