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
    protected $customerCode = 'XYZ';

    public function __construct($contracts, $from, $to)
    {
        $this->contracts = $contracts;
        $this->from = $from;
        $this->to = $to;
    }

    public function export()
    {
        $path = base_path('acra_template.xlsx');
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);

        // 1. PackageInfo
        $this->fillPackageInfo($spreadsheet->getSheetByName('PackageInfo'));

        // 2. Debtor
        $this->fillDebtor($spreadsheet->getSheetByName('Debtor'));

        // 3. Credit
        $this->fillCredit($spreadsheet->getSheetByName('Credit'));

        // 4. Collateral
        $this->fillCollateral($spreadsheet->getSheetByName('Collateral'));

        // 5. Guarantor
        $this->fillGuarantor($spreadsheet->getSheetByName('Guarantor'));

        // Ֆայլի անվանումը ըստ ուղեցույցի 2.2 կետի
        $packageId = now()->format('YmdHis');
        $fileName = "{$this->customerCode}_01_01_{$packageId}.xlsx";
        $filePath = storage_path('app/public/' . $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    private function fillPackageInfo($sheet)
    {
        if (!$sheet) return;
        $sheet->setCellValue('B1', $this->customerCode);
        $sheet->setCellValue('B2', $this->from);
        $sheet->setCellValue('B3', $this->to);
        $sheet->setCellValue('B4', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('B5', 1);
        $sheet->setCellValue('B6', 1);
    }

    private function fillDebtor($sheet)
    {
        if (!$sheet) return;
        $clients = $this->contracts->map->client->unique('id');
        dd($this->contracts);
        $row = 2;
        foreach ($clients as $client) {
            $sheet->setCellValue('A' . $row, $client->id);
            $sheet->setCellValue('B' . $row, $client->is_legal_entity ? '2' : '1'); // 1-ֆիզ, 2-իրավ
            $sheet->setCellValue('C' . $row, $client->name . ' ' . $client->surname);
            $sheet->setCellValue('D' . $row, $client->passport_series);
            $sheet->setCellValue('E' . $row, $client->birth_date);
            $sheet->setCellValue('F' . $row, $client->passport_date);
            $sheet->setCellValue('G' . $row, $client->passport_by);
            $sheet->setCellValue('H' . $row, $client->ssn);
            $sheet->setCellValue('I' . $row, $client->gender == 'male' ? '1' : '2');
            $sheet->setCellValue('J' . $row, '1'); // 1-Ռեզիդենտ
            $sheet->setCellValue('K' . $row, '10'); // 10-Մասնավոր
            $sheet->setCellValue('L' . $row, $client->address);
            $sheet->setCellValue('M' . $row, '8'); // Ոլորտ
            $sheet->setCellValue('R' . $row, $client->id_card);
            $sheet->setCellValue('S' . $row, $client->id_card_date);
            $sheet->setCellValue('T' . $row, $client->id_card_by);
            $row++;
        }
    }

    private function fillCredit($sheet)
    {
        if (!$sheet) return;
        $row = 2;
        foreach ($this->contracts as $contract) {
            // Հաշվարկում ենք մնացորդը և ժամկետանց օրերը ըստ ձեր ContractTrait-ի կամ մոդելի
            $overdueDays = 0;
            if ($contract->is_overdue) {
                $earliestOverduePayment = $contract->payments()
                    ->where('status', 'initial')
                    ->whereDate('date', '<', today())
                    ->orderBy('date', 'asc')
                    ->first();
                if ($earliestOverduePayment) {
                    $overdueDays = now()->diffInDays(Carbon::parse($earliestOverduePayment->date));
                }
            }

            $sheet->setCellValue('A' . $row, $contract->client_id);
            $sheet->setCellValue('B' . $row, $contract->num);
            $sheet->setCellValue('C' . $row, $contract->date);
            $sheet->setCellValue('D' . $row, $contract->deadline);
            $sheet->setCellValue('E' . $row, $contract->closed_at);
            $sheet->setCellValue('F' . $row, '15'); // Լոմբարդային վարկ
            $sheet->setCellValue('G' . $row, $contract->contract_amount);
            $sheet->setCellValue('H' . $row, $contract->provided_amount);
            $sheet->setCellValue('I' . $row, $contract->provided_amount - $contract->mother); // Մարված մայր գումար
            $sheet->setCellValue('J' . $row, $contract->mother); // Մայր գումարի մնացորդ
            $sheet->setCellValue('K' . $row, $contract->penalty_amount ?? 0); // Ժամկետանց մնացորդ
            $sheet->setCellValue('L' . $row, 0); // Ժամկետանց տոկոս
            $sheet->setCellValue('N' . $row, '1'); // AMD
            $sheet->setCellValue('O' . $row, '1'); // Ստանդարտ ռիսկ
            $sheet->setCellValue('P' . $row, $contract->status == 'completed' ? '2' : '1'); // 1-գործող, 2-մարված
            $sheet->setCellValue('Q' . $row, $contract->interest_rate);
            $sheet->setCellValue('R' . $row, '8'); // Սպառողական ոլորտ
            $sheet->setCellValue('S' . $row, '1'); // Երևան (կամ ըստ pawnshop-ի)
            $sheet->setCellValue('W' . $row, $overdueDays);
            $sheet->setCellValue('X' . $row, now()->format('Y-m-d'));
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
                $sheet->setCellValue('C' . $row, '1'); // AMD
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
                $sheet->setCellValue('C' . $row, '1'); // Գործող
                $sheet->setCellValue('D' . $row, $g->name . ' ' . $g->surname);
                $sheet->setCellValue('E' . $row, $g->passport_series);
                $sheet->setCellValue('F' . $row, $g->birth_date);
                $sheet->setCellValue('I' . $row, $g->ssn);
                $sheet->setCellValue('J' . $row, $g->gender == 'male' ? '1' : '2');
                $sheet->setCellValue('M' . $row, $g->address);
                $sheet->setCellValue('S' . $row, $contract->provided_amount); // Երաշխ. գումար
                $sheet->setCellValue('T' . $row, '1'); // AMD
                $row++;
            }
        }
    }
}
