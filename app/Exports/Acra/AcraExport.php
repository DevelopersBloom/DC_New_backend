<?php

namespace App\Exports\Acra;

use App\Models\ClassificationHistory;
use App\Models\DocumentJournal;
use App\Models\Prepayment;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use App\Models\Contract;
use PhpParser\Comment\Doc;

class AcraExport
{
    protected $contracts;
    protected $allClients;
    protected $from;
    protected $to;
    /** Report generation time; PackageInfo B4 and the file name's package id. */
    protected Carbon $createdAt;
    protected $customerCode = 'ACC';
    private const DATE_FORMAT = 'dd/mm/yyyy';
    private const INTEGER_FORMAT = '0';
    // Overdue totals below this (AMD) should not be reported as overdue.
    public const MIN_OVERDUE_AMD = 1000;

    /** Blocking issues found before the file is finalized. */
    protected array $validationErrors = [];
    /** Non-blocking issues, logged for review. */
    protected array $validationWarnings = [];

    public function __construct($contracts, $allClients, $from, $to)
    {
        $this->contracts = $contracts;
        $this->allClients = $allClients;
        $this->from = $from;
        $this->to = $to;
    }

    public function export()
    {
        $this->createdAt = now();

        $path = base_path('acra_template.xlsx');
        if (!file_exists($path)) {
            throw new \Exception("Template file not found at: " . $path);
        }

        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        $this->fillPackageInfo($spreadsheet->getSheetByName('PackageInfo'));
        $this->fillDebtor($spreadsheet->getSheetByName('Debtor'));
        $this->fillOwner($spreadsheet->getSheetByName('Owner'));
        $this->fillCredit($spreadsheet->getSheetByName('Credit'));
        $this->fillCollateral($spreadsheet->getSheetByName('Collateral'));
        $this->fillGuarantor($spreadsheet->getSheetByName('Guarantor'));

        $this->normalizeTextColumns($spreadsheet);

        $this->trimStyleOnlyColumns($spreadsheet);

        $this->applyGlobalStyles($spreadsheet);

        $this->validateSheets($spreadsheet);

        if (!empty($this->validationWarnings)) {
            Log::warning('ACRA export validation warnings:', $this->validationWarnings);
        }
        if (!empty($this->validationErrors)) {
            Log::error('ACRA export validation failed, file was not generated:', $this->validationErrors);
            throw ValidationException::withMessages(['acra' => $this->validationErrors]);
        }

        // Package id is the CreatedDateTime (same timestamp as PackageInfo B4).
        $packageId = $this->createdAt->format('YmdHis');
        $fileName = "{$this->customerCode}_01_01_{$packageId}.xlsx";
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return [
            'path' => $filePath,
            'name' => $fileName
        ];
    }

