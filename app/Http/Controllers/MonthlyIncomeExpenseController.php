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
//            $code = (string)$rowCodeMap[$row]; // օրինակ "1.1" կամ "1.10"
//            $prevNet = (float)($prevBy[$code]['net'] ?? 0.0);
//            $currNet = (float)($currBy[$code]['net'] ?? 0.0);
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;

class MonthlyIncomeExpenseController extends Controller
{
    public function __construct(private IncomeExpenseMonthlyReport $svc)
    {
    }

    public function __invoke(Request $request): Response|BinaryFileResponse
    {
        // ---- Parse date range from query (?from=YYYY-MM-DD&to=YYYY-MM-DD)
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

        // ---- Compute previous comparable period (same number of days)
        $daysInclusive = $to->copy()->startOfDay()->diffInDays($from->copy()->startOfDay()) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($daysInclusive - 1)->startOfDay();

        // ---- Build data
        $current = $this->svc->build($from, $to);
        $previous = $this->svc->build($prevFrom, $prevTo);

        $currBy = [];
        foreach ($current as $r) {
            $currBy[(string)$r['code']] = $r;
        }
        $prevBy = [];
        foreach ($previous as $r) {
            $prevBy[(string)$r['code']] = $r;
        }

        // ---- Load template
        $templatePath = base_path('v05.xls');
        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsReader();
        $reader->setReadDataOnly(false); // keep styles / formulas
        $spreadsheet = $reader->load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        // IMPORTANT:
        // ✦ ՉԵՆՔ unmerge անում merge-երը, քանի որ դա է բերում "Cell coordinate must not be absolute." սխալին
        // (մասնավորապես երբ range-ը գալիս է SheetName!$A$1:$B$2 նոտացիայով)։
        // Եթե merge-երը պետք է պահպանվեն, դրանք չեն խանգարում setCellValue-ին՝ գրիր վերևի-ձախ բջիջում։

        // ---- Load map (row -> code)
        $mapPath = storage_path('app/templates/v05_map.json');
        if (!is_file($mapPath)) {
            return response()->json(['message' => "Map not found at {$mapPath}"], 404);
        }
        $rowCodeMap = json_decode(file_get_contents($mapPath), true) ?: [];

        // ---- Write header dates (C9, C10) as real Excel dates, format dd-mm-yy (no time)
        $sheet->setCellValue('C9', ExcelDate::PHPToExcel($from->copy()->startOfDay()));
        $sheet->getStyle('C9')->getNumberFormat()->setFormatCode('dd-mm-yy');

        $sheet->setCellValue('C10', ExcelDate::PHPToExcel($to->copy()->startOfDay()));
        $sheet->getStyle('C10')->getNumberFormat()->setFormatCode('dd-mm-yy');

        // ---- Fill C/D columns from map, բայց չվրաեձել բանաձև ունեցող բջիջները
        $maxRow = $sheet->getHighestRow();
        for ($row = 1; $row <= $maxRow; $row++) {
            if (!isset($rowCodeMap[$row])) {
                continue;
            }

            $code = (string)$rowCodeMap[$row]; // օրինակ "1.1" կամ "1.10"
            $prevNet = (float)($prevBy[$code]['net'] ?? 0.0);
            $currNet = (float)($currBy[$code]['net'] ?? 0.0);

            $cCell = $sheet->getCellByColumnAndRow(3, $row); // C
            $dCell = $sheet->getCellByColumnAndRow(4, $row); // D

            // Միայն եթե տվյալ բջիջում բանաձև ՉԿԱ, գրում ենք թիվ (մերժում ենք subtotal / formula rows-ը)
            if (!$cCell->isFormula()) {
                $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC);
            }
            if (!$dCell->isFormula()) {
                $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC);
            }
        }

        // ---- Save as XLS
        $writer = new XlsWriter($spreadsheet);
        // Թող Excel-ը բացելիս հաշվարկի բանաձևերը (արագ համակարգում, չի վերագրի computed արժեքներ)
        $writer->setPreCalculateFormulas(false);

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
