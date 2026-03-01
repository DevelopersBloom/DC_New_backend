<?php

namespace App\Http\Controllers;

use App\Exports\Reports\ReportsJournalExport;
use App\Exports\Reports\V03Export;
use App\Exports\Reports\V06Export;
use App\Exports\Reports\V07Export;
use App\Exports\Reports\V13Export;
use App\Exports\Reports\V17Export;
use App\Services\ActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ReportController
{
    protected ActivityService $activity;
    public function __construct(ActivityService $activity)
    {
        $this->activity = $activity;
    }
    public function getFirstReport(Request $request)
    {
        $to = $request->query('to');

        $filename = 'Հաշվետվություն' . ($to ? "_to_{$to}" : '') . '.xlsx';

        $this->activity->log(
            'export_first_report',
            "Export {$filename}"
        );

        return Excel::download(new ReportsJournalExport($to), $filename);
    }

    public function getV03Report(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date',
        ]);

        $this->activity->log(
            'export_v03',
            "Export V03 from {$request->from} to {$request->to}"
        );
        $export = new V03Export();

        $path = $export->export($request->from, $request->to);
//        return $this->downloadWithPrefix($path, 'v06');
        return response()->download($path)->deleteFileAfterSend();
    }


    public function getV06Report(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date',
        ]);

        $this->activity->log(
            'export_v06',
            "Export V06 from {$request->from} to {$request->to}"
        );
        $export = new V06Export();

        $path = $export->export($request->from, $request->to);

        return response()->download($path)->deleteFileAfterSend();    }

    public function getV07Report(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date',
        ]);
        $this->activity->log(
            'export_v07',
            "Export V07 from {$request->from} to {$request->to}"
        );

        $export = new V07Export();
        $path = $export->export($request->from, $request->to);

        return response()->download($path)->deleteFileAfterSend();
    }

    public function getV013Report(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date'
        ]);

        $export = new V13Export();
        $path = $export->export($request->from, $request->to);

        return response()->download($path)->deleteFileAfterSend();
    }

    public function getV17Report(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date'
        ]);

        $from = $request->from
            ? Carbon::parse($request->from)->startOfDay()
            : now()->subDays(7)->startOfDay();

        $to = $request->to
            ? Carbon::parse($request->to)->endOfDay()
            : now()->endOfDay();
        $this->activity->log(
            'export_v17',
            "Export V17 from {$from} to {$to}"
        );

        $export = new V17Export();
        $file = $export->export($from, $to);

        return response()->download($file)->deleteFileAfterSend(true);
    }


    private function downloadTemplate(string $templateFile, Request $request)
    {
        $from = (string) $request->query('from', '');
        $to   = (string) $request->query('to', '');

        $from = $this->sanitizeDatePart($from);
        $to   = $this->sanitizeDatePart($to);

        $this->activity->log(
            'download_template',
            "Download template {$templateFile} from {$from} to {$to}"
        );
        $suffix = $this->buildSuffix($from, $to);

        $absPath = base_path($templateFile);
        if (!is_file($absPath)) {
            abort(404, "Template not found: {$templateFile}");
        }

        $ext = Str::lower(pathinfo($templateFile, PATHINFO_EXTENSION));
        $mime = $ext === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/vnd.ms-excel';

        $downloadName = pathinfo($templateFile, PATHINFO_FILENAME) . $suffix . '.' . $ext;

        return response()->download($absPath, $downloadName, [
            'Content-Type' => $mime,
        ]);
    }

    private function sanitizeDatePart(string $value): string
    {
        return preg_replace('/[^0-9\-\.\_]/', '', $value);
    }

    private function buildSuffix(string $from, string $to): string
    {
        if ($from && $to) {
            return "_from_{$from}_to_{$to}";
        }
        if ($from) {
            return "_from_{$from}";
        }
        if ($to) {
            return "_to_{$to}";
        }
        return '';
    }

    private function downloadWithPrefix(string $path, string $reportNumber)
    {
        $filename = "66100-{$reportNumber}.xls";

        return response()->download($path, $filename)->deleteFileAfterSend();
    }
}
