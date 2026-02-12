<?php

//namespace App\Exports\Acra;
//
//use Maatwebsite\Excel\Concerns\FromCollection;
//use Maatwebsite\Excel\Concerns\WithMultipleSheets;
//
//class AcraExport implements WithMultipleSheets
//{
//    protected $contracts;
//    protected $startDate;
//    protected $endDate;
//
//    public function __construct($contracts, $startDate, $endDate)
//    {
//        $this->contracts = $contracts;
//        $this->startDate = $startDate;
//        $this->endDate = $endDate;
//    }
//
//    public function sheets(): array
//    {
//        return [
//            'PackageInfo'  => new PackageInfoSheet($this->startDate, $this->endDate),
//            'Debtor'       => new DebtorSheet($this->contracts),
//            'Interrelated' => new InterrelatedSheet($this->contracts),
//            'Owner'        => new OwnerSheet($this->contracts),
//            'Credit'       => new CreditSheet($this->contracts),
//            'Collateral'   => new CollateralSheet($this->contracts),
//            'Guarantor'    => new OwnerSheet($this->contracts),
//        ];
//    }
//}
namespace App\Exports\Acra;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Models\Contract;

class AcraExport
{
    protected $contracts;
    protected $from;
    protected $to;
    protected $customerCode = 'ACC';

    public function __construct($contracts, $from, $to)
    {
        $this->contracts = $contracts;
        $this->from = $from;
        $this->to = $to;
    }

    public function export()
    {
        // Բեռնում ենք template-ը storage-ից
        $path = base_path('acra_template.xlsx');
        if (!file_exists($path)) {
            throw new \Exception("Template file not found at: " . $path);
        }

        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        // 1. PackageInfo
        $this->fillPackageInfo($spreadsheet->getSheetByName('PackageInfo'));

        // 2. Debtor
        $this->fillDebtor($spreadsheet->getSheetByName('Debtor'));

        // 3. Owner (Մասնակիցներ)
        $this->fillOwner($spreadsheet->getSheetByName('Owner'));

        // 4. Credit
        $this->fillCredit($spreadsheet->getSheetByName('Credit'));

        // 5. Collateral
        $this->fillCollateral($spreadsheet->getSheetByName('Collateral'));

        // 6. Guarantor
        $this->fillGuarantor($spreadsheet->getSheetByName('Guarantor'));

        // Ֆայլի անվանումը ըստ պահանջի: ACC_01_01_YYYYMMDDHHMMSS
        $packageId = now()->format('YmdHis');
        $fileName = "{$this->customerCode}_01_01_{$packageId}.xlsx";
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return [
            'path' => $filePath,
            'name' => $fileName
        ];
    }

    private function fillPackageInfo($sheet)
    {
        if (!$sheet) return;
        $sheet->setCellValue('B1', $this->customerCode);
        $sheet->setCellValue('B2', Carbon::parse($this->from)->format('Y-m-d'));
        $sheet->setCellValue('B3', Carbon::parse($this->to)->format('Y-m-d'));
        $sheet->setCellValue('B4', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('B5', 1);
        $sheet->setCellValue('B6', 1);
    }

    private function fillDebtor($sheet)
    {
        if (!$sheet) return;
        $clients = $this->contracts->map->client->unique('id');
        $row = 2;
        foreach ($clients as $client) {
            $sheet->setCellValue('A' . $row, $client->id);

            $type = ($client->type === 'legal') ? 'իրավաբանական անձ' : 'ֆիզիկական անձ';
            $sheet->setCellValue('B' . $row, $type);

            $name = ($client->type === 'legal')
                ? ($client->company_name . ' ' . $client->legal_form)
                : trim($client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : ''));
            $sheet->setCellValue('C' . $row, $name);

            $sheet->setCellValue('D' . $row, ($client->type === 'legal') ? $client->tax_number : $client->passport_series);

            if ($client->type !== 'legal') {
                // Ավելացնում ենք ստուգում նախքան parse անելը
                $sheet->setCellValue('E' . $row, $this->formatDate($client->date_of_birth));
                $sheet->setCellValue('F' . $row, $this->formatDate($client->passport_issued));
                $sheet->setCellValue('G' . $row, $client->passport_given_by ?? '');
            }

            $sheet->setCellValue('H' . $row, $client->social_card_number);
            $sheet->setCellValue('J' . $row, ($client->residency_status === 'resident' ? 'ռեզիդենտ' : 'ոչ ռեզիդենտ'));
            $row++;
        }
    }

