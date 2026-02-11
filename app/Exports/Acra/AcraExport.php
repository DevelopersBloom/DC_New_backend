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
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AcraExport
{
    protected $contracts;
    protected $from;
    protected $to;

    public function __construct($contracts, $from, $to)
    {
        $this->contracts = $contracts;
        $this->from = $from;
        $this->to = $to;
    }

    public function export()
    {
        // 1. Բացում ենք Template ֆայլը
        $path = base_path('acra_template.xlsx');
//        $path = storage_path('app/templates/acra_template.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        // ---------------------------
        // 1. SHEET: PackageInfo
        // ---------------------------
        $pInfo = $spreadsheet->getSheetByName('PackageInfo');
        if ($pInfo) {
            $pInfo->setCellValue('B1', 'TMP');
            $pInfo->setCellValue('B2', $this->from);
            $pInfo->setCellValue('B3', $this->to);
            $pInfo->setCellValue('B4', now()->format('Y-m-d H:i:s'));
            $pInfo->setCellValue('B5', 1);
            $pInfo->setCellValue('B6', 1);
        }

        // ---------------------------
        // 2. SHEET: Debtor
        // ---------------------------
        $debtorSheet = $spreadsheet->getSheetByName('Debtor');
        if ($debtorSheet) {
            $clients = $this->contracts->map->client->unique('id');
            $row = 2;
            foreach ($clients as $client) {
                $debtorSheet->setCellValue('A' . $row, $client->id);
                $debtorSheet->setCellValue('B' . $row, '1'); // Իրավաբանական կարգավիճակ
                $debtorSheet->setCellValue('C' . $row, $client->name . ' ' . $client->surname);
                $debtorSheet->setCellValue('D' . $row, $client->passport);
                $debtorSheet->setCellValue('E' . $row, $client->birth_date);
                $debtorSheet->setCellValue('F' . $row, $client->passport_date);
                $debtorSheet->setCellValue('G' . $row, $client->passport_by);
                $debtorSheet->setCellValue('H' . $row, $client->ssn);
                $debtorSheet->setCellValue('I' . $row, $client->gender == 'male' ? '1' : '2');
                $debtorSheet->setCellValue('J' . $row, '1'); // Ռեզիդենտություն
                $debtorSheet->setCellValue('K' . $row, '10'); // Սեփականության ձև
                $debtorSheet->setCellValue('L' . $row, $client->address);
                $debtorSheet->setCellValue('M' . $row, '8'); // Գործունեության ոլորտ
                $debtorSheet->setCellValue('R' . $row, $client->id_card);
                $debtorSheet->setCellValue('S' . $row, $client->id_card_date);
                $debtorSheet->setCellValue('T' . $row, $client->id_card_by);
                $row++;
            }
        }

        // ---------------------------
        // 3. SHEET: Credit
        // ---------------------------
        $creditSheet = $spreadsheet->getSheetByName('Credit');
        if ($creditSheet) {
            $row = 2;
            foreach ($this->contracts as $contract) {
                $creditSheet->setCellValue('A' . $row, $contract->client_id);
                $creditSheet->setCellValue('B' . $row, $contract->num);
                $creditSheet->setCellValue('C' . $row, $contract->date);
                $creditSheet->setCellValue('D' . $row, $contract->deadline);
                $creditSheet->setCellValue('E' . $row, $contract->closed_at);
                $creditSheet->setCellValue('F' . $row, '15'); // Վարկի տեսակ
                $creditSheet->setCellValue('G' . $row, $contract->provided_amount);
                $creditSheet->setCellValue('H' . $row, $contract->provided_amount);
                $creditSheet->setCellValue('I' . $row, $contract->provided_amount - $contract->mother);
                $creditSheet->setCellValue('J' . $row, $contract->mother);
                $creditSheet->setCellValue('K' . $row, $contract->overdue_balance ?? 0);
                $creditSheet->setCellValue('L' . $row, $contract->overdue_interest ?? 0);
                $creditSheet->setCellValue('N' . $row, '1'); // Արժույթ (AMD)
                $creditSheet->setCellValue('O' . $row, '1'); // Ռիսկի դաս
                $creditSheet->setCellValue('P' . $row, $contract->status == 'initial' ? '1' : '2');
                $creditSheet->setCellValue('Q' . $row, $contract->interest_rate);
                $creditSheet->setCellValue('R' . $row, '8'); // Ոլորտ
                $creditSheet->setCellValue('S' . $row, '1'); // Վայր
                $creditSheet->setCellValue('U' . $row, $contract->date);
                $creditSheet->setCellValue('W' . $row, $contract->delay_days ?? 0);
                $creditSheet->setCellValue('X' . $row, now()->format('Y-m-d'));
                $row++;
            }
        }

        // ---------------------------
        // 4. SHEET: Collateral
        // ---------------------------
        $collateralSheet = $spreadsheet->getSheetByName('Collateral');
        if ($collateralSheet) {
            $row = 2;
            foreach ($this->contracts as $contract) {
                foreach ($contract->items as $item) {
                    $collateralSheet->setCellValue('A' . $row, $contract->num);
                    $collateralSheet->setCellValue('B' . $row, $item->provided_amount);
                    $collateralSheet->setCellValue('C' . $row, '1'); // Արժույթ
                    $collateralSheet->setCellValue('D' . $row, $item->subcategory . ' ' . $item->description);
                    $row++;
                }
            }
        }

        // ---------------------------
        // 5. SHEET: Guarantor
        // ---------------------------
        $guarantorSheet = $spreadsheet->getSheetByName('Guarantor');
        if ($guarantorSheet) {
            $row = 2;
            foreach ($this->contracts as $contract) {
                foreach ($contract->guarantors as $g) {
                    $guarantorSheet->setCellValue('A' . $row, $contract->num);
                    $guarantorSheet->setCellValue('B' . $row, $g->id);
                    $guarantorSheet->setCellValue('C' . $row, '1');
                    $guarantorSheet->setCellValue('D' . $row, $g->name . ' ' . $g->surname);
                    $guarantorSheet->setCellValue('E' . $row, $g->passport);
                    $guarantorSheet->setCellValue('F' . $row, $g->birth_date);
                    $guarantorSheet->setCellValue('G' . $row, $g->passport_date);
                    $guarantorSheet->setCellValue('H' . $row, $g->passport_by);
                    $guarantorSheet->setCellValue('I' . $row, $g->ssn);
                    $guarantorSheet->setCellValue('J' . $row, $g->gender == 'male' ? '1' : '2');
                    $guarantorSheet->setCellValue('L' . $row, '10');
                    $guarantorSheet->setCellValue('M' . $row, $g->address);
                    $guarantorSheet->setCellValue('T' . $row, '1'); // Արժույթ
                    $guarantorSheet->setCellValue('U' . $row, $g->id_card);
                    $guarantorSheet->setCellValue('V' . $row, $g->id_card_date);
                    $guarantorSheet->setCellValue('W' . $row, $g->id_card_by);
                    $row++;
                }
            }
        }

        // Պահպանում ենք ֆայլը
        $fileName = 'ACRA_' . now()->format('Ymd_His') . '.xlsx';
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }
}
