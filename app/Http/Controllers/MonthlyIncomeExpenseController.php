<?php
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
    public function __invoke(Request $request): Response|BinaryFileResponse
    {
//        $month = $request->query('month');
//        if (!$month) {
//            return response()->json(['message' => 'Provide ?month=YYYY-MM'], 422);
//        }
//
//        try {
//            [$from, $to] = $this->monthRange($month);
//        } catch (\Throwable $e) {
//            return response()->json(['message' => 'Invalid month format. Use YYYY-MM'], 422);
//        }
//
//        [$prevFrom, $prevTo] = $this->previousMonthRangeFrom($from);
//
//        $current = $this->svc->build($from, $to);
//        $previous = $this->svc->build($prevFrom, $prevTo);
        $fromStr = $request->query('from');
        $toStr   = $request->query('to');

        if (!$fromStr || !$toStr) {
            return response()->json(['message' => 'Provide ?from=YYYY-MM-DD&to=YYYY-MM-DD'], 422);
        }

        try {
            $from = Carbon::createFromFormat('Y-m-d', $fromStr)->startOfDay();
            $to   = Carbon::createFromFormat('Y-m-d', $toStr)->endOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid date format. Use YYYY-MM-DD'], 422);
        }

        if ($from->gt($to)) {
            return response()->json(['message' => '`from` must be <= `to`'], 422);
        }

        $daysInclusive = $to->copy()->startOfDay()->diffInDays($from->copy()->startOfDay()) + 1;

        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($daysInclusive - 1)->startOfDay();

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

        $templatePath = base_path('v05.xls');
        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsReader();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($sheet->getMergeCells() as $range) {
            $sheet->unmergeCells(str_replace('$', '', $range));
        }

        $mapPath = storage_path('app/templates/v05_map.json');
        if (!is_file($mapPath)) {
            return response()->json(['message' => "Map not found at {$mapPath}"], 404);
        }
        $rowCodeMap = json_decode(file_get_contents($mapPath), true) ?: [];
        $maxRow = $sheet->getHighestRow();
        for ($row = 1; $row <= $maxRow; $row++) {
            if (!isset($rowCodeMap[$row])) {
                // map-ում չլինելու դեպքում ընդհանրապես չգրենք՝
                // թողնենք բջիջները ինչպես կային (բանաձևերը, ձևաչափերը կմնան)
                continue;
            }

            $code = (string)$rowCodeMap[$row];

            // Արժեքներ՝ միայն եթե կան տվյալներ
            $valPrev = isset($prevBy[$code]) ? (float)$prevBy[$code]['net'] : null;
            $valCurr = isset($currBy[$code]) ? (float)$currBy[$code]['net'] : null;

            // Թիրախ բջիջներ
            $cellC = $sheet->getCellByColumnAndRow(3, $row); // C$row
            $cellD = $sheet->getCellByColumnAndRow(4, $row); // D$row

            // 1) Եթե բջիջը բանաձև ունի՝ մի վերագրիր
            if (!$cellC->isFormula() && $valPrev !== null) {
                $sheet->setCellValueExplicitByColumnAndRow(3, $row, $valPrev, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            }
            if (!$cellD->isFormula() && $valCurr !== null) {
                $sheet->setCellValueExplicitByColumnAndRow(4, $row, $valCurr, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            }
        }

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
//
//            $sheet->setCellValueExplicitByColumnAndRow(3, $row, $prevNet, DataType::TYPE_NUMERIC); // C
//            $sheet->setCellValueExplicitByColumnAndRow(4, $row, $currNet, DataType::TYPE_NUMERIC); // D
//        }

        $writer = new XlsWriter($spreadsheet);
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
