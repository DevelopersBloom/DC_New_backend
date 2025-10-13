<?php
//
//namespace App\Http\Controllers;
//
//use App\Traits\CalculatesAccountBalancesTrait;
//use Illuminate\Http\Request;
//use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
//use Symfony\Component\HttpFoundation\Response;
//use Symfony\Component\HttpFoundation\BinaryFileResponse;
//use PhpOffice\PhpSpreadsheet\Cell\DataType;
//use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
//use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
//class ReportV01Controller extends Controller
//{
//    use CalculatesAccountBalancesTrait;
//
//    protected ?string $to;
//    protected array $summary = [];
//
//    public function __construct(?string $to = null)
//    {
//        $this->to = $to;
//        $this->summary = [];
//    }
//
//    public function __invoke(Request $request): Response|BinaryFileResponse
//    {
//        $toStr = "2035-12-10"; //for test
//        if (!$toStr) {
//            return response()->json(['message' => 'Provide ?to=YYYY-MM-DD'], 422);
//        }
//
//        $rawRows  = $this->balancesRowsQuery($toStr)->get();
//        $rows     = $this->transformToReport1($rawRows)->values();
//        $this->summary = $this->balancesSummary($toStr) ?? [];
//
//        $templatePath = base_path('v01.xlsm');
//
//        if (!is_file($templatePath)) {
//            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
//        }
//
//        $reader = new XlsxReader();
//
//        $reader->setReadDataOnly(false);
//        $spreadsheet = $reader->load($templatePath);
//
//        $sheet = $spreadsheet->getSheetByName('Sheet1') ?: $spreadsheet->getSheet(0);
//        $spreadsheet->setActiveSheetIndex($sheet->getParent()->getIndex($sheet));
//
//        $startRow   = 8;
//        $currentRow = $startRow;
//
//        if ($rows->isEmpty()) {
//            $sheet->setCellValueExplicit("A{$currentRow}", 'NO DATA', DataType::TYPE_NUMERIC);
//        } else {
//            foreach ($rows as $row) {
//                $code = (string)$row->code;
//
//                if (preg_match('/^\d+$/', $code)) {
//                    $sheet->setCellValueExplicitByColumnAndRow(1, $currentRow, (float)$code, DataType::TYPE_NUMERIC);
//                } else {
//                    $sheet->setCellValueExplicitByColumnAndRow(1, $currentRow, $code, DataType::TYPE_STRING);
//                }
//
//
//                $nums = [
//                    6  => (float)($row->amd_resident ?? 0),
//                    7  => (float)($row->amd_non_resident ?? 0),
//                    8  => (float)($row->fx_group1_resident ?? 0),
//                    9  => (float)($row->fx_group1_non_resident ?? 0),
//                    10 => (float)($row->usd_resident ?? 0),
//                    11 => (float)($row->usd_non_resident ?? 0),
//                    12 => (float)($row->eur_resident ?? 0),
//                    13 => (float)($row->eur_non_resident ?? 0),
//                    14 => (float)($row->fx_group2_resident ?? 0),
//                    15 => (float)($row->fx_group2_non_resident ?? 0),
//                    16 => (float)($row->rub_resident ?? 0),
//                    17 => (float)($row->rub_non_resident ?? 0),
//                ];
//
//                foreach ($nums as $colIndex => $val) {
//                    $sheet->setCellValueExplicitByColumnAndRow($colIndex, $currentRow, $val, DataType::TYPE_NUMERIC);
//                }
//
//                $currentRow++;
//            }
//        }
//
//        $writer = new XlsxWriter($spreadsheet);
//        $writer->setPreCalculateFormulas(false);
//
//        $dir = storage_path('app/reports');
//        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
//
//        $filename = 'base_pats_v01_OUT.xlsm';
//        $path = $dir . DIRECTORY_SEPARATOR . $filename;
//
//        while (ob_get_level() > 0) { @ob_end_clean(); }
//
//        $writer->save($path);
//
//
//        return response()->download($path, $filename, [
//            'Content-Type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
//        ]);
//    }
//
//    protected function rangesOverlap(string $r1, string $r2): bool
//    {
//        [$s1, $e1] = Coordinate::rangeBoundaries(str_replace('$', '', $r1));
//        [$s2, $e2] = Coordinate::rangeBoundaries(str_replace('$', '', $r2));
//
//        return !(
//            $e1[0] < $s2[0] || $e2[0] < $s1[0] ||
//            $e1[1] < $s2[1] || $e2[1] < $s1[1]
//        );
//    }
//    protected function transformToReport1($rows)
//    {
//        $sumFields = [
//            'total_resident','total_non_resident',
//            'amd_resident','amd_non_resident',
//            'fx_group1_resident','fx_group1_non_resident',
//            'usd_resident','usd_non_resident',
//            'eur_resident','eur_non_resident',
//            'fx_group2_resident','fx_group2_non_resident',
//            'rub_resident','rub_non_resident',
//        ];
//
//        $lettered = $rows->filter(fn($r) => (bool)preg_match('/^\d{5}[A-Za-z]+$/', (string)$r->code));
//        $grouped  = $rows->groupBy(fn($r) => preg_match('/^\d{5}/', (string)$r->code, $m) ? $m[0] : (string)$r->code);
//
//        $baseAggregates = $grouped->map(function ($group, $base5) use ($sumFields) {
//            $exact = $group->first(fn($x) => (string)$x->code === $base5);
//            $name  = $exact->name
//                ?? optional($group->sortBy(fn($x) => strlen((string)$x->code))->first())->name
//                ?? $base5;
//
//            $agg = ['code' => $base5, 'name' => $name];
//            foreach ($sumFields as $f) {
//                $agg[$f] = (float)$group->sum(fn($x) => (float)($x->{$f} ?? 0));
//            }
//            return (object)$agg;
//        });
//
//        $letteredNormalized = $lettered->map(function ($r) use ($sumFields) {
//            foreach ($sumFields as $f) { $r->{$f} = (float)($r->{$f} ?? 0); }
//            $r->code = (string)$r->code;
//            $r->name = (string)($r->name ?? $r->code);
//            return $r;
//        });
//
//        $final = $baseAggregates->values()
//            ->merge($letteredNormalized->values())
//            ->sortBy('code')
//            ->values();
//
//        $sum6 = $this->sumByPrefix($rows, '6', $sumFields);
//        $sum7 = $this->sumByPrefix($rows, '7', $sumFields);
//
//        $net52000 = [];
//        foreach ($sumFields as $f) {
//            $net52000[$f] = (float)($sum6[$f] - $sum7[$f]);
//        }
//
//        $finalNo67 = $final->reject(fn($r) => preg_match('/^[67]/', (string)$r->code));
//
//        $hasNonZero52000 = false;
//        foreach ($sumFields as $f) {
//            if (abs($net52000[$f]) > 0.0) { $hasNonZero52000 = true; break; }
//        }
//
//        $existing52000Key = $finalNo67->search(fn($r) => (string)$r->code === '52000');
//
//        if ($hasNonZero52000) {
//            if ($existing52000Key !== false) {
//                $r = $finalNo67[$existing52000Key];
//                foreach ($sumFields as $f) { $r->{$f} = $net52000[$f]; }
//                $finalNo67[$existing52000Key] = $r;
//            } else {
//                // Add new
//                $finalNo67->push((object) array_merge([
//                    'code' => '52000',
//                ], $net52000));
//            }
//        } else {
//            if ($existing52000Key !== false) {
//                $finalNo67->forget($existing52000Key);
//                $finalNo67 = $finalNo67->values(); // reindex
//            }
//        }
//
//        return $finalNo67->sortBy('code')->values();
//    }
//
//    protected function sumByPrefix($rows, string $prefix, array $sumFields): array
//    {
//        $agg = [];
//        foreach ($sumFields as $f) { $agg[$f] = 0.0; }
//
//        foreach ($rows as $r) {
//            $code = (string)($r->code ?? '');
//            if (preg_match('/^' . preg_quote($prefix, '/') . '/', $code)) {
//                foreach ($sumFields as $f) {
//                    $agg[$f] += (float)($r->{$f} ?? 0);
//                }
//            }
//        }
//        return $agg;
//    }
//}
//
//


