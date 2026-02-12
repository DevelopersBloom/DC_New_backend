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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    public function export()
    {
        $spreadsheet = new Spreadsheet();

        // Էջերի ստեղծում և լրացում
        $this->fillPackageInfo($spreadsheet->getActiveSheet()->setTitle('PackageInfo'));
        $this->fillDebtor($spreadsheet->createSheet()->setTitle('Debtor'));
        $this->fillOwner($spreadsheet->createSheet()->setTitle('Owner'));
        $this->fillCredit($spreadsheet->createSheet()->setTitle('Credit')); // Ավելացված է
        $this->fillGuarantor($spreadsheet->createSheet()->setTitle('Guarantor'));

        // Ձևավորում
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach (range('A', $sheet->getHighestColumn()) as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        }

        // Ֆայլի անվանումը ըստ պահանջի: ACC_01_01_YYYYMMDDHHMMSS
        $fileName = $this->customerCode . '_01_01_' . now()->format('YmdHis') . '.xlsx';
        $tempFile = tempnam(sys_get_temp_dir(), $fileName);

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return [
            'path' => $tempFile,
            'name' => $fileName
        ];
    }

    private function fillPackageInfo($sheet)
    {
        $sheet->setCellValue('A1', 'SourceName')->setCellValue('B1', $this->customerCode);
        $sheet->setCellValue('A2', 'StartDate')->setCellValue('B2', Carbon::parse($this->from)->format('Y-m-d'));
        $sheet->setCellValue('A3', 'EndDate')->setCellValue('B3', Carbon::parse($this->to)->format('Y-m-d'));
        $sheet->setCellValue('A4', 'CreatedDateTime')->setCellValue('B4', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('A5', 'FileCount')->setCellValue('B5', 1);
        $sheet->setCellValue('A6', 'FileNum')->setCellValue('B6', 1);
    }

    private function fillDebtor($sheet)
    {
        $clients = $this->contracts->map->client->unique('id');
        $row = 2;

        foreach ($clients as $client) {
            $sheet->setCellValue('A' . $row, $client->id);

            // Սյուն B: Կարգավիճակ
            $type = ($client->type === 'legal') ? 'իրավաբանական անձ' : 'ֆիզիկական անձ';
            $sheet->setCellValue('B' . $row, $type);

            // Սյուն C: Անվանում (ԱԱՀ կամ Կազմակերպություն)
            $name = ($client->type === 'legal')
                ? ($client->company_name . ' ' . $client->legal_form)
                : trim($client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : ''));
            $sheet->setCellValue('C' . $row, $name);

            // Սյուն D: ՀՎՀՀ կամ Անձնագիր
            $sheet->setCellValue('D' . $row, ($client->type === 'legal') ? $client->tax_number : $client->passport_series);

            if ($client->type !== 'legal') {
                $sheet->setCellValue('E' . $row, $client->date_of_birth ? Carbon::parse($client->date_of_birth)->format('d.m.Y') : '');
                $sheet->setCellValue('F' . $row, $client->passport_issued ? Carbon::parse($client->passport_issued)->format('d.m.Y') : '');
                // Սյուն G: Անձնագիր տվող մարմին (ենթադրենք ունեք այս դաշտը, եթե ոչ՝ թողեք դատարկ)
                $sheet->setCellValue('G' . $row, $client->passport_given_by ?? '');
            }

            $sheet->setCellValue('H' . $row, $client->social_card_number);
            $sheet->setCellValue('J' . $row, ($client->residency_status === 'resident' ? 'ռեզիդենտ' : 'ոչ ռեզիդենտ'));
            $row++;
        }
    }

    private function fillCredit($sheet)
    {
        $row = 2;
        foreach ($this->contracts as $contract) {
            $sheet->setCellValue('A' . $row, $contract->client_id); // Սյուն A
            $sheet->setCellValue('B' . $row, $contract->num);      // Սյուն B (Պայմանագրի համար)
            $sheet->setCellValue('C' . $row, Carbon::parse($contract->date)->format('d.m.Y')); // Տրամադրման ամսաթիվ
            $sheet->setCellValue('D' . $row, Carbon::parse($contract->deadline)->format('d.m.Y')); // Մարման ամսաթիվ

            // Սյուն E: Փաստացի մարման ամսաթիվ (վերջին)
            $lastPayment = $contract->payments()->where('status', 'completed')->latest('date')->first();
            $sheet->setCellValue('E' . $row, $lastPayment ? Carbon::parse($lastPayment->date)->format('d.m.Y') : '');

            $sheet->setCellValue('F' . $row, 'վարկ'); // Տեսակը
            $sheet->setCellValue('G' . $row, $contract->contract_amount); // Պայմանագրային գումար
            $sheet->setCellValue('H' . $row, $contract->provided_amount); // Փաստացի տրամադրված (կուտակային)

            // Սյուն I: Մարված գումար (կուտակային)
            $totalPaid = $contract->payments()->where('status', 'completed')->sum('mother');
            $sheet->setCellValue('I' . $row, $totalPaid);

            $sheet->setCellValue('J' . $row, $contract->provided_amount - $totalPaid); // Մայր գումարի մնացորդ

            // Ժամկետանցներ (K, L, M, W)
            $isOverdue = $contract->is_overdue;
            $sheet->setCellValue('K' . $row, $isOverdue ? $contract->payments()->where('status', 'initial')->whereDate('date', '<', today())->sum('mother') : 0);
            $sheet->setCellValue('L' . $row, $isOverdue ? $contract->payments()->where('status', 'initial')->whereDate('date', '<', today())->sum('amount') : 0);

            if ($isOverdue) {
                $firstOverdue = $contract->payments()->where('status', 'initial')->whereDate('date', '<', today())->oldest('date')->first();
                $sheet->setCellValue('M' . $row, Carbon::parse($firstOverdue->date)->format('d.m.Y'));

                $days = Carbon::parse($firstOverdue->date)->diffInDays(today());
                $sheet->setCellValue('W' . $row, $days);
            }

            $sheet->setCellValue('N' . $row, ($contract->currency === 'USD' ? '002' : '001'));
            $sheet->setCellValue('O' . $row, 'Ստանդարտ'); // Ենթադրենք լռելյայն
            $sheet->setCellValue('P' . $row, ($contract->status === 'completed' ? 'մարված' : 'գործող'));
            $sheet->setCellValue('Q' . $row, $contract->interest_rate);
            $sheet->setCellValue('U' . $row, Carbon::parse($contract->date)->format('d.m.Y'));

            $row++;
        }
    }

    private function fillOwner($sheet)
    {
        // Ձեր կոդը հիմնականում ճիշտ էր, ավելացրեք դաշտերի ստուգում
        $row = 2;
        $legalClients = $this->contracts->map->client->unique('id')->where('type', 'legal');

        foreach ($legalClients as $client) {
            if ($client->owners) { // Ստուգեք արդյոք ունեք owners relation
                foreach ($client->owners as $owner) {
                    $sheet->setCellValue('A' . $row, $client->id);
                    $sheet->setCellValue('B' . $row, $owner->id);
                    $sheet->setCellValue('C' . $row, ($owner->type === 'legal' ? 'իրավաբանական անձ' : 'ֆիզիկական անձ'));
                    $sheet->setCellValue('D' . $row, $owner->display_name);
                    $sheet->setCellValue('E' . $row, $owner->code);
                    $row++;
                }
            }
        }
    }

    private function fillGuarantor($sheet)
    {
        $row = 2;
        foreach ($this->contracts as $contract) {
            foreach ($contract->guarantors as $guarantor) {
                $sheet->setCellValue('A' . $row, $contract->num); // Credit B սյունը
                $sheet->setCellValue('B' . $row, $guarantor->id);
                $sheet->setCellValue('C' . $row, ($guarantor->type === 'legal' ? 'իրավաբանական անձ' : 'ֆիզիկական անձ'));

                $name = ($guarantor->type === 'legal')
                    ? ($guarantor->company_name . ' ' . $guarantor->legal_form)
                    : trim($guarantor->name . ' ' . $guarantor->surname . ' ' . $guarantor->middle_name);

                $sheet->setCellValue('D' . $row, $name);
                $sheet->setCellValue('E' . $row, ($guarantor->type === 'legal' ? $guarantor->tax_number : $guarantor->passport_series));
                $sheet->setCellValue('T' . $row, ($contract->currency === 'USD' ? '002' : '001'));
                $row++;
            }
        }
    }
}
