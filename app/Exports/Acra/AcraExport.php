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


use App\Models\Contract;
use App\Models\Client;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class AcraExport
{
    protected $contracts;
    protected $from;
    protected $to;
    protected $customerCode = 'ACC';

    public function __construct($contracts, $from, $to)
    {
        $this->contracts = $contracts;
        $this->from = $from; // StartDate
        $this->to = $to;     // EndDate
    }

    /**
     * Sheet 1: PackageInfo
     */
    private function fillPackageInfo($sheet)
    {
        $sheet->setCellValue('A1', 'SourceName')->setCellValue('B1', $this->customerCode);
        $sheet->setCellValue('A2', 'StartDate')->setCellValue('B2', $this->from);
        $sheet->setCellValue('A3', 'EndDate')->setCellValue('B3', $this->to);
        $sheet->setCellValue('A4', 'CreatedDateTime')->setCellValue('B4', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('A5', 'FileCount')->setCellValue('B5', 1);
        $sheet->setCellValue('A6', 'FileNum')->setCellValue('B6', 1);

        $sheet->getStyle('A1:B6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    /**
     * Sheet 2: Debtor (Վարկառուներ)
     */
    private function fillDebtor($sheet)
    {
        $clients = $this->contracts->map->client->unique('id');
        $row = 2;

        foreach ($clients as $client) {
            $sheet->setCellValue('A' . $row, $client->id);

            // Կարգավիճակ
            $type = ($client->type === 'legal') ? 'իրավաբանական անձ' : 'ֆիզիկական անձ';
            $sheet->setCellValue('B' . $row, $type);

            // Անվանում / ԱԱՀ
            $name = ($client->type === 'legal')
                ? ($client->company_name . ' ' . $client->legal_form)
                : trim($client->name . ' ' . $client->surname . ' ' . $client->middle_name);
            $sheet->setCellValue('C' . $row, $name);

            // ՀՎՀՀ կամ Անձնագիր
            $sheet->setCellValue('D' . $row, ($client->type === 'legal') ? $client->tax_number : $client->passport_series);

            // Ֆիզ. անձանց լրացուցիչ տվյալներ
            if ($client->type !== 'legal') {
                $sheet->setCellValue('E' . $row, $client->date_of_birth ? Carbon::parse($client->date_of_birth)->format('d.m.Y') : '');
                $sheet->setCellValue('F' . $row, $client->passport_issued ? Carbon::parse($client->passport_issued)->format('d.m.Y') : '');
                $sheet->setCellValue('G' . $row, substr($client->passport_series, 0, 3)); // Օրինակ՝ 011
            }

            $sheet->setCellValue('H' . $row, $client->social_card_number);
            $residency = ($client->residency_status === 'resident') ? 'ռեզիդենտ' : 'ոչ ռեզիդենտ';
            $sheet->setCellValue('J' . $row, $residency);

            $row++;
        }
    }

    /**
     * Sheet 4: Owner (Սեփականատերեր - միայն իրավաբանական անձանց համար)
     */
    private function fillOwner($sheet)
    {
        $row = 2;
        $legalClients = $this->contracts->map->client->unique('id')->where('type', 'legal');

        foreach ($legalClients as $client) {
            // Ենթադրենք ունենք բաժնետերերի կապ
            if ($client->owners) {
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

    /**
     * Sheet 6: Guarantor (Երաշխավորներ)
     */
    private function fillGuarantor($sheet)
    {
        $row = 2;
        foreach ($this->contracts as $contract) {
            foreach ($contract->guarantors as $guarantor) {
                // Բացառել պետական մարմինները
                if ($guarantor->is_government) continue;

                $sheet->setCellValue('A' . $row, $contract->id);
                $sheet->setCellValue('B' . $row, $guarantor->id);
                $sheet->setCellValue('C' . $row, ($guarantor->type === 'legal' ? 'իրավաբանական անձ' : 'ֆիզիկական անձ'));

                $gName = ($guarantor->type === 'legal')
                    ? ($guarantor->company_name . ' ' . $guarantor->legal_form)
                    : trim($guarantor->name . ' ' . $guarantor->surname . ' ' . $guarantor->middle_name);
                $sheet->setCellValue('D' . $row, $gName);

                $sheet->setCellValue('E' . $row, ($guarantor->type === 'legal' ? $guarantor->tax_number : $guarantor->passport_series));

                // Արժույթ (001 = AMD, 002 = USD)
                $currency = ($contract->currency === 'USD') ? '002' : '001';
                $sheet->setCellValue('T' . $row, $currency);
                $row++;
            }
        }
    }
}
