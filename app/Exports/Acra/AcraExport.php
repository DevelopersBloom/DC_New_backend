<?php

namespace App\Exports\Acra;

use App\Models\DocumentJournal;
use App\Models\Transaction;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
    protected $customerCode = 'ACC';

    public function __construct($contracts, $allClients, $from, $to)
    {
        $this->contracts = $contracts;
        $this->allClients = $allClients;
        $this->from = $from;
        $this->to = $to;
    }

    public function export()
    {
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

        // Կիրառում ենք միանման հավասարեցում բոլոր էջերի համար
        $this->applyGlobalStyles($spreadsheet);

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

    private function fillPackageInfo($sheet)
    {
        if (!$sheet) return;
        $sheet->setCellValue('B1', $this->customerCode);
        $sheet->setCellValue('B2', $this->formatDate($this->from, 'Y-m-d'));
        $sheet->setCellValue('B3', $this->formatDate($this->to, 'Y-m-d'));
        $sheet->setCellValue('B4', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('B5', 1);
        $sheet->setCellValue('B6', 1);
    }

    private function fillDebtor($sheet)
    {
        if (!$sheet) return;
        $clients = $this->contracts->map->client->unique('id');
        $row = 2;
        foreach ($this->allClients as $client) {
            $sheet->setCellValue('A' . $row, $client->id);
            $sheet->setCellValue('B' . $row, ($client->type === 'legal' ? 'իրավաբանական անձ' : 'ֆիզիկական անձ'));

            $name = ($client->type === 'legal')
                ? ($client->company_name . ' ' . $client->legal_form)
                : trim($client->name . ' ' . $client->surname . ($client->middle_name ? ' ' . $client->middle_name : ''));

            $sheet->setCellValue('C' . $row, $name);
            $sheet->setCellValue('D' . $row, ($client->type === 'legal' ? $client->tax_number : $client->passport_series));

            if ($client->type !== 'legal') {
                $sheet->setCellValue('E' . $row, $this->formatDate($client->date_of_birth));
                $sheet->setCellValue('F' . $row, $this->formatDate($client->passport_validity));
                $sheet->setCellValue('G' . $row, $client->passport_issued ?? '');
                $sheet->setCellValue('H' . $row, $client->social_card_number);

            }
            $sheet->setCellValue('J' . $row, ($client->residency_status === 'resident' ? 'ռեզիդենտ' : 'ոչ ռեզիդենտ'));
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
        foreach ($this->contracts as $contract) {
            $sheet->setCellValue('A' . $row, $contract->client_id);
            $sheet->setCellValue('B' . $row, $contract->num);

            $sheet->setCellValue('C' . $row, $this->formatDate($contract->date));
            $sheet->setCellValue('D' . $row, $contract->deadline ? $this->formatDate($contract->deadline) : '01.01.2999');

            $lastPaymentDate = null;

            $journalId = DocumentJournal::where('journalable_type', Contract::class)
                ->where('journalable_id', $contract->id)
                ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
                ->select('id')
                ->first();

            $lastMotherPayment = DocumentJournal::where('journalable_type',DocumentJournal::class)
                ->where('journalable_id',$journalId->id)
                ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
                ->where('date','>=', $this->from)
                ->where('date','<=', $this->to)
                ->latest('date')
                ->first();
            if ($lastMotherPayment) {
                $lastPaymentDate = $lastMotherPayment->date;
            } elseif ($contract->status === 'completed' || $contract->status === 'executed') {
                $lastPaymentDate = $contract->closed_at;
            }
            $sheet->setCellValue('E' . $row, $this->formatDate($lastPaymentDate));

            $sheet->setCellValue('F' . $row, 'վարկ');
            $sheet->setCellValue('G' . $row, $contract->contract_amount);
            $sheet->setCellValue('H' . $row, $contract->mother);

            $totalPaid =  DocumentJournal::where('journalable_type',DocumentJournal::class)
                ->where('journalable_id',$journalId->id)
                ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
                ->where('date','<=', $this->to)
                ->sum('amount_amd');
            $sheet->setCellValue('I' . $row, $totalPaid);

            $sheet->setCellValue('J' . $row, max(0, $contract->provided_amount));

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

            $sheet->setCellValue('K' . $row, $overdueMother);
            $sheet->setCellValue('L' . $row, $overdueInterest);

            //  M , W
            $firstOverdue = $contract->payments()
                ->where('status', 'initial')
                ->where('date', '<', $this->to)
                ->oldest('date')
                ->first();

            if ($firstOverdue && ($overdueMother > 0 || $overdueInterest > 0)) {
                $sheet->setCellValue('M' . $row, $this->formatDate($firstOverdue->date));
                $days = Carbon::parse($firstOverdue->date)->diffInDays(Carbon::parse($this->from));
                $sheet->setCellValue('W' . $row, $days);
            } else {
                $sheet->setCellValue('W' . $row, 0);
            }

            // N, O, P, Q, U
            $sheet->setCellValue('N' . $row, '001');

            $riskClass = $contract->client->classification->title ?? 'Ստանդարտ';
            $sheet->setCellValue('O' . $row, $riskClass);

            $sheet->setCellValue('P' . $row, ($contract->status === 'completed' ? 'մարված' : 'գործող'));
            $sheet->setCellValue('Q' . $row, $contract->interest_rate);
            $sheet->setCellValue('U' . $row, $this->formatDate($contract->date));

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
