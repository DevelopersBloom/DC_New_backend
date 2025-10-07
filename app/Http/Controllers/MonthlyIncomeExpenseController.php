<?php
//namespace App\Http\Controllers;
//
//use App\Services\IncomeExpenseMonthlyReport;
//use Illuminate\Http\Request;
//use Symfony\Component\HttpFoundation\Response;
//use Symfony\Component\HttpFoundation\BinaryFileResponse;
//use PhpOffice\PhpSpreadsheet\Cell\DataType;
//use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
//use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
//use Carbon\Carbon;
//
//class MonthlyIncomeExpenseController extends Controller
//{
//    public function __construct(private IncomeExpenseMonthlyReport $svc)
//    {
//    }
//    public function __invoke(Request $request): Response|BinaryFileResponse
//    {
////        $month = $request->query('month');
////        if (!$month) {
////            return response()->json(['message' => 'Provide ?month=YYYY-MM'], 422);
////        }
////
////        try {
////            [$from, $to] = $this->monthRange($month);
////        } catch (\Throwable $e) {
////            return response()->json(['message' => 'Invalid month format. Use YYYY-MM'], 422);
////        }
////
////        [$prevFrom, $prevTo] = $this->previousMonthRangeFrom($from);
////
////        $current = $this->svc->build($from, $to);
////        $previous = $this->svc->build($prevFrom, $prevTo);
//        $fromStr = $request->query('from');
//        $toStr   = $request->query('to');
//
//        if (!$fromStr || !$toStr) {
//            return response()->json(['message' => 'Provide ?from=YYYY-MM-DD&to=YYYY-MM-DD'], 422);
//        }
//
//        try {
//            $from = Carbon::createFromFormat('Y-m-d', $fromStr)->startOfDay();
//            $to   = Carbon::createFromFormat('Y-m-d', $toStr)->endOfDay();
//        } catch (\Throwable $e) {
//            return response()->json(['message' => 'Invalid date format. Use YYYY-MM-DD'], 422);
//        }
//
//        if ($from->gt($to)) {
//            return response()->json(['message' => '`from` must be <= `to`'], 422);
//        }
//
//        $daysInclusive = $to->copy()->startOfDay()->diffInDays($from->copy()->startOfDay()) + 1;
//
//        $prevTo = $from->copy()->subDay()->endOfDay();
//        $prevFrom = $prevTo->copy()->subDays($daysInclusive - 1)->startOfDay();
//
//        $current = $this->svc->build($from, $to);
//        $previous = $this->svc->build($prevFrom, $prevTo);
//        $currBy = [];
//        foreach ($current as $r) {
//            $currBy[(string)$r['code']] = $r;
//        }
//        $prevBy = [];
//        foreach ($previous as $r) {
//            $prevBy[(string)$r['code']] = $r;
//        }
//
//        $templatePath = base_path('v05.xls');
//        if (!is_file($templatePath)) {
//            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
//        }
//
//        $reader = new XlsReader();
//        $reader->setReadDataOnly(false);
//        $spreadsheet = $reader->load($templatePath);
//        $sheet = $spreadsheet->getActiveSheet();
//
//        foreach ($sheet->getMergeCells() as $range) {
//            $sheet->unmergeCells(str_replace('$', '', $range));
//        }
//
//        $mapPath = storage_path('app/templates/v05_map.json');
//        if (!is_file($mapPath)) {
//            return response()->json(['message' => "Map not found at {$mapPath}"], 404);
//        }
//        $rowCodeMap = json_decode(file_get_contents($mapPath), true) ?: [];
//
//
//// from/to գրել C9 և C10 բջիջներում `dd-mm-yy` format-ով առանց ժամի
//        $sheet->setCellValue('C9', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($from->copy()->startOfDay()));
//        $sheet->getStyle('C9')->getNumberFormat()->setFormatCode('dd-mm-yyyy');
//
//        $sheet->setCellValue('C10', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($to->copy()->startOfDay()));
//        $sheet->getStyle('C10')->getNumberFormat()->setFormatCode('dd-mm-yyyy');
//
//        $maxRow = $sheet->getHighestRow();
//        for ($row = 1; $row <= $maxRow; $row++) {
//            if (!isset($rowCodeMap[$row])) {
//                continue;
//            }
//
//            $code = (string) $rowCodeMap[$row];
//
//            // Նախնականացնել՝ որ չօգտագործվեն, եթե չկան
//            $prevNet = null;
//            $currNet = null;
//
//            // Գրել միայն եթե կա համապատասխան արժեք
//            if (isset($prevBy[$code]['net'])) {
//                $prevNet = (float) $prevBy[$code]['net'];
//                $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC); // C
//            }
//
//            if (isset($currBy[$code]['net'])) {
//                $currNet = (float) $currBy[$code]['net'];
//                $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC); // D
//            }
//
//            $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC); // C
//            $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC); // D
//        }
//
//        $writer = new XlsWriter($spreadsheet);
//        $filename = "monthly_income_expense.xls";
//
//        $dir = storage_path('app/reports');
//        if (!is_dir($dir)) {
//            @mkdir($dir, 0777, true);
//        }
//        $path = $dir . DIRECTORY_SEPARATOR . $filename;
//
//        while (ob_get_level() > 0) {
//            @ob_end_clean();
//        }
//        $writer->save($path);
//
//        return response()->download($path, $filename, [
//            'Content-Type' => 'application/vnd.ms-excel',
//            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
//            'Pragma' => 'public',
//        ])->deleteFileAfterSend(true);
//    }
//
//    /** helpers **/
//    protected function monthRange(string $yyyyMm): array
//    {
//        $start = Carbon::createFromFormat('Y-m', $yyyyMm)->startOfMonth()->toDateString();
//        $end = Carbon::createFromFormat('Y-m', $yyyyMm)->endOfMonth()->toDateString();
//        return [$start, $end];
//    }
//
//    protected function previousMonthRangeFrom(string $from): array
//    {
//        $c = Carbon::createFromFormat('Y-m-d', $from)->subMonthNoOverflow();
//        return [$c->startOfMonth()->toDateString(), $c->endOfMonth()->toDateString()];
//    }
//}


