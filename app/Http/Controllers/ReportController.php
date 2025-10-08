<?php

namespace App\Http\Controllers;

use App\Exports\ReportsJournalExport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ReportController
{
    public function getFirstReport(Request $request)
    {
        $to = $request->query('to');

        $filename = 'Հաշվետվություն' . ($to ? "_to_{$to}" : '') . '.xlsx';

        return Excel::download(new ReportsJournalExport($to), $filename);
    }

    public function getV03Report(Request $request)
    {
        return $this->downloadTemplate('v03.xls', $request);
    }

    public function getV06Report(Request $request)
    {
        return $this->downloadTemplate('v06.xls', $request);
    }

    public function getV07Report(Request $request)
    {
        return $this->downloadTemplate('v07.xls', $request);
    }

    public function getV013Report(Request $request)
    {
        // Ֆայլի անունը “v013.xls” (կամ “v013.xlsx” եթե այդպես ես պահել)
        return $this->downloadTemplate('v013.xls', $request);
    }

    /**
     * Ընդհանուր helper՝ բեռնավորելու համար static template ֆայլը
     * և ֆայլի անվան մեջ ներառելու from/to query-ները:
     */
    private function downloadTemplate(string $templateFile, Request $request)
    {
        $from = (string) $request->query('from', '');
        $to   = (string) $request->query('to', '');

        $from = $this->sanitizeDatePart($from);
        $to   = $this->sanitizeDatePart($to);

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

}