namespace App\Http\Controllers;

use App\Traits\CalculatesAccountBalancesTrait;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

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
        $toStr = "2035-12-10"; //for test
        if (!$toStr) {
            return response()->json(['message' => 'Provide ?to=YYYY-MM-DD'], 422);
        }

        $rawRows = $this->balancesRowsQuery($toStr)->get();
        $rows = $this->transformToReport1($rawRows)->values();
        $this->summary = $this->balancesSummary($toStr) ?? [];

        $templatePath = base_path('v01.xlsm');

        if (!is_file($templatePath)) {
            return response()->json(['message' => "Template not found at {$templatePath}"], 404);
        }

        $reader = new XlsxReader();

        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);

        $sheet = $spreadsheet->getSheetByName('Sheet1') ?: $spreadsheet->getSheet(0);
        $spreadsheet->setActiveSheetIndex($sheet->getParent()->getIndex($sheet));

        $startRow = 8;
        $currentRow = $startRow;

        if ($rows->isEmpty()) {
            $sheet->setCellValueExplicit("A{$currentRow}", 'NO DATA', DataType::TYPE_NUMERIC);
        } else {
            foreach ($rows as $row) {
                $code = (string)$row->code;

                // Այս տրամաբանությունը ճիշտ է. տառ պարունակող կոդերը գրանցվում են որպես տեքստ, իսկ 5-անիշ թվային կոդերը՝ որպես թիվ։
                if (preg_match('/^\d+$/', $code)) {
                    $sheet->setCellValueExplicitByColumnAndRow(1, $currentRow, (float)$code, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicitByColumnAndRow(1, $currentRow, $code, DataType::TYPE_STRING);
                }


                $nums = [
                    6 => (float)($row->amd_resident ?? 0),
                    7 => (float)($row->amd_non_resident ?? 0),
                    8 => (float)($row->fx_group1_resident ?? 0),
                    9 => (float)($row->fx_group1_non_resident ?? 0),
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

        $writer = new XlsxWriter($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $dir = storage_path('app/reports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $filename = 'base_pats_v01_OUT.xlsm';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $writer->save($path);


        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        ]);
    }

    protected function rangesOverlap(string $r1, string $r2): bool
    {
        [$s1, $e1] = Coordinate::rangeBoundaries(str_replace('$', '', $r1));
        [$s2, $e2] = Coordinate::rangeBoundaries(str_replace('$', '', $r2));

        return !(
            $e1[0] < $s2[0] || $e2[0] < $s1[0] ||
            $e1[1] < $s2[1] || $e2[1] < $s1[1]
        );
    }

    // Փոփոխված մեթոդը 5-անիշ կոդերը գումարելու (ներառյալ տառերովը) և տառ պարունակող կոդերը (օրինակ՝ 33512NV) առանձին տողով ցույց տալու համար
    protected function transformToReport1($rows)
    {
        $sumFields = [
            'total_resident', 'total_non_resident',
            'amd_resident', 'amd_non_resident',
            'fx_group1_resident', 'fx_group1_non_resident',
            'usd_resident', 'usd_non_resident',
            'eur_resident', 'eur_non_resident',
            'fx_group2_resident', 'fx_group2_non_resident',
            'rub_resident', 'rub_non_resident',
        ];

        // 1. Բաժանում ենք տառ պարունակող կոդերը (օրինակ՝ 33512NV), որոնք պետք է երևան որպես առանձին տողեր։
        $lettered = $rows->filter(fn($r) => (bool)preg_match('/[A-Za-z]/', (string)$r->code));

        // 2. Խմբավորում ենք ԲՈԼՈՐ տողերը (այսինքն՝ օգտագործում ենք $rows-ը), ըստ առաջին 5 թվանշանի։
        // Սա ապահովում է, որ 33512NV-ի տվյալները կգումարվեն 33512-ի մեջ։
        $grouped = $rows->groupBy(fn($r) => preg_match('/^\d{5}/', (string)$r->code, $m) ? $m[0] : (string)$r->code);

        // 3. Հաշվարկում ենք 5-անիշ հիմնական կոդերի գումարային տողերը։
        $baseAggregates = $grouped->map(function ($group, $base5) use ($sumFields) {
            // Միայն 5-անիշ թվային բանալիները պահել։
            if (!preg_match('/^\d{5}$/', $base5)) {
                return null;
            }

            $exact = $group->first(fn($x) => (string)$x->code === $base5);
            $name = $exact->name
                ?? optional($group->sortBy(fn($x) => strlen((string)$x->code))->first())->name
                ?? $base5;

            $agg = ['code' => $base5, 'name' => $name];
            foreach ($sumFields as $f) {
                $agg[$f] = (float)$group->sum(fn($x) => (float)($x->{$f} ?? 0));
            }
            return (object)$agg;
        })->filter()->values(); // Հեռացնում է null-երը

        // 4. Նորմալացնում ենք տառ պարունակող կոդերը (որոնք կցուցադրվեն առանձին տողով):
        $letteredNormalized = $lettered->map(function ($r) use ($sumFields) {
            foreach ($sumFields as $f) {
                $r->{$f} = (float)($r->{$f} ?? 0);
            }
            $r->code = (string)$r->code;
            $r->name = (string)($r->name ?? $r->code);
            return $r;
        });

        // 5. Միացնում ենք ագրեգացված 5-անիշ կոդերը և առանձին տառ պարունակող կոդերը։
        $final = $baseAggregates->values()
            ->merge($letteredNormalized->values())
            ->sortBy('code')
            ->values();

        // 6. Net 52000 հաշվարկը մնում է անփոփոխ՝ օգտագործելով $rows-ը՝ բոլոր 6x/7x կոդերը ներառելու համար։
        $sum6 = $this->sumByPrefix($rows, '6', $sumFields);
        $sum7 = $this->sumByPrefix($rows, '7', $sumFields);

        $net52000 = [];
        foreach ($sumFields as $f) {
            $net52000[$f] = (float)($sum6[$f] - $sum7[$f]);
        }

        $finalNo67 = $final->reject(fn($r) => preg_match('/^[67]/', (string)$r->code));

        $hasNonZero52000 = false;
        foreach ($sumFields as $f) {
            if (abs($net52000[$f]) > 0.0) {
                $hasNonZero52000 = true;
                break;
            }
        }

        $existing52000Key = $finalNo67->search(fn($r) => (string)$r->code === '52000');

        if ($hasNonZero52000) {
            if ($existing52000Key !== false) {
                $r = $finalNo67[$existing52000Key];
                foreach ($sumFields as $f) {
                    $r->{$f} = $net52000[$f];
                }
                $finalNo67[$existing52000Key] = $r;
            } else {
                // Add new
                $finalNo67->push((object)array_merge([
                    'code' => '52000',
                ], $net52000));
            }
        } else {
            if ($existing52000Key !== false) {
                $finalNo67->forget($existing52000Key);
                $finalNo67 = $finalNo67->values(); // reindex
            }
        }

        return $finalNo67->sortBy('code')->values();
    }
}