namespace App\Http\Controllers;

use App\Services\IncomeExpenseMonthlyReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Carbon\Carbon;

class MonthlyIncomeExpenseController extends Controller
{
    public function __construct(private IncomeExpenseMonthlyReport $svc)
    {
    }

    public function __invoke(Request $request): Response|BinaryFileResponse
    {
        // ---- 1) Parse dates ----
        $fromStr = $request->query('from');
        $toStr = $request->query('to');
        if (!$fromStr || !$toStr) {
            return response()->json(['message' => 'Provide ?from=YYYY-MM-DD&to=YYYY-MM-DD'], 422);
        }

        try {
            $from = Carbon::createFromFormat('Y-m-d', $fromStr)->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $toStr)->endOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid date format. Use YYYY-MM-DD'], 422);
        }

        if ($from->gt($to)) {
            return response()->json(['message' => '`from` must be <= `to`'], 422);
        }

        // Prev period: same length, ending day before $from
        $daysInclusive = $to->copy()->startOfDay()->diffInDays($from->copy()->startOfDay()) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($daysInclusive - 1)->startOfDay();

        // ---- 2) Build data ----
        $current = $this->svc->build($from->toDateTimeString(), $to->toDateTimeString());
        $previous = $this->svc->build($prevFrom->toDateTimeString(), $prevTo->toDateTimeString());

        $currBy = [];
        foreach ($current as $r) {
            $currBy[(string)$r['code']] = $r;
        }
        $prevBy = [];
        foreach ($previous as $r) {
            $prevBy[(string)$r['code']] = $r;
        }

        // ---- 3) Load template ----
        $templatePath = base_path('v05.xls');
        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsReader();
        $reader->setReadDataOnly(false); // keep formulas/styles
        $spreadsheet = $reader->load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        // ---- 4) Load row→code map ----
        $mapPath = storage_path('app/templates/v05_map.json');
        if (!is_file($mapPath)) {
            return response()->json(['message' => "Map not found at {$mapPath}"], 404);
        }
        $rowCodeMap = json_decode(file_get_contents($mapPath), true) ?: [];
        $rowCodeMap = array_combine(
            array_map('intval', array_keys($rowCodeMap)),
            array_values($rowCodeMap)
        );

        // ---- 5) Write date range into C9/C10 (dd-mm-yyyy) ----
        $sheet->setCellValue('C9', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($from->copy()->startOfDay()));
        $sheet->getStyle('C9')->getNumberFormat()->setFormatCode('dd-mm-yyyy');
        $sheet->setCellValue('C10', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($to->copy()->startOfDay()));
        $sheet->getStyle('C10')->getNumberFormat()->setFormatCode('dd-mm-yyyy');

        // ---- 6) Debug merged cells ----
        Log::info('Merged cells in template: ' . json_encode($sheet->getMergeCells()));

        // ---- 7) Unmerge cells in columns C/D for mapped rows ----
        foreach (array_keys($sheet->getMergeCells()) as $rawRange) {
            $normalized = preg_replace('/^.*?!/', '', $rawRange);
            $normalized = str_replace('$', '', $normalized);
            if (!preg_match('/^[A-Z]+[0-9]+:[A-Z]+[0-9]+$/', $normalized)) {
                Log::warning('Skipping invalid merge range: ' . $normalized);
                continue;
            }
            try {
                [$start, $end] = Coordinate::rangeBoundaries($normalized);
                [$sCol, $sRow] = $start;
                [$eCol, $eRow] = $end;
                // Unmerge if range overlaps with columns C (3) or D (4) and mapped rows
                if (($sCol <= 4 && $eCol >= 3) && isset($rowCodeMap[$sRow])) {
                    $sheet->unmergeCells($normalized);
                    Log::info('Unmerged range affecting C/D in row ' . $sRow . ': ' . $normalized);
                }
            } catch (\Throwable $e) {
                Log::warning('Unmerge failed: ' . $normalized . ' — ' . $e->getMessage());
            }
        }

