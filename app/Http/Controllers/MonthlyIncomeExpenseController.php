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
////    private const CODE_DECIMALS = 2;
//
//    public function __construct(private IncomeExpenseMonthlyReport $svc){}
//    public function __invoke(Request $request): Response|BinaryFileResponse
//    {
//
//        $month = $request->query('month');
//        if (!$month) {
//            return response()->json(['message' => 'Provide ?month=YYYY-MM'], 422);
//        }
//        try {
//            [$from, $to] = $this->monthRange($month);
//        } catch (\Throwable $e) {
//            return response()->json(['message' => 'Invalid month format. Use YYYY-MM'], 422);
//        }
//
//        [$prevFrom, $prevTo] = $this->previousMonthRangeFrom($from);
//        $current = $this->svc->build($from, $to);
//        $previous = $this->svc->build($prevFrom, $prevTo);
//        $currBy = [];
//        foreach ($current as $r) {
//            $currBy[$this->normalizeCodeScalar($r['code'])] = $r;
//        }
//        $prevBy = [];
//        foreach ($previous as $r) {
//            $prevBy[$this->normalizeCodeScalar($r['code'])] = $r;
//        }
//
//        $templatePath = base_path('v05.xls');
//        if (!file_exists($templatePath)) {
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
////        $maxRow = $sheet->getHighestRow();
////        for ($row = 1; $row <= $maxRow; $row++) {
////            $cellA = $sheet->getCellByColumnAndRow(1, $row);
////            $code = $this->normalizedCodeFromCellRaw($cellA);
////            if ($code === '') continue;
////
////            $prevNet = (float)($prevBy[$code]['net'] ?? 0.0);
////            $currNet = (float)($currBy[$code]['net'] ?? 0.0);
////
////            $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC);
////            $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC);
////        }
////        $mapPath = storage_path('app/templates/v05_map.json');
////        $rowCodeMap = is_file($mapPath) ? json_decode(file_get_contents($mapPath), true) ?: [] : [];
////        $maxRow = $sheet->getHighestRow();
////        for ($row = 1; $row <= $maxRow; $row++) {
////
////            if (isset($rowCodeMap[$row])) {
////                $codeKey = (string) $rowCodeMap[$row];
////            } else {
////                $cellA   = $sheet->getCellByColumnAndRow(1, $row);
////                $codeKey = $this->normalizedCodeFromCellRaw($cellA);
////                if ($codeKey === '') continue;
////            }
////
////            $prevNet = (float)($prevBy[$this->normalizeCodeScalar($codeKey)]['net'] ?? 0.0);
////            $currNet = (float)($currBy[$this->normalizeCodeScalar($codeKey)]['net'] ?? 0.0);
////
////            $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC);
////            $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC);
////        }
//        $mapPath = storage_path('app/templates/v05_map.json');
//        $rowCodeMap = is_file($mapPath) ? json_decode(file_get_contents($mapPath), true) ?: [] : [];
//
//        $maxRow = $sheet->getHighestRow();
//        for ($row = 1; $row <= $maxRow; $row++) {
//
//            // ✅ օգտագործում ենք ՄԻԱՅՆ map-ը
//            if (!isset($rowCodeMap[$row])) {
//                continue; // ոչ մի Excel-read fallback
//            }
//
//            $codeKey = (string)$rowCodeMap[$row]; // map-ում գրիր "1.1" և "1.10" ըստ տողի
//
//            // ✅ ոչ մի normalize այլևս (քանի որ արդեն տեքստ է)
//            $prevNet = (float)($prevBy[$codeKey]['net'] ?? 0.0);
//            $currNet = (float)($currBy[$codeKey]['net'] ?? 0.0);
//
//            $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC);
//            $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC);
//        }
//
//
//        $writer = new XlsWriter($spreadsheet);
//        $filename = "monthly_income_expense_{$month}.xls";
//
//
//        $dir = storage_path('app/reports');
//        if (!is_dir($dir)) {
//            @mkdir($dir, 0777, true);
//        }
//
//        $path = $dir . DIRECTORY_SEPARATOR . $filename;
//
//
//        while (ob_get_level() > 0) {
//            @ob_end_clean();
//        }
//
//        $writer->save($path);
//
//
//        return response()->download($path, $filename, [
//            'Content-Type' => 'application/vnd.ms-excel',
//            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
//            'Pragma' => 'public',
//            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
//        ])->deleteFileAfterSend(true);
//    }
//
//    /** Helpers **/
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
//


namespace App\Http\Controllers;

use App\Services\IncomeExpenseMonthlyReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use Carbon\Carbon;

class MonthlyIncomeExpenseController extends Controller
{
    public function __construct(private IncomeExpenseMonthlyReport $svc)
    {
    }

    /**
     * GET /api/admin/reports/monthly-income-expense?month=YYYY-MM
     * - C սյուն՝ նախորդ ամիս net
     * - D սյուն՝ ընթացիկ ամիս net
     * Template: project root -> v05.xls
     * Map: storage/app/templates/v05_map.json
     */
    public function __invoke(Request $request): Response|BinaryFileResponse
    {
        // --- միայն month ---
        $month = $request->query('month');
        if (!$month) {
            return response()->json(['message' => 'Provide ?month=YYYY-MM'], 422);
        }

        try {
            [$from, $to] = $this->monthRange($month);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid month format. Use YYYY-MM'], 422);
        }

        // նախորդ ամբողջ ամիս
        [$prevFrom, $prevTo] = $this->previousMonthRangeFrom($from);

        // ագրեգացիա ըստ STRING code-ի (խնդրում ենք svc->build-ում ապահովել CAST(... AS CHAR) AS code)
        $current = $this->svc->build($from, $to);          // rows: ['code','inflow','outflow','net']
        $previous = $this->svc->build($prevFrom, $prevTo);  // rows: ['code','inflow','outflow','net']

        // lookup-ներ ըստ code (կոդերը ՏԵՔՍՏ են՝ "1.1", "1.10" և այլն)
        $currBy = [];
        foreach ($current as $r) {
            $currBy[(string)$r['code']] = $r;
        }
        $prevBy = [];
        foreach ($previous as $r) {
            $prevBy[(string)$r['code']] = $r;
        }

        // template
        $templatePath = base_path('v05.xls');
        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsReader();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        // merged cell-երը զատենք, որ column/row API-ն վստահ աշխատի
        foreach ($sheet->getMergeCells() as $range) {
            $sheet->unmergeCells(str_replace('$', '', $range));
        }

        // map file: row -> exact code (օր. "25":"1.10")
        $mapPath = storage_path('app/templates/v05_map.json');
        if (!is_file($mapPath)) {
            return response()->json(['message' => "Map not found at {$mapPath}"], 404);
        }
        $rowCodeMap = json_decode(file_get_contents($mapPath), true) ?: [];

        // լցնենք C (prev) և D (curr) միայն MAP-ով
        $maxRow = $sheet->getHighestRow();
        for ($row = 1; $row <= $maxRow; $row++) {
            if (!isset($rowCodeMap[$row])) {
                continue;
            }

            $code = (string)$rowCodeMap[$row]; // օրինակ "1.1" կամ "1.10"
            $prevNet = (float)($prevBy[$code]['net'] ?? 0.0);
            $currNet = (float)($currBy[$code]['net'] ?? 0.0);

            $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC); // C
            $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC); // D
        }

        // պահպանենք և տանք ներբեռնել
        $writer = new XlsWriter($spreadsheet);
        $filename = "monthly_income_expense_{$month}.xls";

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
