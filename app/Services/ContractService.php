<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Contract;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Subcategory;
use App\Models\SubcategoryItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class   ContractService
{
    public function getContracts($filters)
    {
        $query = Contract::where('pawnshop_id', Auth::user()->pawnshop_id)
            ->with([
                'payments' => function ($payment) {
                    $payment->orderBy('date');
                },
                'client' => function ($query) {
                    $query->withCount('contracts');
                },
                'category',
                'items'
            ])
            ->orderBy('num', 'DESC');

        // Apply filters
        $query->filterStatus($filters['status'] ?? 'all')
            ->filterByDate('date', $filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->filterByRange('provided_amount', $filters['provided_amount_from'] ?? null, $filters['provided_amount_to'] ?? null)
            ->filterByRange('estimated_amount', $filters['estimated_amount_from'] ?? null, $filters['estimated_amount_to'] ?? null)
            ->filterByClient($filters)
            ->filterByContractItem($filters['type'] ?? null,$filters['subspecies'] ?? null,$filters['model'] ?? null)
            ->filterByDelayDays($filters['delay']??null);

        if (!empty($filters['num'])) {
            $query->where('num', 'like', $filters['num'] . '%');
        }
        $contracts = $query->paginate(10);


        // Get total counts
        $totalContracts = Contract::where('pawnshop_id', Auth::user()->pawnshop_id)->count();
        $activeContracts = Contract::where('pawnshop_id', Auth::user()->pawnshop_id)
            ->where('status', 'initial')
            ->count();
        $executedContracts = Contract::where('pawnshop_id', Auth::user()->pawnshop_id)
            ->where('status', 'executed')
            ->count();

        return [
            'contracts' => $query->paginate(10),
            'totalContracts' => $totalContracts,
            'activeContracts' => $activeContracts,
            'executedContracts' => $executedContracts,
        ];
//        return $query->paginate(10);
    }
    public function storeContractItem(int $contract_id, array $data)
    {
        $query = Item::query();
        if (!empty($data['serialNumber'])) {
            $query->orWhere('sn', $data['serialNumber']);
        }
        if (!empty($data['imei'])) {
            $query->orWhere('imei', $data['imei']);
        }

        $item = $query->first();
        $category = Category::findOrFail($data['category_id']);

        if ($category->name == 'electronics' && (!empty($data['serialNumber']) || !empty($data['imei'])) && $item) {
            $item->update($data);
            $item->sn = $data['serialNumber'];
            $item->save();
        } else {
            $item = new Item();

            $item->category_id = $category->id;
            switch ($category->name) {
                case 'electronics':
                    $subcategory = Subcategory::firstOrCreate(
                        [
                            'name'        => $data['subcategory'],
                            'category_id' => $data['category_id'],
                        ]
                    );

                    if (!empty($data['model'])) {
                        $subcategoryItem = SubcategoryItem::firstOrCreate([
                            'subcategory_id' => $subcategory->id,
                            'model' => $data['model'],
                        ]);
                        $item->model = $subcategoryItem->model;
                    }
                    $item->subcategory = $subcategory->name;
                    $item->sn = $data['serialNumber'] ?? null;
                    $item->imei = $data['imei'] ?? null;
                    break;

                case 'gold':
                    $subcategory = Subcategory::firstOrCreate(
                        [
                            'name' => $data['subcategory'],
                            'category_id' => $data['category_id']
                        ]
                    );
                    $item->subcategory = $subcategory->name;
                    $item->weight = $data['weight'] ?? null;
                    $item->clear_weight = $data['clear_weight'] ?? null;
                    $item->hallmark = $data['hallmark'] ?? null;
                    break;

                case 'car':
                    $subcategory = Subcategory::firstOrCreate(
                        [
                            'name'        => $data['model'],
                            'category_id' => $data['category_id'],
                        ]
                    );
                    if (!empty($data['car_make'])) {
                        $subcategoryItem = SubcategoryItem::firstOrCreate([
                            'subcategory_id' => $subcategory->id,
                            'model' => $data['car_make'],
                        ]);
                        $item->car_make = $subcategoryItem->model;
                    }
                    $item->model = $subcategory->name ?? null;
                    $item->manufacture = $data['manufacture'] ?? null;
                    $item->power = $data['power'] ?? null;
                    $item->license_plate = $data['license_plate'] ?? null;
                    $item->color = $data['color'] ?? null;
                    $item->registration = $data['registration_certificate'] ?? null;
                    $item->identification = $data['identification_number'] ?? null;
                    $item->ownership = $data['ownership_certificate'] ?? null;
                    $item->issued_by = $data['issued_by'] ?? null;
                    $item->date_of_issuance = $data['date_of_issuance'] ?? null;
                    break;
            }
            $item->description = $data['description'] ?? null;
            $item->provided_amount = $data['rated'] ?? null;
            $item->save();
        }

        $contract = Contract::findOrFail($contract_id);
        $contract->items()->syncWithoutDetaching([$item->id]);
        return $item;
    }
    public function updateContractItems(array $items)
    {
        DB::beginTransaction();
        try {
            foreach ($items as $data) {
                $item = Item::where('id', $data['id'])->first();
                if ($item->category_id != $data['category_id']) {
                    $item->fill([
                        'subcategory' => null,
                        'model' => null,
                        'weight' => null,
                        'clear_weight' => null,
                        'hallmark' => null,
                        'car_make' => null,
                        'manufacture' => null,
                        'power' => null,
                        'license_plate' => null,
                        'color' => null,
                        'registration' => null,
                        'identification' => null,
                        'ownership' => null,
                        'issued_by' => null,
                        'date_of_issuance' => null,
                        'sn' => null,
                        'imei' => null,
                        'description' => null,
                        'provided_amount' => null,
                    ]);
                    $item->save();
                }
                $category = Category::where('id', $data['category_id'])->first();
                $item->category_id = $category->id;

                if (!empty($data['description'])) {
                    foreach ($item->contracts as $contract) {
                        $contract->description = $data['description'] ?? $contract->description;
                        $contract->save();
                    }
                }

                switch ($category->name) {
                    case 'electronics':
                        $subcategory = Subcategory::firstOrCreate([
                            'name'        => $data['subcategory'],
                            'category_id' => $data['category_id'],
                        ]);
                        if (!empty($data['model'])) {
                            $subcategoryItem = SubcategoryItem::firstOrCreate([
                                'subcategory_id' => $subcategory->id,
                                'model' => $data['model'],
                            ]);
                            $item->model = $subcategoryItem->model;
                        }
                        $item->subcategory = $subcategory->name;
                        $item->sn = $data['serialNumber'] ?? $item->sn;
                        $item->imei = $data['imei'] ?? $item->imei;
                        break;

                    case 'gold':
                        $subcategory = Subcategory::firstOrCreate([
                            'name' => $data['subcategory'],
                            'category_id' => $data['category_id'],
                        ]);
                        $item->subcategory = $subcategory->name;
                        $item->weight = $data['weight'] ?? $item->weight;
                        $item->clear_weight = $data['clear_weight'] ?? $item->clear_weight;
                        $item->hallmark = $data['hallmark'] ?? $item->hallmark;
                        break;

                    case 'car':
                        $subcategory = Subcategory::firstOrCreate([
                            'name' => $data['model'],
                            'category_id' => $data['category_id'],
                        ]);
                        if (!empty($data['car_make'])) {
                            $subcategoryItem = SubcategoryItem::firstOrCreate([
                                'subcategory_id' => $subcategory->id,
                                'model' => $data['car_make'],
                            ]);
                            $item->car_make = $subcategoryItem->model;
                        }
                        $item->model = $subcategory->name ?? null;
                        $item->manufacture = $data['manufacture'] ?? $item->manufacture;
                        $item->power = $data['power'] ?? $item->power;
                        $item->license_plate = $data['license_plate'] ?? $item->license_plate;
                        $item->color = $data['color'] ?? $item->color;
                        $item->registration = $data['registration_certificate'] ?? $item->registration;
                        $item->identification = $data['identification_number'] ?? $item->identification;
                        $item->ownership = $data['ownership_certificate'] ?? $item->ownership;
                        $item->issued_by = $data['issued_by'] ?? $item->issued_by;
                        $item->date_of_issuance = $data['date_of_issuance'] ?? $item->date_of_issuance;
                        break;
                }
                $item->description = $data['description'] ?? $item->description;
                $item->provided_amount = $data['rated'] ?? $item->provided_amount;
                $item->save();
            }

            DB::commit();
            return response()->json(['message' => 'Items updated successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating items', 'error' => $e->getMessage()], 500);
        }
    }
    public function createContract(int $client_id, array $data, $deadline)
    {

        $categoryId = $data['category_id'] ?? 1;

        $contractNumber = $this->generateContractNumber($categoryId);

        $status = isset($data['closed_at']) ? Contract::STATUS_COMPLETED : Contract::STATUS_INITIAL;

        $values = [
            'date' => $data['date'] ?? now()->toDateString(),
            'client_id' => $client_id,
            'num' => $contractNumber,
            'estimated_amount' => $data['estimated_amount'],
            'provided_amount' => $data['provided_amount'],
            'contract_amount' => $data['contract_amount'] ?? null,
            'left' => $data['left'] ?? $data['provided_amount'],
            'mother' => $data['mother'] ?? $data['provided_amount'], // Default to provided amount
            'interest_rate' => $data['interest_rate'],
            'effective_rate' => $data['effective_rate'] ?? null,
            'fee_annual_rate' => $data['fee_annual_rate'] ?? 0,
            'penalty' => $data['penalty'],
            'deadline' => $deadline,
            'deadline_days' => $data['deadline'],
            'lump_rate' => $data['lump_rate'],
            'description' => $data['description'] ?? null,
            'status' => $status,
            'closed_at' => $data['closed_at'] ?? null,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? $data['pawnshop_id'],
            'user_id' => auth()->user()->id ?? 1,
            'category_id' => $categoryId ,
            'payment_type' => $data['payment_type'],
//            'kasko_amount' => $data['kasko_amount'] ?? null,
        ];

        // Create and return the contract
        return Contract::create($values);
    }
    private function generateContractNumber(int $categoryId): string
    {

        $map = [
            1 => 'A', // մեքենա
            2 => 'G', // ոսկի
            3 => 'C', // սպառողական
            4 => 'H', // անշարժ
        ];

        $prefix = $map[$categoryId] ?? 'X';

        $year = now()->format('y');

        $last = Contract::orderByDesc('id')->value('num');

        if ($last) {
            $lastNumber = (int) substr($last, -5);
        } else {
            $lastNumber = 0;
        }

        $newNumber = $lastNumber + 1;

        $formatted = str_pad($newNumber, 5, '0', STR_PAD_LEFT);

        return "{$prefix}-{$year}-{$formatted}";
    }

    public function createPayment(Contract $contract, $import_date = null, $import_pawnshop_id = null)
    {
        if ($contract->payment_type === 'classic') {
             $this->createClassicPayment($contract, $import_date, $import_pawnshop_id);
        }

        if ($contract->payment_type === 'amortized') {
             $this->createAnnuityPayment($contract, $import_date, $import_pawnshop_id);
        }
    }

    public function createClassicPayment(Contract $contract,$import_date = null,$import_pawnshop_id = null)
    {
        $schedule = [];
        $fromDate = $import_date ? Carbon::parse($import_date)->setTimezone('Asia/Yerevan') : Carbon::parse($contract->date)->setTimezone('Asia/Yerevan');
        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
        $toDate = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan');
        $currentDate = $fromDate;
        $pgi_id = 1;
        while ($currentDate->lt($toDate))
         {
            $payment = [
                'contract_id' => $contract->id,
                'from_date' => $currentDate->format('d.m.Y'),
            ];

            // Determine the next payment date, or use the deadline if it's the last payment
            $nextPaymentDate = (clone $currentDate)->addMonths();
            $paymentDate  = $nextPaymentDate->lt($toDate) ? $nextPaymentDate : $toDate;

            $diffDays = $paymentDate->diffInDays($currentDate);
            $payment['mother'] = 0;

            // Check if it's the last payment
            if ($paymentDate->eq($toDate)) {
                $diffDays++;
                $payment['mother'] = $contract->provided_amount; // Add mother amount for the last payment
                $payment['last_payment'] = true;
            }

             $kaskoAmount = 0;
             $isLastPayment = $paymentDate->eq($toDate);

             if ($contract->kasko_amount &&
                 $paymentDate->month == $currentDate->month &&
                 !$isLastPayment) {
                    $kaskoAmount = $contract->kasko_amount;
             }
             $amount = $this->calcAmount($contract->provided_amount, $diffDays, $contract->interest_rate/100);
            $payment['date'] = $paymentDate->format('Y-m-d');
            $payment['to_date'] = $paymentDate->format('Y-m-d');
            $payment['days'] = $diffDays;
            $payment['amount'] = $amount;
            $payment['pawnshop_id'] = $pawnshop_id;
            $payment['PGI_ID'] = $pgi_id;
            $payment['kasko_amount'] = $kaskoAmount;

            Payment::create($payment);
            $pgi_id++;
            // Move to the next payment date
//            $nextPaymentDate = (clone $currentDate)->addMonthNoOverflow();
//
            $schedule[] = [
                'date' => $paymentDate->format('Y-m-d'),
                'amount' => $amount
            ];
            $currentDate = $nextPaymentDate;
        }
        $contract->payment_schedule = $schedule;
        $contract->save();
    }
//    protected function createAnnuityPayment(Contract $contract, $import_date = null, $import_pawnshop_id = null)
//    {
//        $principal = $contract->provided_amount;
//        $months = $contract->deadline_days;
//        $annualRate = $contract->interest_rate * 365;
////        $annualRate = $contract->interest_rate;
//        $monthlyRate = $annualRate / 100 / 12;
//        $effectiveAnnualRate = $contract->effective_annual_rate;
//
//        $effectiveMonthlyRate = pow(1 + $effectiveAnnualRate, 1/12) - 1;
//
//        $annuityPayment = ($principal * $monthlyRate) / (1 - pow(1 + $monthlyRate, -$months));
//
//        $remaining = $principal;
//        $schedule = [];
//        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
//        $pgi_id = 1;
//
//        $currentDate = $import_date ? \Carbon\Carbon::parse($import_date) : \Carbon\Carbon::parse($contract->date);
//
//        for ($i = 1; $i <= $months; $i++) {
//            $interest = $remaining * $monthlyRate;
//            $effective = $remaining * $effectiveMonthlyRate;
//            $principalPayment = $annuityPayment - $interest;
//            $remaining -= $principalPayment;
//
//            $paymentDate = (clone $currentDate)->addMonths($i);
//            $kaskoAmount = 0;
//
//            $isLastMonth = ($i == $months);
//
//            if ($contract->kasko_amount &&
//                $paymentDate->month == $currentDate->month &&
//                !$isLastMonth) {
//                    $kaskoAmount = $contract->kasko_amount;
//            }
//            $payment = [
//                'contract_id' => $contract->id,
//                'date' => $paymentDate->format('Y-m-d'),
//                'to_date' => $paymentDate->format('Y-m-d'),
//                'amount' => round($annuityPayment, 10),
//                'principal_payment' => round($principalPayment, 10),
//                'interest_payment' => round($interest, 10),
//                'effective_payment' => round($effective,10),
//                'remaining' => round(max($remaining, 0), 10),
//                'kasko_amount' => $kaskoAmount,
//                'pawnshop_id' => $pawnshop_id,
//                'PGI_ID' => $pgi_id,
//            ];
//
//            Payment::create($payment);
//            $pgi_id++;
//
//            $schedule[] = [
//                'date' => $paymentDate->format('Y-m-d'),
//                'amount' => round($annuityPayment, 3),
//            ];
//        }
//
//        $contract->payment_schedule = $schedule;
//        $contract->save();
//    }


    public function calcAmount($amount, $days, $rate): float
    {
        return $days * $rate * $amount;
    }

    private function excelPmt(float $rate, int $nper, float $pv, float $fv = 0.0, int $when = 0): float
    {
        if (abs($rate) < 1e-12) {
            return -($pv + $fv) / $nper;
        }

        $pow = pow(1 + $rate, $nper);

        return -($rate * ($fv + $pv * $pow)) / ((1 + $rate * $when) * ($pow - 1));
    }

    private function excelFv(float $rate, int $nper, float $pmt, float $pv, int $when = 0): float
    {
        if (abs($rate) < 1e-12) {
            return -($pv + $pmt * $nper);
        }

        $pow = pow(1 + $rate, $nper);

        return -($pv * $pow + $pmt * (1 + $rate * $when) * (($pow - 1) / $rate));
    }

    private function excelIpmt(float $rate, int $per, int $nper, float $pv, float $fv = 0.0, int $when = 0): float
    {
        $pmt = $this->excelPmt($rate, $nper, $pv, $fv, $when);

        if ($when !== 0) {
            throw new \InvalidArgumentException('when != 0 not supported here (matches your Excel sheet).');
        }

        $pow = pow(1 + $rate, $per - 1);

        $balance = $fv + $pv * $pow + $pmt * (($pow - 1) / $rate);

        return -$balance * $rate;
    }

    private function excelPpmt(float $rate, int $per, int $nper, float $pv, float $fv = 0.0, int $when = 0): float
    {
        $pmt  = $this->excelPmt($rate, $nper, $pv, $fv, $when);
        $ipmt = $this->excelIpmt($rate, $per, $nper, $pv, $fv, $when);

        return $pmt - $ipmt;
    }
    protected function createAnnuityPayment(Contract $contract, $import_date = null, $import_pawnshop_id = null)
    {
        $loanAmount = (float) $contract->provided_amount;
        $months     = (int) $contract->deadline_days;

        $interestAnnualPercent = (float) $contract->interest_rate * 365;
        $interestMonthlyRate   = ($interestAnnualPercent / 100) / 12;

        $feeAnnualPercent = (float) $contract->fee_annual_rate;
        $feeMonthlyRate   = ($feeAnnualPercent / 100) / 12;

        $allMonthlyRate = $interestMonthlyRate + $feeMonthlyRate;

        $monthlyPayment = -$this->excelPmt($allMonthlyRate, $months, $loanAmount);

        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
        $pgi_id      = 1;

        $currentDate = $import_date
            ? \Carbon\Carbon::parse($import_date)
            : \Carbon\Carbon::parse($contract->date);

        $schedule = [];

        for ($i = 1; $i <= $months; $i++) {

            $beginingBalance = -$this->excelFv($allMonthlyRate, $i - 1, -$monthlyPayment, $loanAmount);
            $endingBalance   = -$this->excelFv($allMonthlyRate, $i,     -$monthlyPayment, $loanAmount);

            $principalPayment = -$this->excelPpmt($allMonthlyRate, $i, $months, $loanAmount);
            $interestPayment  = -$this->excelIpmt($interestMonthlyRate, $i, $months, $loanAmount);
            $monthlyFeeAmount = -$this->excelIpmt($feeMonthlyRate,      $i, $months, $loanAmount);

            $paymentDate = (clone $currentDate)->addMonths($i);

            $kaskoAmount = 0;
            $isLastMonth = ($i === $months);
            if ($contract->kasko_amount && $paymentDate->month == $currentDate->month && !$isLastMonth) {
                $kaskoAmount = (float) $contract->kasko_amount;
            }

            Payment::create([
                'contract_id'        => $contract->id,
                'date'               => $paymentDate->format('Y-m-d'),
                'to_date'            => $paymentDate->format('Y-m-d'),

                'amount'             => round($monthlyPayment, 10),

                'principal_payment'  => round($principalPayment, 10),
                'interest_payment'   => round($interestPayment, 10),
                'service_fee_payment' => round($monthlyFeeAmount, 10),

                'remaining'          => round(max($endingBalance, 0), 10),

                'kasko_amount'       => $kaskoAmount,
                'pawnshop_id'        => $pawnshop_id,
                'PGI_ID'             => $pgi_id,
            ]);

            $pgi_id++;

            $schedule[] = [
                'date'         => $paymentDate->format('Y-m-d'),
                'payment'      => round($monthlyPayment, 3),
                'monthly_fee'  => round($monthlyFeeAmount, 3),
                'total'        => round($monthlyPayment + $monthlyFeeAmount, 3),
                'principal'    => round($principalPayment, 3),
                'interest'     => round($interestPayment, 3),
                'balance'      => round(max($endingBalance, 0), 3),
            ];
        }

        $contract->payment_schedule = $schedule;
        $contract->save();
    }


}