        // ---- 8) (Optional) Safe unmerge only inside a target range ----
        $targetRange = null; // Keep null unless unmerging is required
        Log::info('Target range value: ' . ($targetRange ?? 'null'));
        if ($targetRange) {
            try {
                $targetRange = str_replace('$', '', $targetRange);
                [$tStart, $tEnd] = Coordinate::rangeBoundaries($targetRange);
                [$tStartCol, $tStartRow] = $tStart;
                [$tEndCol, $tEndRow] = $tEnd;
                foreach (array_keys($sheet->getMergeCells()) as $rawRange) {
                    $normalized = preg_replace('/^.*?!/', '', $rawRange);
                    $normalized = str_replace('$', '', $normalized);
                    if (!preg_match('/^[A-Z]+[0-9]+:[A-Z]+[0-9]+$/', $normalized)) {
                        Log::warning('Skipping invalid range: ' . $normalized);
                        continue;
                    }
                    try {
                        [$start, $end] = Coordinate::rangeBoundaries($normalized);
                        [$sCol, $sRow] = $start;
                        [$eCol, $eRow] = $end;
                        $overlaps = !($eCol < $tStartCol || $sCol > $tEndCol || $eRow < $tStartRow || $sRow > $tEndRow);
                        if ($overlaps) {
                            $sheet->unmergeCells($normalized);
                            Log::info('Unmerged range: ' . $normalized);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Unmerge failed for range: ' . $normalized . ' — ' . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Target range processing failed: ' . $targetRange . ' — ' . $e->getMessage());
            }
        }

        // ---- 9) Validate mapped rows ----
        $maxRow = $sheet->getHighestRow();
        foreach ($rowCodeMap as $row => $code) {
            if ($row > $maxRow) {
                Log::warning("Row $row (code $code) exceeds max row $maxRow in v05.xls");
                continue;
            }
            $cCell = $sheet->getCellByColumnAndRow(3, $row);
            $dCell = $sheet->getCellByColumnAndRow(4, $row);
            if ($cCell->isInMergeRange() || $dCell->isInMergeRange()) {
                Log::warning("Row $row (code $code) is still part of a merged range after unmerge");
            }
        }

        // ---- 10) Fill rows C/D, set empty when no data ----
        for ($row = 1; $row <= $maxRow; $row++) {
            if (!isset($rowCodeMap[$row])) {
                continue;
            }

            $code = (string)$rowCodeMap[$row];

            // Skip if C or D has a formula (preserve formulas like at C16)
            $cCell = $sheet->getCellByColumnAndRow(3, $row); // C
            $dCell = $sheet->getCellByColumnAndRow(4, $row); // D
            if ($cCell->isFormula() || $dCell->isFormula()) {
                continue;
            }

            // Write previous period (column C): empty if no data
            $sheet->setCellValueExplicitByColumnAndRow(
                3,
                $row,
                isset($prevBy[$code]['net']) ? (float)$prevBy[$code]['net'] : null,
                isset($prevBy[$code]['net']) ? DataType::TYPE_NUMERIC : DataType::TYPE_NULL
            );

            // Write current period (column D): empty if no data
            $sheet->setCellValueExplicitByColumnAndRow(
                4,
                $row,
                isset($currBy[$code]['net']) ? (float)$currBy[$code]['net'] : null,
                isset($currBy[$code]['net']) ? DataType::TYPE_NUMERIC : DataType::TYPE_NULL
            );
        }

        // ---- 11) Save & force formula recalculation ----
        $writer = new XlsWriter($spreadsheet);
        $writer->setPreCalculateFormulas(true); // important for .xls

        $filename = "monthly_income_expense.xls";
        $dir = storage_path('app/reports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $writer->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'public',
        ])->deleteFileAfterSend(true);
    }

    /** helpers **/
    protected function monthRange(string $yyyyMm): array
    {
        $start = Carbon::createFromFormat('Y-m', $yyyyMm)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', $yyyyMm)->endOfMonth()->toDateString();
        return [$start, $end];
    }

    protected function previousMonthRangeFrom(string $from): array
    {
        $c = Carbon::createFromFormat('Y-m-d', $from)->subMonthNoOverflow();
        return [$c->startOfMonth()->toDateString(), $c->endOfMonth()->toDateString()];
    }
}

