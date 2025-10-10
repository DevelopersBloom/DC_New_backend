<?php

namespace App\Http\Controllers;

use App\Traits\CalculatesAccountBalancesTrait;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;

class ReportV01Controller extends Controller
{
    use CalculatesAccountBalancesTrait;

    protected ?string $to;
    protected array $summary = [];

    public function __construct(?string $to = null)
    {
        $this->to = $to;
        $this->summary = [];
    }

    public function __invoke(Request $request): Response|BinaryFileResponse
    {
        // $toStr = $request->query('to', $this->to);
        $toStr = "2025-12-10";
        if (!$toStr) {
            return response()->json(['message' => 'Provide ?to=YYYY-MM-DD'], 422);
        }

        // 1) Տվյալներ
        // հենց տվյալների ստանալուց հետո
        $rawRows  = $this->balancesRowsQuery($toStr)->get();
        $rows     = $this->transformToReport1($rawRows)->values();
        $this->summary = $this->balancesSummary($toStr) ?? [];

        $sheetInfo = [
            "TO={$toStr}",
            "RAW_COUNT=" . $rawRows->count(),
            "ROWS_COUNT=" . $rows->count(),
        ];


        // 2) Template (.xls)
        $templatePath = base_path('v01.xls'); // հարմարեցրու ըստ քո տեղադրության
        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsReader();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);

        // ✅ ընտրում ենք աշխատաշիթը՝ նախ փորձելով Sheet1, հետո 0-րդը
        $sheet = $spreadsheet->getSheetByName('Sheet1') ?: $spreadsheet->getSheet(0);
        $spreadsheet->setActiveSheetIndex($sheet->getParent()->getIndex($sheet));

        // 🧹 Անջատենք merge-երը տվյալների զոնայում՝ A8:Q10000
        foreach ($sheet->getMergeCells() as $mergedRange) {
            if ($this->rangesOverlap($mergedRange, 'A8:Q10000')) {
                $sheet->unmergeCells(str_replace('$', '', $mergedRange));
            }
        }

        // 🔎 Smoke test — որ հասկանանք՝ գրելու/շիթի/ֆայլի մասով ամեն ինչ OK է
        $sheet->setCellValue('A1', 'HELLO!');
        $sheet->setCellValue('B2', date('Y-m-d H:i:s'));
        $sheet->setCellValue('C3', 12345);
        $sheet->setCellValue('A5', $sheetInfo[0]);
        $sheet->setCellValue('A6', $sheetInfo[1]);
        $sheet->setCellValue('A7', $sheetInfo[2]);

        // 3) Գրելու սկիզբ
        $startRow   = 8;
        $currentRow = $startRow;

        if ($rows->isEmpty()) {
            // ⛳ եթե տվյալ չկա, placeholder
            $sheet->setCellValueExplicit("A{$currentRow}", 'NO DATA', DataType::TYPE_STRING);
        } else {
            foreach ($rows as $row) {
                // A (1): code
                $sheet->setCellValueExplicitByColumnAndRow(1, $currentRow, (string)$row->code, DataType::TYPE_STRING);
                // B (2): name
                $sheet->setCellValueExplicitByColumnAndRow(2, $currentRow, (string)($row->name ?? ''), DataType::TYPE_STRING);

                // ❌ Չենք դիպչում C(3), D(4), E(5)

                // ✅ Գրենք F..Q (6..17)
                $nums = [
                    6  => (float)($row->amd_resident ?? 0),
                    7  => (float)($row->amd_non_resident ?? 0),
                    8  => (float)($row->fx_group1_resident ?? 0),
                    9  => (float)($row->fx_group1_non_resident ?? 0),
                    10 => (float)($row->usd_resident ?? 0),
                    11 => (float)($row->usd_non_resident ?? 0),
                    12 => (float)($row->eur_resident ?? 0),
                    13 => (float)($row->eur_non_resident ?? 0),
                    14 => (float)($row->fx_group2_resident ?? 0),
                    15 => (float)($row->fx_group2_non_resident ?? 0),
                    16 => (float)($row->rub_resident ?? 0),
                    17 => (float)($row->rub_non_resident ?? 0),
                ];

                foreach ($nums as $colIndex => $val) {
                    // XLS-ում երբեմն Explicit NUMERIC-ը «անտեսվում» է format-ի պատճառով,
                    // բայց սա ճիշտ է գրառում է անում՝ արժեքը իրական թիվ է պահում:
                    $sheet->setCellValueExplicitByColumnAndRow($colIndex, $currentRow, $val, DataType::TYPE_NUMERIC);
                }

                $currentRow++;
            }
        }

