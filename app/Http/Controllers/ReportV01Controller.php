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

        $rawRows  = $this->balancesRowsQuery($toStr)->get();
        $rows     = $this->transformToReport1($rawRows)->values();
        $this->summary = $this->balancesSummary($toStr) ?? [];


        $templatePath = base_path('v01.xls');
        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsReader();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);

        $sheet = $spreadsheet->getSheetByName('Sheet1') ?: $spreadsheet->getSheet(0);
        $spreadsheet->setActiveSheetIndex($sheet->getParent()->getIndex($sheet));

        foreach ($sheet->getMergeCells() as $mergedRange) {
            if ($this->rangesOverlap($mergedRange, 'A8:Q10000')) {
                $sheet->unmergeCells(str_replace('$', '', $mergedRange));
            }
        }


        $startRow   = 8;
        $currentRow = $startRow;

        if ($rows->isEmpty()) {
            $sheet->setCellValueExplicit("A{$currentRow}", 'NO DATA', DataType::TYPE_STRING);
        } else {
            foreach ($rows as $row) {
                $sheet->setCellValueExplicitByColumnAndRow(
                    1, $currentRow, (string)$row->code, DataType::TYPE_STRING
                );

                $name = isset($row->name) && trim((string)$row->name) !== '' ? (string)$row->name : '';
                $sheet->setCellValueExplicitByColumnAndRow(
                    2, $currentRow, $name, DataType::TYPE_STRING
                );
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
                    $sheet->setCellValueExplicitByColumnAndRow($colIndex, $currentRow, $val, DataType::TYPE_NUMERIC);
                }

                $currentRow++;
            }
        }

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

        $writer = new XlsWriter($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $dir = storage_path('app/reports');
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

        $filename = 'base_pats_v01_OUT.xls';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        while (ob_get_level() > 0) { @ob_end_clean(); }

        $writer->save($path);


        return response()->download($path, $filename, [
            'Content-Type'  => 'application/vnd.ms-excel',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'public',
        ])->deleteFileAfterSend(true);
    }

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