    /**
     * Օժանդակ մեթոդ ամսաթվերի անվտանգ ֆորմատավորման համար
     */
    private function formatDate($date)
    {
        if (!$date) return '';

        try {
            // Եթե արդեն Carbon օբյեկտ է (Laravel casts-ի շնորհիվ)
            if ($date instanceof \Carbon\Carbon) {
                return $date->format('d.m.Y');
            }
            // Եթե տեքստ է, փորձում ենք parse անել
            return \Carbon\Carbon::parse($date)->format('d.m.Y');
        } catch (\Exception $e) {
            return ''; // Սխալ տվյալի դեպքում վերադարձնում ենք դատարկ
        }
    }
    private function fillOwner($sheet)
    {
        if (!$sheet) return;
        $row = 2;
        $legalClients = $this->contracts->map->client->unique('id')->where('type', 'legal');

        foreach ($legalClients as $client) {
            // Եթե ունեք owners հարաբերություն Client մոդելում
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
        foreach ($this->contracts as $contract) {
            $sheet->setCellValue('A' . $row, $contract->client_id);
            $sheet->setCellValue('B' . $row, $contract->num);
            $sheet->setCellValue('C' . $row, Carbon::parse($contract->date)->format('d.m.Y'));
            $sheet->setCellValue('D' . $row, $contract->deadline ? Carbon::parse($contract->deadline)->format('d.m.Y') : '01.01.2999');
            $sheet->setCellValue('E' . $row, $contract->closed_at ? Carbon::parse($contract->closed_at)->format('d.m.Y') : '');

            $sheet->setCellValue('F' . $row, 'վարկ');
            $sheet->setCellValue('G' . $row, $contract->contract_amount);
            $sheet->setCellValue('H' . $row, $contract->provided_amount);

            // Մարված մայր գումար (կուտակային)
            $totalPaid = $contract->payments()->where('status', 'completed')->sum('mother');
            $sheet->setCellValue('I' . $row, $totalPaid);
            $sheet->setCellValue('J' . $row, $contract->provided_amount - $totalPaid);

            // Ժամկետանցներ
            if ($contract->is_overdue) {
                $overdueMother = $contract->payments()->where('status', 'initial')->whereDate('date', '<', today())->sum('mother');
                $overdueInterest = $contract->payments()->where('status', 'initial')->whereDate('date', '<', today())->sum('amount');
                $firstOverdue = $contract->payments()->where('status', 'initial')->whereDate('date', '<', today())->oldest('date')->first();

                $sheet->setCellValue('K' . $row, $overdueMother);
                $sheet->setCellValue('L' . $row, $overdueInterest);
                $sheet->setCellValue('M' . $row, Carbon::parse($firstOverdue->date)->format('d.m.Y'));

                $days = Carbon::parse($firstOverdue->date)->diffInDays(today());
                $sheet->setCellValue('W' . $row, $days);
            }

            $sheet->setCellValue('N' . $row, ($contract->currency === 'USD' ? '002' : '001'));
            $sheet->setCellValue('O' . $row, 'Ստանդարտ');
            $sheet->setCellValue('P' . $row, ($contract->status === 'completed' ? 'մարված' : 'գործող'));
            $sheet->setCellValue('Q' . $row, $contract->interest_rate);
            $sheet->setCellValue('U' . $row, Carbon::parse($contract->date)->format('d.m.Y'));

            $row++;
        }
    }

    private function fillCollateral($sheet)
    {
        if (!$sheet) return;
        $row = 2;
        foreach ($this->contracts as $contract) {
            foreach ($contract->items as $item) {
                $sheet->setCellValue('A' . $row, $contract->num);
                $sheet->setCellValue('B' . $row, $item->pivot->estimated_amount ?? $contract->estimated_amount);
                $sheet->setCellValue('C' . $row, ($contract->currency === 'USD' ? '002' : '001'));
                $sheet->setCellValue('D' . $row, $item->subcategory . ' ' . $item->description);
                $row++;
            }
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
                    : trim($g->name . ' ' . $g->surname . ($g->middle_name ? ' ' . $g->middle_name : ''));

                $sheet->setCellValue('D' . $row, $gName);
                $sheet->setCellValue('E' . $row, ($g->type === 'legal' ? $g->tax_number : $g->passport_series));
                $sheet->setCellValue('T' . $row, ($contract->currency === 'USD' ? '002' : '001'));
                $row++;
            }
        }
    }
}