        // 4) Ամփոփում
        $labels = ['Ակտիվներ','Պարտավորություններ','Կապիտալ','Հաշվեկշիռ'];
        $values = [
            $this->summary['Ակտիվներ'] ?? 0,
            $this->summary['Պարտավորություններ'] ?? 0,
            $this->summary['Կապիտալ'] ?? 0,
            $this->summary['Հաշվեկշիռ'] ?? ($this->summary['Հաշվեշիռ'] ?? 0),
        ];
        foreach ($labels as $i => $label) {
            $r = 2 + $i; // rows 2..5
            $sheet->setCellValue("S{$r}", $label);
            $sheet->setCellValueExplicit("T{$r}", (float)$values[$i], DataType::TYPE_NUMERIC);
            $sheet->getStyle("T{$r}")->getNumberFormat()->setFormatCode('#,##0');
        }

        // 5) Պահպանում (.xls)
        $writer = new XlsWriter($spreadsheet);
        // Հին XLS-ում ֆորմուլաների precalc-ը հաճախ «ծանրացնում» է. անջատենք
        $writer->setPreCalculateFormulas(false);

        $dir = storage_path('app/reports');
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

        $filename = 'base_pats_v01_OUT.xls';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        // Header/Output buffering cleanup — կարևոր է download-ի համար
        while (ob_get_level() > 0) { @ob_end_clean(); }

        $writer->save($path);

        // ⛳ ցանկության դեպքում՝ sanity log
        // \Log::info('Report saved', ['path' => $path, 'size' => @filesize($path)]);

        return response()->download($path, $filename, [
            'Content-Type'  => 'application/vnd.ms-excel',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'public',
        ])->deleteFileAfterSend(true);
    }

    // ✔️ Օգնիչ՝ պարզելու համար՝ overlap կա՞ data-range-ի հետ
    protected function rangesOverlap(string $r1, string $r2): bool
    {
        [$s1, $e1] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries(str_replace('$', '', $r1));
        [$s2, $e2] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries(str_replace('$', '', $r2));

        return !(
            $e1[0] < $s2[0] || $e2[0] < $s1[0] ||  // columns disjoint
            $e1[1] < $s2[1] || $e2[1] < $s1[1]     // rows disjoint
        );
    }

    /** քո helpers — նույնը, ինչ առաջ էր */
    protected function transformToReport1($rows)
    {
        $sumFields = [
            'total_resident','total_non_resident',
            'amd_resident','amd_non_resident',
            'fx_group1_resident','fx_group1_non_resident',
            'usd_resident','usd_non_resident',
            'eur_resident','eur_non_resident',
            'fx_group2_resident','fx_group2_non_resident',
            'rub_resident','rub_non_resident',
        ];

        $lettered = $rows->filter(fn($r) => (bool)preg_match('/^\d{5}[A-Za-z]+$/', (string)$r->code));
        $grouped  = $rows->groupBy(fn($r) => preg_match('/^\d{5}/', (string)$r->code, $m) ? $m[0] : (string)$r->code);

        $baseAggregates = $grouped->map(function ($group, $base5) use ($sumFields) {
            $exact = $group->first(fn($x) => (string)$x->code === $base5);
            $name  = $exact->name
                ?? optional($group->sortBy(fn($x) => strlen((string)$x->code))->first())->name
                ?? $base5;

            $agg = ['code' => $base5, 'name' => $name];
            foreach ($sumFields as $f) {
                $agg[$f] = (float)$group->sum(fn($x) => (float)($x->{$f} ?? 0));
            }
            return (object)$agg;
        });

        $letteredNormalized = $lettered->map(function ($r) use ($sumFields) {
            foreach ($sumFields as $f) { $r->{$f} = (float)($r->{$f} ?? 0); }
            $r->code = (string)$r->code;
            $r->name = (string)($r->name ?? $r->code);
            return $r;
        });

        return $baseAggregates->values()
            ->merge($letteredNormalized->values())
            ->sortBy('code')
            ->values();
    }
}
