<?php


namespace App\Http\Controllers;

use App\Services\ActivityService;
use App\Services\IncomeExpenseMonthlyReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

class MonthlyIncomeExpenseController extends Controller
{

    protected ActivityService $activity;

    public function __construct(private IncomeExpenseMonthlyReport $svc,ActivityService $activity)
    {
        $this->activity = $activity;
    }
    public function __invoke(Request $request): Response|BinaryFileResponse
    {
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

        $this->activity->log(
            'export_v05',
            "Export V05 journal from{$from} to {$to}"
        );
        if ($from->gt($to)) {
            return response()->json(['message' => '`from` must be <= `to`'], 422);
        }

        $previous = $this->svc->build($from);
        $current  = $this->svc->build($to);
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

        $mapPath = storage_path('app/templates/v05_map.json');
        if (!is_file($mapPath)) {
            return response()->json(['message' => "Map not found at {$mapPath}"], 404);
        }

        $rowCodeMap = json_decode(file_get_contents($mapPath), true) ?: [];
        $sheet->setCellValueExplicit('C8', '«Ակրեդիտ» ՎՄ ՍՊԸ', DataType::TYPE_STRING);

        $sheet->setCellValue('C9', Date::PHPToExcel($from));
        $sheet->getStyle('C9')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $sheet->setCellValue('C10', Date::PHPToExcel($to));
        $sheet->getStyle('C10')->getNumberFormat()->setFormatCode('dd/mm/yy');

        $maxRow = $sheet->getHighestRow();

        for ($row = 1; $row <= $maxRow; $row++) {

            if (!isset($rowCodeMap[$row])) {
                continue;
            }

            $code = (string)$rowCodeMap[$row];

            if (isset($prevBy[$code]['net'])) {
                $sheet->setCellValueExplicitByColumnAndRow(
                    3, $row, (float)$prevBy[$code]['net'], DataType::TYPE_NUMERIC
                );
            }

            if (isset($currBy[$code]['net'])) {
                $sheet->setCellValueExplicitByColumnAndRow(
                    4, $row, (float)$currBy[$code]['net'], DataType::TYPE_NUMERIC
                );
            }
        }

        Log::debug('D161 before save = ' . var_export($sheet->getCell('D161')->getValue(), true));

        $writer = new XlsxWriter($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $filename = "monthly_income_expense.xlsx";
        $dir = storage_path('app/reports');

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $filename;

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $writer->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'public',
        ])->deleteFileAfterSend(true);
    }
}