    /**
     * The registry's checker reads every column the template formats as text
     * ("@") with a typed String accessor, and crashes ("System.Double" ->
     * "System.String") or mismatches cross-sheet ids when a numeric cell shows
     * up there. Convert numeric cells in template-text columns to strings; the
     * template's column formats are the registry's schema.
     */
    private function normalizeTextColumns($spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $textColumns = [];
            foreach ($sheet->getColumnDimensions() as $column => $dimension) {
                $format = $spreadsheet->getCellXfByIndex($dimension->getXfIndex())
                    ->getNumberFormat()->getFormatCode();
                if ($format === '@') {
                    $textColumns[$column] = true;
                }
            }
            if (!$textColumns) {
                continue;
            }

            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                [$column, $rowNumber] = Coordinate::coordinateFromString($coordinate);
                if ($rowNumber < 2 || !isset($textColumns[$column])) {
                    continue;
                }
                $cell = $sheet->getCell($coordinate);
                if ($cell->getDataType() === DataType::TYPE_NUMERIC) {
                    $cell->setValueExplicit((string) $cell->getValue(), DataType::TYPE_STRING);
                }
            }
        }
    }

    /**
     * The template carries a style-only (empty) column after the last real
     * column on every sheet. Excel drops it on re-save, but PhpSpreadsheet
     * keeps it, and the registry then rejects the file for having one column
     * too many. Remove trailing columns that contain no values.
     */
    private function trimStyleOnlyColumns($spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lastDataColumn = 1;
            foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                $value = $sheet->getCell($coordinate)->getValue();
                if ($value === null || $value === '') {
                    continue;
                }
                [$column] = Coordinate::coordinateFromString($coordinate);
                $lastDataColumn = max($lastDataColumn, Coordinate::columnIndexFromString($column));
            }

            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            for ($i = $highestColumn; $i > $lastDataColumn; $i--) {
                $column = Coordinate::stringFromColumnIndex($i);
                // Drops the column dimension (width/style definition)...
                $sheet->removeColumn($column);
                // ...and the style-only empty cells removeColumn leaves behind.
                $sheet->getCellCollection()->removeColumn($column);
            }
            // Recalculate the cached highest column so the writer does not pad
            // rows (and the dimension ref) back out to the phantom column.
            $sheet->garbageCollect();
        }
    }

    private function applyGlobalStyles($spreadsheet)
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheet->setRightToLeft(false);

            $highestRow = $sheet->getHighestRow();
            $highestCol = $sheet->getHighestColumn();

            $sheet->getStyle("A1:{$highestCol}{$highestRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }

        // PackageInfo values (dates, counts) are right-aligned in the ACRA sample file.
        $packageInfo = $spreadsheet->getSheetByName('PackageInfo');
        if ($packageInfo) {
            $packageInfo->getStyle('B1:B6')
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // Date columns are right-aligned in the ACRA sample file.
        $dateColumns = [
            'Debtor' => ['E', 'F'],
            'Credit' => ['C', 'D', 'E', 'M', 'U', 'X'],
        ];
        foreach ($dateColumns as $sheetName => $columns) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if (!$sheet) continue;
            $highestRow = $sheet->getHighestRow();
            foreach ($columns as $column) {
                $sheet->getStyle("{$column}2:{$column}{$highestRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
    }

    private function formatDate($date, $format = 'd.m.Y')
    {
        if (!$date) return '';
        try {
            if ($date instanceof Carbon) {
                return $date->format($format);
            }
            return Carbon::parse($date)->format($format);
        } catch (\Exception $e) {
            return '';
        }
    }

    private function setDateCellValue($sheet, string $coordinate, $date, string $format = self::DATE_FORMAT): void
    {
        if (!$date) {
            $sheet->setCellValue($coordinate, '');
            return;
        }

        try {
            $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);
            $sheet->setCellValue($coordinate, ExcelDate::PHPToExcel($carbon));
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
        } catch (\Exception $e) {
            $sheet->setCellValue($coordinate, '');
        }
    }

    private function setIntegerCellValue($sheet, string $coordinate, $value): void
    {
        $sheet->setCellValue($coordinate, (int) round((float) $value));
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::INTEGER_FORMAT);
    }

    private function fillPackageInfo($sheet)
    {
        if (!$sheet) return;
        $sheet->setCellValue('B1', $this->customerCode);
        $this->setDateCellValue($sheet, 'B2', $this->from, 'd/mm/yyyy');
        $this->setDateCellValue($sheet, 'B3', $this->to, 'd/mm/yyyy');
        $this->setDateCellValue($sheet, 'B4', $this->createdAt, 'dd/mm/yyyy hh:mm:ss');
        $sheet->setCellValue('B5', 1);
        $sheet->setCellValue('B6', 1);
    }

//    private function fillDebtor($sheet)
//    {
//        if (!$sheet) return;
//        $clients = $this->contracts->map->client->unique('id');
//        $row = 2;
//        foreach ($this->allClients as $client) {
//            $sheet->setCellValue('A' . $row, $client->id);
//            $sheet->setCellValue('B' . $row, ($client->type === 'legal' ? 'իրավաբանական անձ' : 'ֆիզիկական անձ'));
//
//            $name = ($client->type === 'legal')
//                ? ($client->company_name . ' ' . $client->legal_form)
//                : trim($client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : ''));
//
//            $sheet->setCellValue('C' . $row, $name);
//            $sheet->setCellValue('D' . $row, ($client->type === 'legal' ? $client->tax_number : $client->passport_series));
//
//            if ($client->type !== 'legal') {
//                $sheet->setCellValue('E' . $row, $this->formatDate($client->date_of_birth));
//                $issuedAt = null;
//                if ($client->passport_validity) {
//                    $issuedAt = Carbon::parse($client->passport_validity)->subYears(10);
//                }
//                $sheet->setCellValue('F' . $row, $this->formatDate($issuedAt));
//                $sheet->setCellValue('G' . $row, $client->passport_issued ?? '');
//                $sheet->setCellValue('H' . $row, $client->social_card_number);
//
//            }
//            $sheet->setCellValue('J' . $row, ($client->residency_status === 'resident' ? 'ռեզիդենտ' : 'ոչ ռեզիդենտ'));
//            $row++;
//        }
//    }
    private function fillDebtor($sheet)
    {
        if (!$sheet) return;
        $clients = $this->contracts->map->client->unique('id');
        $row = 2;

        foreach ($clients as $client) {
            $sheet->setCellValue('A' . $row, $client->id);

            $statusCode = null;
            if ($client->type === 'legal') {
                $statusCode = 11;
            }
            if ($client->type === 'individual_entrepreneur') {
                $statusCode = 22;
            }
            if ($client->type === 'individual'){
                $statusCode = 21;
            }
            $sheet->setCellValue('B' . $row, $statusCode);

            $name = ($client->type === 'legal')
                ? trim($client->company_name . ' ' . $client->legal_form)
                : trim($client->name . '^' . $client->surname . ($client->middle_name ? '^' . $client->middle_name : ''));

            $sheet->setCellValue('C' . $row, $name);
            $sheet->setCellValue('D' . $row, ($client->type === 'legal' ? $client->tax_number : $client->passport_series));

            if ($client->type !== 'legal') {
                $this->setDateCellValue($sheet, 'E' . $row, $client->date_of_birth);
                $this->setDateCellValue($sheet, 'F' . $row, $client->passport_validity);
                $sheet->setCellValue('G' . $row, $client->passport_issued ?? '');
                $sheet->setCellValue('H' . $row, $client->social_card_number);
            }

//            if ($client->gender) {
//                $genderCode = ($client->gender === 'F') ? 'իգական' : 'արական';
//                $sheet->setCellValue('I' . $row, $genderCode);
//            }
            if ($client->residency_status) {
                $residencyStatusCode = ($client->residency_status === 'resident') ? 1 : 2;
                $sheet->setCellValue('J' . $row, $residencyStatusCode);
            }

            $row++;
        }
    }

    private function fillOwner($sheet)
    {
        if (!$sheet) return;
        $row = 2;
        $legalClients = $this->contracts->map->client->unique('id')->where('type', 'legal');

        foreach ($legalClients as $client) {
            if ($client->owners && count($client->owners) > 0) {
                foreach ($client->owners as $owner) {
                    $sheet->setCellValue('A' . $row, $client->id);
                    $sheet->setCellValue('B' . $row, $owner->id);
                    $sheet->setCellValue('C' . $row, ($owner->type === 'legal' ? 'իրավաբանական անձ' : 'ֆիզիկական անձ'));

                    $ownerName = ($owner->type === 'legal')
                        ? ($owner->company_name . ' ' . $owner->legal_form)
                        : trim($owner->name . ' ' . $owner->surname . ' ' . $owner->middle_name);

                    $sheet->setCellValue('D' . $row, $ownerName);
                    $sheet->setCellValue('E' . $row, ($owner->type === 'legal' ? $owner->tax_number : $owner->passport_series));
                    $row++;
                }
            }
        }
    }

    private function fillCredit($sheet)
    {
        if (!$sheet) return;
        $row = 2;
        // Snapshot cutoff for classification lookups: the last day the period covers.
        // The nightly classification job runs at 00:00 Yerevan time and stamps its
        // ClassificationHistory row with the moment it actually executes (a few
        // seconds after midnight), even though it computes that day's state. A
        // pure whereDate() cutoff would exclude that row, so extend the cutoff
        // with a grace window past midnight to still catch it.
//        $classificationAsOf = Carbon::parse($this->to)->subDay()->endOfDay()->subMinutes(10);
        $classificationAsOf = Carbon::parse($this->from)->endOfDay();

        foreach ($this->contracts as $contract) {
            $sheet->setCellValue('A' . $row, $contract->client_id);
            $sheet->setCellValue('B' . $row, $contract->num);

            $this->setDateCellValue($sheet, 'C' . $row, $contract->date);
            $lastRegularPayment = $contract->payments()
                ->where('type', 'regular')
                ->orderByDesc('to_date')
                ->first();

            $this->setDateCellValue(
                $sheet,
                'D' . $row,
                $lastRegularPayment?->to_date ?? ($contract->deadline ? $contract->deadline : '2999-01-01')
            );

            $lastPaymentDate = null;
            $totalPaid = 0;
            $mainJournal = DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $contract->id)
                ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
                ->select('id')
                ->first();

            if ($mainJournal) {
                $lastMotherPayment = DocumentJournal::where('journalable_type', DocumentJournal::class)
                    ->where('journalable_id', $mainJournal->id)
                    ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
                    ->where('date', '>=', $this->from)
                    ->where('date', '<=', $this->to)
                    ->latest('date')
                    ->first();


                if ($lastMotherPayment) {
                    $lastPaymentDate = $lastMotherPayment->date;
                }

                if ($contract->status == 'completed') {
                    $totalPaid = $contract->mother;
                } else {
                    $totalPaid = DocumentJournal::where('journalable_type', DocumentJournal::class)
                        ->where('journalable_id', $mainJournal->id)
                        ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
                        ->where('date', '<=', $this->to)
                        ->sum('amount_amd');
                }

            }
            // Some closed contracts may have principal closed without detailed PAY_MOTHER_AMOUNT rows.
            // Keep I column meaningful by deriving paid principal from contract figures when needed.
            $derivedPaidFromBalance = max(
                0,
                (float)($contract->contract_amount ?? 0) - max(0, (float)($contract->provided_amount ?? 0))
            );
            if ((float)$totalPaid <= 0 && $derivedPaidFromBalance > 0) {
                $totalPaid = $derivedPaidFromBalance;
            }

            if ($lastMotherPayment) {
                $lastPaymentDate = $lastMotherPayment->date;
            } elseif ($contract->status === 'completed' || $contract->status === 'executed') {
                $lastPaymentDate = $contract->closed_at;
            }
            $this->setDateCellValue($sheet, 'E' . $row, $lastPaymentDate);
//            $categoryName = $contract->category->name ?? '';
//
//            $creditType = match ($categoryName) {
//                'car'           => 'Մեքենայի գրավով վարկ',
//                'gold'          => 'Ոսկու գրավով վարկ',
//                'car-purchase'  => 'ավտովարկ',
//                'electronics'   => 'Անշարժ գույքի գրավով վարկ',
//                default         => 'վարկ',
//            };
            $sheet->setCellValue('F' . $row, '001');
            $sheet->setCellValue('G' . $row, $contract->contract_amount);
            $sheet->setCellValue('H' . $row, $contract->mother);

            $this->setIntegerCellValue($sheet, 'I' . $row, $totalPaid);

            $unpaidPrepayments = Prepayment::where('contract_id', $contract->id)
                ->where('status', 'unpaid')
                ->selectRaw('SUM(principal_amount + partial_amount) as total')
                ->value('total') ?? 0;

            $this->setIntegerCellValue($sheet, 'J' . $row, max(0, $contract->provided_amount - $unpaidPrepayments));

            $overdueMother = 0;
            $overdueInterest = 0;

            if ($contract->payment_type == 'amortized') {
                $overdueMother = $contract->payments()
                    ->where('status', 'initial')
                    ->where('date', '<', $this->to)
                    ->sum('principal_payment');

                $overdueInterest = $contract->payments()
                    ->where('status', 'initial')
                    ->where('date', '<', $this->to)
                    ->sum('interest_payment');
            } else {
                if ($contract->deadline && Carbon::parse($contract->deadline)->lt(Carbon::parse($this->to))) {
                    $overdueMother = max(0, $contract->provided_amount);
                }
                $overdueInterest = $contract->payments()
                    ->where('status', 'initial')
                    ->where('date', '<', $this->from)
                    ->sum('amount');
            }

            // Match the "overdue" contract scope (Contract::scopeStatus): a debt
            // is only reported as overdue when it exceeds MIN_OVERDUE_AMD.
            if (($overdueMother + $overdueInterest) <= self::MIN_OVERDUE_AMD) {
                $overdueMother = 0;
                $overdueInterest = 0;
            }

            $this->setIntegerCellValue($sheet, 'K' . $row, $overdueMother);
            $this->setIntegerCellValue($sheet, 'L' . $row, $overdueInterest);

            //  M , W
            $firstOverdue = $contract->payments()
                ->where('status', 'initial')
                ->where('date', '<', $this->to)
                ->oldest('date')
                ->first();

            if ($firstOverdue && ($overdueMother > 0 || $overdueInterest > 0)) {
                $overdueDate = Carbon::parse($firstOverdue->date)->addDay();
                $this->setDateCellValue($sheet, 'M' . $row, $overdueDate);
                $days = Carbon::parse($firstOverdue->date)->diffInDays(Carbon::parse($this->to));
                $sheet->setCellValue('W' . $row, $days-1);
            } else {
                $sheet->setCellValue('W' . $row, 0);
            }

            // N, O, P, Q, U
            $sheet->setCellValue('N' . $row, 'AMD');

            $riskClassTitle = $contract->client->classification->name ?? '';

            $riskClassCode = match (strtolower($riskClassTitle)) {
                'standard'    => '01',
                'monitored'   => '02',
                'substandard' => '03',
                'suspicious'  => '04',
                'loss'        => '05',
                default       => '',
            };

            $sheet->setCellValue('O' . $row, $riskClassCode);

            // X: last risk classification date ("Վարկի վերջին դասակարգման ամսաթիվ").
            // Left blank when the client has no risk class, and also for "standard"
            // (01) — only non-standard classes (02-05) carry a classification date.
            $requiresClassificationDate = !in_array($riskClassCode, ['', '01'], true);
            $lastClassificationDate = null;
            if ($requiresClassificationDate && $contract->client->classification_id) {
                $lastClassificationDate = ClassificationHistory::query()
                    ->where('client_id', $contract->client_id)
                    //->where('classification_id', $contract->client->classification_id)
                    ->where('date', '<=', $classificationAsOf)
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->value('date');
            }
            if ($lastClassificationDate) {
                $this->setDateCellValue($sheet, 'X' . $row, $lastClassificationDate);
            } elseif ($requiresClassificationDate) {
                $this->validationErrors[] = sprintf(
                    'Credit row %d (contract %s, client %s): risk class "%s" is set but the last classification date (X) is missing.',
                    $row,
                    $contract->num,
                    $contract->client_id,
                    $riskClassTitle
                );
                continue;
            }

            if ($contract->status) {
                $contractStatusCode = ($contract->status === 'completed') ? 1 : 0;
                $sheet->setCellValue('P' . $row, $contractStatusCode);
            }
//            $sheet->setCellValue('P' . $row, ($contract->status === 'completed' ? 'մարված' : 'գործող'));
            $sheet->setCellValue('Q' . $row, $contract->interest_rate * 365);
            $this->setDateCellValue($sheet, 'U' . $row, $contract->created_at);

            $row++;
        }
    }

    private function fillCollateral($sheet)
    {
        if (!$sheet) return;
        $row = 2;
        foreach ($this->contracts as $contract) {
            $items = $contract->items;
            if (!$items || $items->isEmpty()) {
                continue;
            }

            $sheet->setCellValue('A' . $row, $contract->num);

            $totalEstimatedAmount = (float) $items->sum(function ($item) {
                return (float) ($item->estimated_amount ?? 0);
            });
            if ($totalEstimatedAmount <= 0) {
                $totalEstimatedAmount = (float) ($contract->estimated_amount ?? 0);
            }
            $sheet->setCellValue('B' . $row, $totalEstimatedAmount);
            $sheet->setCellValue('C' . $row, 'AMD');

            $firstItem = $items->first();
            $collateralCode = '';
            if ($firstItem && $firstItem->category) {
                switch (strtolower($firstItem->category->name)) {
                    case 'gold':
                        $collateralCode = '01';
                        break;
                    case 'car':
                    case 'car-purchase':
                        $collateralCode = '10';
                        break;
                    case 'other movable property':
                        $collateralCode = '11';
                        break;
                    // Real estate is stored under the legacy category name
                    // "electronics" (title: Անշարժ գույք).
                    case 'electronics':
                    case 'real estate':
                        $collateralCode = '12';
                        break;
                    case 'guarantee':
                        $collateralCode = '13';
                        break;
                }
            }
            $sheet->setCellValue('D' . $row, $collateralCode);

            $row++;
        }
    }

    private function fillGuarantor($sheet)
    {
        if (!$sheet) return;
        $row = 2;
        foreach ($this->contracts as $contract) {
            foreach ($contract->guarantors as $g) {
                $sheet->setCellValue('A' . $row, $contract->num);
                $sheet->setCellValue('B' . $row, $g->id);
                $sheet->setCellValue('C' . $row, ($g->type === 'legal' ? 'իրավաբանական անձ' : 'ֆիզիկական անձ'));

                $gName = ($g->type === 'legal')
                    ? ($g->company_name . ' ' . $g->legal_form)
                    : trim($g->name . '^' . $g->surname . ($g->middle_name ? '^' . $g->middle_name : ''));

                $sheet->setCellValue('D' . $row, $gName);
                $sheet->setCellValue('E' . $row, ($g->type === 'legal' ? $g->tax_number : $g->passport_series));
                if ($g->residency_status) {
                    $sheet->setCellValue('K' . $row, $g->residency_status === 'resident' ? 1 : 2);
                }
                $sheet->setCellValue('T' . $row, 'AMD');
                $row++;
            }
        }
    }

    /**
     * Pre-export QA: checks every filled row against the ACRA rules and
     * collects a summary. Errors block the file; warnings are only logged.
     */
    private function validateSheets($spreadsheet): void
    {
        $this->validateDebtorSheet($spreadsheet->getSheetByName('Debtor'));
        $this->validateCreditSheet($spreadsheet->getSheetByName('Credit'));
        $this->validateCollateralSheet($spreadsheet->getSheetByName('Collateral'));
        $this->validateGuarantorSheet($spreadsheet->getSheetByName('Guarantor'));
    }

    private function cellValue($sheet, string $column, int $row)
    {
        return $sheet->getCell($column . $row)->getValue();
    }

    private function isBlank($value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    /**
     * FirstName^LastName or FirstName^LastName^Patronymic,
     * caret-separated with no spaces around the carets.
     */
    private function isCaretName($name): bool
    {
        $name = (string) $name;
        if (!str_contains($name, '^') || preg_match('/\s\^|\^\s/u', $name)) {
            return false;
        }
        $parts = explode('^', $name);
        if (count($parts) < 2 || count($parts) > 3) {
            return false;
        }
        foreach ($parts as $part) {
            if (trim($part) === '') {
                return false;
            }
        }
        return true;
    }

    private function validateDebtorSheet($sheet): void
    {
        if (!$sheet) return;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $clientId = $this->cellValue($sheet, 'A', $row);
            if ($this->isBlank($clientId)) continue;
            $label = "Debtor row {$row} (client {$clientId})";

            $typeCode = $this->cellValue($sheet, 'B', $row);
            $name = $this->cellValue($sheet, 'C', $row);
            $isIndividual = in_array((int) $typeCode, [21, 22], true);

            if ($this->isBlank($typeCode)) {
                $this->validationErrors[] = "{$label}: debtor type code (B) is missing.";
            }
            if ($this->isBlank($name)) {
                $this->validationErrors[] = "{$label}: name (C) is missing.";
            } elseif ($isIndividual && !$this->isCaretName($name)) {
                $this->validationErrors[] = "{$label}: name (C) must be FirstName^LastName^Patronymic, got \"{$name}\".";
            }
            if ($this->isBlank($this->cellValue($sheet, 'D', $row))) {
                $this->validationErrors[] = "{$label}: passport/tax number (D) is missing.";
            }
            if ($isIndividual && $this->isBlank($this->cellValue($sheet, 'E', $row))) {
                $this->validationErrors[] = "{$label}: date of birth (E) is missing.";
            }
        }
    }

    private function validateCreditSheet($sheet): void
    {
        if (!$sheet) return;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $clientId = $this->cellValue($sheet, 'A', $row);
            $num = $this->cellValue($sheet, 'B', $row);
            if ($this->isBlank($clientId) && $this->isBlank($num)) continue;
            $label = "Credit row {$row} (contract {$num})";

            $required = [
                'A' => 'client id',
                'B' => 'contract number',
                'C' => 'contract date',
                'D' => 'deadline',
                'N' => 'currency',
                'O' => 'risk class',
            ];
            foreach ($required as $column => $field) {
                if ($this->isBlank($this->cellValue($sheet, $column, $row))) {
                    $this->validationErrors[] = "{$label}: {$field} ({$column}) is missing.";
                }
            }

            $overdueTotal = (float) $this->cellValue($sheet, 'K', $row)
                + (float) $this->cellValue($sheet, 'L', $row);
            if ($overdueTotal > 0 && $this->isBlank($this->cellValue($sheet, 'M', $row))) {
                $this->validationErrors[] = "{$label}: overdue amounts (K/L) are set but the overdue start date (M) is empty.";
            }
        }
    }

    private function validateCollateralSheet($sheet): void
    {
        if (!$sheet) return;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $num = $this->cellValue($sheet, 'A', $row);
            if ($this->isBlank($num)) continue;
            $label = "Collateral row {$row} (contract {$num})";

            $amount = $this->cellValue($sheet, 'B', $row);
            if ($this->isBlank($amount) || (float) $amount <= 0) {
                $this->validationErrors[] = "{$label}: collateral value (B) must be a positive amount.";
            }
            if ($this->isBlank($this->cellValue($sheet, 'C', $row))) {
                $this->validationErrors[] = "{$label}: currency (C) is missing.";
            }
            if ($this->isBlank($this->cellValue($sheet, 'D', $row))) {
                $this->validationErrors[] = "{$label}: property code (D) is missing — unmapped collateral category.";
            }
        }
    }

    private function validateGuarantorSheet($sheet): void
    {
        if (!$sheet) return;
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $num = $this->cellValue($sheet, 'A', $row);
            $name = $this->cellValue($sheet, 'D', $row);
            if ($this->isBlank($num) && $this->isBlank($name)) continue;
            $label = "Guarantor row {$row} (contract {$num})";

            if ($this->isBlank($name)) {
                $this->validationErrors[] = "{$label}: guarantor name (D) is missing.";
            } elseif ($this->cellValue($sheet, 'C', $row) === 'ֆիզիկական անձ' && !$this->isCaretName($name)) {
                $this->validationErrors[] = "{$label}: name (D) must be FirstName^LastName^Patronymic, got \"{$name}\".";
            }
            if ($this->isBlank($this->cellValue($sheet, 'E', $row))) {
                $this->validationErrors[] = "{$label}: passport/tax number (E) is missing.";
            }
        }
    }
}
