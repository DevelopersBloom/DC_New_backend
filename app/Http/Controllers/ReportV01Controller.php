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

    /** կարգավորումներ */
    protected ?string $to;
    protected array $summary = [];

    /** գումարվող դաշտերի ցանկը */
    protected array $sumFields = [
        'total_resident','total_non_resident',
        'amd_resident','amd_non_resident',
        'fx_group1_resident','fx_group1_non_resident',
        'usd_resident','usd_non_resident',
        'eur_resident','eur_non_resident',
        'fx_group2_resident','fx_group2_non_resident',
        'rub_resident','rub_non_resident',
    ];

    public function __construct(?string $to = null)
    {
        $this->to = $to;
        $this->summary = [];
    }

    /**
     * GET /reports/journal?to=YYYY-MM-DD
     * Կարող ես փոխել ըստ կարիքի. Քանի որ քո հաշվարկները կախված են $to-ից, թողել եմ նույնը:
     */
    public function __invoke(Request $request): Response|BinaryFileResponse
    {
        $toStr = $request->query('to', $this->to);
        if (!$toStr) {
            return response()->json(['message' => 'Provide ?to=YYYY-MM-DD'], 422);
        }

        // 1) Բերում ենք տողերը և ամփոփումը (քո trait-ից)
        $rawRows  = $this->balancesRowsQuery($toStr)->get();   // Collection
        $rows     = $this->transformToReport1($rawRows)->values();
        $this->summary = $this->balancesSummary($toStr) ?? [];

        // 2) Բացում ենք template-ը (XLS)
        $templatePath = base_path('v01.xls'); // փոխիր իրական ուղով՝ base_pats(v01).xls
        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsReader();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        // 3) Սկսման բջիջը՝ ըստ քո պահանջի (A8)
        $startRow = 8; // A8
        $currentRow = $startRow;

        // 4) Գրենք տողերը template-ի մեջ ըստ map()-իդ տրամաբանության
        foreach ($rows as $row) {
            $mapped = $this->mapRow($row); // ստանում ենք array 17 սյուներով (A..Q)

            // Ա–Q = 1..17 սյուն
            $col = 1;
            foreach ($mapped as $value) {
                // թվերը որպես numeric, տեքստերը՝ general
                if (is_numeric($value)) {
                    $sheet->setCellValueExplicitByColumnAndRow($col, $currentRow, (float)$value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicitByColumnAndRow($col, $currentRow, (string)$value, DataType::TYPE_STRING);
                }
                $col++;
            }
            $currentRow++;
        }

        // 5) Ամփոփման արժեքներ՝ S2:T5 (հարմարեցրու, եթե template-ում այլ վայր է)
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

        // 6) Պահպանում ենք XLS writer-ով և տալիս download
        $writer = new XlsWriter($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $dir = storage_path('app/reports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $filename = 'base_pats_v01.xls';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        while (ob_get_level() > 0) { @ob_end_clean(); }
        $writer->save($path);

        return response()->download($path, $filename, [
            'Content-Type'  => 'application/vnd.ms-excel',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'public',
        ])->deleteFileAfterSend(true);
    }

    /** map()—ի «Controller» տարբերակը (A..Q) */
    protected function mapRow($row): array
    {
        return [
            $row->code,                     // A
            $row->name,                     // B
            (float)($row->total_resident ?? 0) + (float)($row->total_non_resident ?? 0), // C

            (float)($row->total_resident ?? 0),      // D
            (float)($row->total_non_resident ?? 0),  // E

            (float)($row->amd_resident ?? 0),        // F
            (float)($row->amd_non_resident ?? 0),    // G

            (float)($row->fx_group1_resident ?? 0),  // H
            (float)($row->fx_group1_non_resident ?? 0), // I

            (float)($row->usd_resident ?? 0),        // J
            (float)($row->usd_non_resident ?? 0),    // K

            (float)($row->eur_resident ?? 0),        // L
            (float)($row->eur_non_resident ?? 0),    // M

            (float)($row->fx_group2_resident ?? 0),  // N
            (float)($row->fx_group2_non_resident ?? 0), // O

            (float)($row->rub_resident ?? 0),        // P
            (float)($row->rub_non_resident ?? 0),    // Q
        ];
    }

    /** ------ Helpers (քո export logic-ից 그대로) ------ */

    protected function transformToReport1($rows)
    {
        $lettered = $rows->filter(fn($r) => $this->isLetteredCode((string)$r->code));
        $grouped = $rows->groupBy(fn($r) => $this->base5((string)$r->code));

        $baseAggregates = $grouped->map(function ($group, $base5) {
            $exact = $group->first(fn($x) => (string)$x->code === $base5);
            $name  = $exact->name
                ?? optional($group->sortBy(fn($x) => strlen((string)$x->code))->first())->name
                ?? $base5;

            $agg = ['code' => $base5, 'name' => $name];
            foreach ($this->sumFields as $f) {
                $agg[$f] = (float)$group->sum(fn($x) => (float)($x->{$f} ?? 0));
            }
            return (object)$agg;
        });

        $letteredNormalized = $lettered->map(function ($r) {
            foreach ($this->sumFields as $f) {
                $r->{$f} = (float)($r->{$f} ?? 0);
            }
            $r->code = (string)$r->code;
            $r->name = (string)($r->name ?? $r->code);
            return $r;
        });

        return $baseAggregates
            ->values()
            ->merge($letteredNormalized->values())
            ->sortBy('code')
            ->values();
    }

    protected function isLetteredCode(string $code): bool
    {
        return (bool)preg_match('/^\d{5}[A-Za-z]+$/', $code);
    }

    protected function base5(string $code): string
    {
        if (preg_match('/^\d{5}/', $code, $m)) {
            return $m[0];
        }
        return $code;
    }
}
