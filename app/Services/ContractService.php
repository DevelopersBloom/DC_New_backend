<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Contract;
use App\Models\DealAction;
use App\Models\Item;
use App\Models\ItemRealEstate;
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
            ]);
        $query->orderByRaw('CAST(RIGHT(num, 5) AS UNSIGNED) DESC');
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
//    public function storeContractItem(int $contract_id, array $data)
//    {
//        $query = Item::query();
//        if (!empty($data['serialNumber'])) {
//            $query->orWhere('sn', $data['serialNumber']);
//        }
//        if (!empty($data['imei'])) {
//            $query->orWhere('imei', $data['imei']);
//        }
//
//        $item = $query->first();
//        $category = Category::findOrFail($data['category_id']);
//
//        if ($category->name == 'electronics' && (!empty($data['serialNumber']) || !empty($data['imei'])) && $item) {
//            $item->update($data);
//            $item->sn = $data['serialNumber'];
//            $item->save();
//        } else {
//            $item = new Item();
//
//            $item->category_id = $category->id;
//            switch ($category->name) {
//                case 'electronics':
//                    $subcategory = Subcategory::firstOrCreate(
//                        [
//                            'name'        => $data['subcategory'],
//                            'category_id' => $data['category_id'],
//                        ]
//                    );
//
//                    if (!empty($data['model'])) {
//                        $subcategoryItem = SubcategoryItem::firstOrCreate([
//                            'subcategory_id' => $subcategory->id,
//                            'model' => $data['model'],
//                        ]);
//                        $item->model = $subcategoryItem->model;
//                    }
//                    $item->subcategory = $subcategory->name;
//                    $item->sn = $data['serialNumber'] ?? null;
//                    $item->imei = $data['imei'] ?? null;
//                    break;
//
//                case 'gold':
//                    $subcategory = Subcategory::firstOrCreate(
//                        [
//                            'name' => $data['subcategory'],
//                            'category_id' => $data['category_id']
//                        ]
//                    );
//                    $item->subcategory = $subcategory->name;
//                    $item->weight = $data['weight'] ?? null;
//                    $item->clear_weight = $data['clear_weight'] ?? null;
//                    $item->hallmark = $data['hallmark'] ?? null;
//                    break;
//
//                case 'car':
//                case 'car-purchase':
//                    $subcategory = Subcategory::firstOrCreate(
//                        [
//                            'name'        => $data['model'],
//                            'category_id' => $data['category_id'],
//                        ]
//                    );
//                    if (!empty($data['car_make'])) {
//                        $subcategoryItem = SubcategoryItem::firstOrCreate([
//                            'subcategory_id' => $subcategory->id,
//                            'model' => $data['car_make'],
//                        ]);
//                        $item->car_make = $subcategoryItem->model;
//                    }
//                    $item->model = $subcategory->name ?? null;
//                    $item->manufacture = $data['manufacture'] ?? null;
//                    $item->power = $data['power'] ?? null;
//                    $item->license_plate = $data['license_plate'] ?? null;
//                    $item->color = $data['color'] ?? null;
//                    $item->registration = $data['registration_certificate'] ?? null;
//                    $item->identification = $data['identification_number'] ?? null;
//                    $item->ownership = $data['ownership_certificate'] ?? null;
//                    $item->issued_by = $data['issued_by'] ?? null;
//                    $item->date_of_issuance = $data['date_of_issuance'] ?? null;
//                    break;
//            }
//            $item->description = $data['description'] ?? null;
//            $item->provided_amount = $data['rated'] ?? null;
//            $item->save();
//        }
//
//        $contract = Contract::findOrFail($contract_id);
//        $contract->items()->syncWithoutDetaching([$item->id]);
//        return $item;
//    }
    public function storeContractItem(int $contract_id, array $data)
    {
        $category = Category::findOrFail($data['category_id']);

        $item = null;
        if ($category->name !== 'electronics') {
            $query = Item::query();
            if (!empty($data['serialNumber'])) {
                $query->orWhere('sn', $data['serialNumber']);
            }
            if (!empty($data['imei'])) {
                $query->orWhere('imei', $data['imei']);
            }
            $existing = $query->first();

            if ((!empty($data['serialNumber']) || !empty($data['imei'])) && $existing) {
                $existing->update($data);
                $existing->sn = $data['serialNumber'] ?? null;
                $existing->save();
                $item = $existing;
            }
        }

        if ($item === null) {
            $item = new Item();
            $item->category_id = $category->id;

            $realEstateData = null;
            switch ($category->name) {
                case 'electronics':
                    if ($this->isRealEstateItem($data) || empty($data['subcategory'] ?? null)) {
                        $item->subcategory = $category->title;
                        $realEstateData = [
                            'certificate_number'         => $data['certificate_number'] ?? null,
                            'certificate_password'       => $data['certificate_password'] ?? null,
                            'cadastral_code'             => $data['cadastral_code'] ?? null,
                            'area_sqm'                   => $data['area_sqm'] ?? null,
                            'appraiser_company'          => $data['appraiser_company'] ?? null,
                            'appraisal_report_number'    => $data['appraisal_report_number'] ?? null,
                            'appraisal_date'             => $data['appraisal_date'] ?? null,
                            'appraised_value'            => $data['appraised_value'] ?? null,
                            'unified_reference_number'   => $data['unified_reference_number'] ?? null,
                            'unified_reference_password' => $data['unified_reference_password'] ?? null,
                            'is_joint'                   => $data['is_joint'] ?? false,
                        ];
                    } else {
                        $subcategory = Subcategory::firstOrCreate([
                            'name'        => $data['subcategory'] ?? '',
                            'category_id' => $data['category_id'],
                        ]);
                        if (!empty($data['model'])) {
                            $subcategoryItem = SubcategoryItem::firstOrCreate([
                                'subcategory_id' => $subcategory->id,
                                'model'          => $data['model'],
                            ]);
                            $item->model = $subcategoryItem->model;
                        }
                        $item->subcategory = $subcategory->name;
                        $item->sn = $data['serialNumber'] ?? null;
                        $item->imei = $data['imei'] ?? null;
                    }
                    break;

                case 'gold':
                    $subcategory = Subcategory::firstOrCreate([
                        'name'        => $data['subcategory'] ?? '',
                        'category_id' => $data['category_id'],
                    ]);
                    $item->subcategory   = $subcategory->name;
                    $item->weight        = $data['weight'] ?? null;
                    $item->clear_weight  = $data['clear_weight'] ?? null;
                    $item->hallmark      = $data['hallmark'] ?? null;
                    break;

                case 'car':
                case 'car-purchase':
                    $subcategory = Subcategory::firstOrCreate([
                        'name'        => $data['model'] ?? '',
                        'category_id' => $data['category_id'],
                    ]);
                    if (!empty($data['car_make'])) {
                        $subcategoryItem = SubcategoryItem::firstOrCreate([
                            'subcategory_id' => $subcategory->id,
                            'model'          => $data['car_make'],
                        ]);
                        $item->car_make = $subcategoryItem->model;
                    }
                    $item->model          = $subcategory->name ?? null;
                    $item->manufacture    = $data['manufacture'] ?? null;
                    $item->power          = $data['power'] ?? null;
                    $item->license_plate  = $data['license_plate'] ?? null;
                    $item->color          = $data['color'] ?? null;
                    $item->registration   = $data['registration_certificate'] ?? null;
                    $item->identification = $data['identification_number'] ?? null;
                    $item->ownership      = $data['ownership_certificate'] ?? null;
                    $item->issued_by      = $data['issued_by'] ?? null;
                    $item->date_of_issuance = $data['date_of_issuance'] ?? null;
                    break;
            }

            if (!$item->exists) {
                $item->description     = $data['description'] ?? null;
                $item->provided_amount = $data['rated'] ?? null;
                $item->save();
            }
            if ($realEstateData !== null) {
                ItemRealEstate::create(array_merge(['item_id' => $item->id], $realEstateData));
            }
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
                        if ($this->isRealEstateItem($data) || empty($data['subcategory'] ?? null)) {
                            $item->subcategory = $category->title;
                            $item->save();
                            $this->syncRealEstateDetails($item, $data);
                            break;
                        }

                        $subcategory = Subcategory::firstOrCreate([
                            'name'        => $data['subcategory'] ?? '',
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
                            'name' => $data['subcategory'] ?? '',
                            'category_id' => $data['category_id'],
                        ]);
                        $item->subcategory = $subcategory->name;
                        $item->weight = $data['weight'] ?? $item->weight;
                        $item->clear_weight = $data['clear_weight'] ?? $item->clear_weight;
                        $item->hallmark = $data['hallmark'] ?? $item->hallmark;
                        break;

                    case 'car':
                    case 'car-purchase':
                        $subcategory = Subcategory::firstOrCreate([
                            'name' => $data['model'] ?? '',
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

        $categoryId = $data['category_id'];
        $category = Category::find($categoryId);
        $categoryName = $category ? $category->name : 'default';
        $contractNumber = $this->generateContractNumber($categoryName);
        $status = isset($data['closed_at']) ? Contract::STATUS_COMPLETED : Contract::STATUS_INITIAL;

        $values = [
            'date' => $data['contract_created_date']
        ?? $data['date'] ?? now()->toDateString(),
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
            'seller_id' => $data['seller_id'] ?? null,
            'currency_id' => $data['currency_id'] ?? 1,
            'contract_kind'      => $data['contract_kind'] ?? 1,
            'loan_type'          => $data['loan_type'] ?? null,
            'interest_rate_type' => $data['interest_rate_type'] ?? 2,
            'security_type'      => $data['security_type'] ?? 4,
            'loan_use_field'     => $data['loan_use_field'] ?? null,
            'payment_day'        => $data['payment_day'] ?? null,
//            'kasko_amount' => $data['kasko_amount'] ?? null,
        ];

        return Contract::create($values);
    }
    private function isRealEstateItem(array $data): bool
    {
        $realEstateKeys = [
            'certificate_number',
            'certificate_password',
            'cadastral_code',
            'area_sqm',
            'appraiser_company',
            'appraisal_report_number',
            'appraisal_date',
            'appraised_value',
            'unified_reference_number',
            'unified_reference_password',
        ];

        foreach ($realEstateKeys as $key) {
            if (!empty($data[$key])) {
                return true;
            }
        }

        return false;
    }

    private function syncRealEstateDetails(Item $item, array $data): void
    {
        ItemRealEstate::updateOrCreate(
            ['item_id' => $item->id],
            [
                'certificate_number'         => $data['certificate_number'] ?? null,
                'certificate_password'       => $data['certificate_password'] ?? null,
                'cadastral_code'             => $data['cadastral_code'] ?? null,
                'area_sqm'                   => $data['area_sqm'] ?? null,
                'appraiser_company'          => $data['appraiser_company'] ?? null,
                'appraisal_report_number'    => $data['appraisal_report_number'] ?? null,
                'appraisal_date'             => $data['appraisal_date'] ?? null,
                'appraised_value'            => $data['appraised_value'] ?? null,
                'unified_reference_number'   => $data['unified_reference_number'] ?? null,
                'unified_reference_password' => $data['unified_reference_password'] ?? null,
                'is_joint'                   => filter_var($data['is_joint'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]
        );
    }

    private function generateContractNumber(string $categoryName): string
    {
        $prefix = match ($categoryName) {
            'gold'           => 'G',
            'electronics'    => 'H',
            'car', 'car-purchase' => 'A',
            default          => 'C',
        };

        $year = now()->format('y');

        $lastContract = Contract::orderBy('id', 'desc')->first();

        if ($lastContract && $lastContract->num) {
            $lastNumber = (int) substr($lastContract->num, -5);
        } else {
            $lastNumber = 0;
        }

        $newNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);

        return "{$prefix}-{$year}-{$newNumber}";
    }

    public function createPayment(Contract $contract, $import_date = null, $import_pawnshop_id = null, $months = null, int $startPgiId = 1)
    {
        if ($contract->payment_type === 'classic') {
             $this->createClassicPayment($contract, $import_date, $import_pawnshop_id, $startPgiId);
        }

        if ($contract->payment_type === 'amortized') {
             $this->createAnnuityPayment($contract, $import_date, $import_pawnshop_id, $months, $startPgiId);
        }
    }

    protected function buildClassicSchedule(Contract $contract, $import_date = null, $import_pawnshop_id = null, int $startPgiId = 1): array
    {
        $fromDate    = $import_date ? Carbon::parse($import_date)->setTimezone('Asia/Yerevan') : Carbon::parse($contract->date)->setTimezone('Asia/Yerevan');
        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
        $toDate      = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan');
        $currentDate = $fromDate;
        $pgi_id      = $startPgiId;
        $rows        = [];

        while ($currentDate->lt($toDate)) {
            $nextPaymentDate = (clone $currentDate)->addMonths();
            $rawPaymentDate  = $nextPaymentDate->lt($toDate) ? $nextPaymentDate : $toDate;
            $paymentDate     = $this->getNextWorkingDay(clone $rawPaymentDate);
            $diffDays        = $paymentDate->diffInDays($currentDate);

            $isLastPayment = $paymentDate->eq($toDate);
            if ($isLastPayment) {
                $diffDays++;
            }

            $kaskoAmount = 0;
            if ($contract->kasko_amount && $paymentDate->month == $currentDate->month && !$isLastPayment) {
                $kaskoAmount = $contract->kasko_amount;
            }

            $amount = $this->calcAmount($contract->provided_amount, $diffDays, $contract->interest_rate / 100);

            $rows[] = [
                'contract_id'  => $contract->id,
                'from_date'    => $currentDate->format('d.m.Y'),
                'date'         => $paymentDate->format('Y-m-d'),
                'to_date'      => $paymentDate->format('Y-m-d'),
                'days'         => $diffDays,
                'amount'       => $amount,
                'original_amount' => $amount,
                'mother'       => $isLastPayment ? $contract->provided_amount : 0,
                'last_payment' => $isLastPayment ? true : null,
                'kasko_amount' => $kaskoAmount,
                'pawnshop_id'  => $pawnshop_id,
                'PGI_ID'       => $pgi_id,
            ];

            $pgi_id++;
            $currentDate = $nextPaymentDate;
        }

        return $rows;
    }

    public function createClassicPayment(Contract $contract, $import_date = null, $import_pawnshop_id = null, int $startPgiId = 1)
    {
        $schedule = [];
        $fromDate = $import_date ? Carbon::parse($import_date)->setTimezone('Asia/Yerevan') : Carbon::parse($contract->date)->setTimezone('Asia/Yerevan');
        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
        $toDate = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan');
        $currentDate = $fromDate;
        $pgi_id = $startPgiId;
        while ($currentDate->lt($toDate))
         {
            $payment = [
                'contract_id' => $contract->id,
                'from_date' => $currentDate->format('d.m.Y'),
            ];

            // Determine the next payment date, or use the deadline if it's the last payment
            $nextPaymentDate = (clone $currentDate)->addMonths();
//            $paymentDate  = $nextPaymentDate->lt($toDate) ? $nextPaymentDate : $toDate;
             $rawPaymentDate = $nextPaymentDate->lt($toDate) ? $nextPaymentDate : $toDate;

            $paymentDate = $this->getNextWorkingDay(clone $rawPaymentDate);
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
        if (abs($rate) < 1e-12) {
            return 0.0;
        }

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

    /**
     * Pure schedule builder for broken-period annuity contracts.
     *
     * Returns an ordered array of row descriptors — one for the broken-period
     * (if brokenDays > 0) followed by `$months` regular installments.
     * No DB I/O; exposed as protected for unit testing via ReflectionMethod.
     *
     * @return array<int, array{
     *   is_broken_period: bool,
     *   calendar_due_date: string,
     *   business_due_date: string,
     *   from_date: string,
     *   days: int,
     *   principal: float,
     *   interest: float,
     *   service_fee: float,
     *   total: float,
     *   balance_after: float,
     *   is_last: bool,
     * }>
     */
    protected function computeBrokenPeriodAnnuitySchedule(
        float  $loanAmount,
        float  $interestRate,
        float  $feeAnnualPct,
        int    $months,
        Carbon $disbursementDate,
        int    $paymentDay
    ): array {
        $annualPct     = $interestRate * 365;
        $monthlyRate   = $annualPct / 100 / 12;
        $feeMonthly    = $feeAnnualPct / 100 / 12;
        $allMonthly    = $monthlyRate + $feeMonthly;
        $pmt           = -$this->excelPmt($allMonthly, $months, $loanAmount);

        // First calendar payment date: next occurrence of $paymentDay strictly after disbursement.
        if ($disbursementDate->day < $paymentDay) {
            $firstCalendar = $disbursementDate->copy()->day($paymentDay);
        } else {
            $firstCalendar = $disbursementDate->copy()->addMonthNoOverflow()->day($paymentDay);
        }

        $brokenDays = (int) $disbursementDate->diffInDays($firstCalendar);
        $rows       = [];
        $balance    = $loanAmount;

        // Row #0 — broken period (interest only, balance unchanged).
        if ($brokenDays > 0) {
            $dailyRate      = $interestRate / 100.0;
            $brokenInterest = round($loanAmount * $dailyRate * $brokenDays, 2);
            $bizDate        = $this->getNextWorkingDay($firstCalendar->copy());

            $rows[] = [
                'is_broken_period'  => true,
                'calendar_due_date' => $firstCalendar->toDateString(),
                'business_due_date' => $bizDate->toDateString(),
                'from_date'         => $disbursementDate->toDateString(),
                'days'              => $brokenDays,
                'principal'         => 0.0,
                'interest'          => $brokenInterest,
                'service_fee'       => 0.0,
                'total'             => $brokenInterest,
                'balance_after'     => $loanAmount,
                'is_last'           => false,
            ];
        }

        // Rows #1 .. #months — standard annuity installments.
        for ($i = 1; $i <= $months; $i++) {
            $isLast       = ($i === $months);
            $calendarDate = $firstCalendar->copy()->addMonthsNoOverflow($i);
            $bizDate      = $this->getNextWorkingDay($calendarDate->copy());

            $prevCalendar = $firstCalendar->copy()->addMonthsNoOverflow($i - 1);
            $fromDate     = $i === 1 ? $firstCalendar->copy() : $this->getNextWorkingDay($prevCalendar->copy());

            $interest   = round($balance * $monthlyRate, 2);
            $serviceFee = $feeAnnualPct > 0 ? round($balance * $feeMonthly, 2) : 0.0;

            if ($isLast) {
                $principal = $balance;
                $total     = round($principal + $interest + $serviceFee, 2);
            } else {
                $principal = round($pmt - $interest - $serviceFee, 2);
                $total     = round($pmt, 2);
            }

            $balance = round($balance - $principal, 2);

            $rows[] = [
                'is_broken_period'  => false,
                'calendar_due_date' => $calendarDate->toDateString(),
                'business_due_date' => $bizDate->toDateString(),
                'from_date'         => $fromDate->toDateString(),
                'days'              => (int) $fromDate->diffInDays($bizDate),
                'principal'         => $principal,
                'interest'          => $interest,
                'service_fee'       => $serviceFee,
                'total'             => $total,
                'balance_after'     => $balance,
                'is_last'           => $isLast,
            ];
        }

        return $rows;
    }

    /**
     * Build annuity schedule rows as an array (without persisting).
     * Each element contains all fields needed to create or update a Payment row.
     */
    protected function buildAnnuitySchedule(Contract $contract, $import_date = null, $import_pawnshop_id = null, $months = null, int $startPgiId = 1): array
    {
        $loanAmount = (float) $contract->provided_amount;

        $currentDate = $import_date
            ? \Carbon\Carbon::parse($import_date)
            : \Carbon\Carbon::parse($contract->date);

        // Use deadline_days as number of months (same as initial payment creation)
        $months = max(1, (int) $contract->deadline_days);
        $interestAnnualPercent = (float) $contract->interest_rate * 365;
        $interestMonthlyRate   = ($interestAnnualPercent / 100) / 12;

        $feeAnnualPercent = (float) $contract->fee_annual_rate;
        $feeMonthlyRate   = ($feeAnnualPercent / 100) / 12;

        $allMonthlyRate = $interestMonthlyRate + $feeMonthlyRate;
        $monthlyPayment = -$this->excelPmt($allMonthlyRate, $months, $loanAmount);

        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
        $pgi_id      = $startPgiId;

        $rows = [];

        for ($i = 1; $i <= $months; $i++) {
            $isLastMonth = ($i === $months);

            $prevRawDate    = (clone $currentDate)->addMonths($i - 1);
            $rawPaymentDate = (clone $currentDate)->addMonths($i);

            $prevPayDate    = $this->getNextWorkingDay(clone $prevRawDate);
            $paymentDate    = $this->getNextWorkingDay(clone $rawPaymentDate);

            $daysPrev     = $i == 1 ? $prevRawDate : $prevPayDate;
            $daysInPeriod = $paymentDate->diffInDays($daysPrev);

            $interestMonthlyRate = ($contract->interest_rate / 100) * $daysInPeriod;
            $allMonthlyRate      = $interestMonthlyRate + $feeMonthlyRate;

            $endingBalance   = -$this->excelFv($allMonthlyRate, $i, -$monthlyPayment, $loanAmount);
            $interestPayment = $this->calcAmount($loanAmount, $daysInPeriod, $contract->interest_rate / 100);

            $servicePayment = 0;
            if ($feeAnnualPercent) {
                $feeDailyPercent = $feeAnnualPercent / 365;
                $servicePayment  = $this->calcAmount($loanAmount, $daysInPeriod, $feeDailyPercent / 100);
            }
            $principalPayment = $monthlyPayment - $interestPayment - $servicePayment;
            $monthlyFeeAmount = -$this->excelIpmt($feeMonthlyRate, $i, $months, $loanAmount);
            $loanAmount      -= $principalPayment;

            $kaskoAmount = 0;
            if ($contract->kasko_amount && $paymentDate->month == $currentDate->month && !$isLastMonth) {
                $kaskoAmount = (float) $contract->kasko_amount;
            }

            if ($isLastMonth) {
                $monthlyPayment   += $loanAmount;
                $principalPayment += $loanAmount;
                $loanAmount        = 0;
            }

            $rows[] = [
                'contract_id'                => $contract->id,
                'date'                       => $paymentDate->format('Y-m-d'),
                'to_date'                    => $paymentDate->format('Y-m-d'),
                'from_date'                  => $i == 1 ? $prevRawDate->format('Y-m-d') : $prevPayDate->format('Y-m-d'),
                'days'                       => $daysInPeriod,
                'amount'                     => round($monthlyPayment, 10),
                'original_amount'            => round($monthlyPayment, 10),
                'principal_payment'          => round($principalPayment, 10),
                'original_principal_payment' => round($principalPayment, 10),
                'interest_payment'           => round($interestPayment, 10),
                'original_interest_payment'  => round($interestPayment, 10),
                'service_fee_payment'        => round($monthlyFeeAmount, 10),
                'remaining'                  => round($loanAmount, 10),
                'kasko_amount'               => $kaskoAmount,
                'pawnshop_id'                => $pawnshop_id,
                'PGI_ID'                     => $pgi_id,
            ];

            $pgi_id++;
        }
        return $rows;
    }

    protected function createAnnuityPayment(Contract $contract, $import_date = null, $import_pawnshop_id = null, $months = null, int $startPgiId = 1)
    {
        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;

        if (!$months) {
            $months = (int) $contract->deadline_days;
        }

        // --- Broken-period path (payment_day is set) ---
        $paymentDay = (int) ($contract->payment_day ?? 0);
        if ($paymentDay >= 1 && $paymentDay <= 28) {
            $loanAmount       = (float) $contract->provided_amount;
            $feeAnnualPercent = $contract->service_fee
                ? (float) $contract->service_fee
                : 0.0;
            $disbursementDate = $import_date
                ? Carbon::parse($import_date)
                : Carbon::parse($contract->date);

            $rows    = $this->computeBrokenPeriodAnnuitySchedule(
                $loanAmount,
                (float) $contract->interest_rate,
                $feeAnnualPercent,
                $months,
                $disbursementDate,
                $paymentDay
            );
            $pgi_id  = $startPgiId;
            $schedule = [];

            foreach ($rows as $row) {
                Payment::create([
                    'contract_id'                => $contract->id,
                    'date'                       => $row['business_due_date'],
                    'to_date'                    => $row['business_due_date'],
                    'calendar_due_date'          => $row['calendar_due_date'],
                    'from_date'                  => $row['from_date'],
                    'days'                       => $row['days'],
                    'amount'                     => $row['total'],
                    'original_amount'            => $row['total'],
                    'principal_payment'          => $row['principal'],
                    'original_principal_payment' => $row['principal'],
                    'interest_payment'           => $row['interest'],
                    'original_interest_payment'  => $row['interest'],
                    'service_fee_payment'        => $row['service_fee'],
                    'remaining'                  => $row['balance_after'],
                    'is_broken_period'           => $row['is_broken_period'],
                    'last_payment'               => $row['is_last'],
                    'kasko_amount'               => 0,
                    'pawnshop_id'                => $pawnshop_id,
                    'PGI_ID'                     => $pgi_id,
                ]);

                $pgi_id++;

                $schedule[] = [
                    'date'        => $row['business_due_date'],
                    'payment'     => $row['total'],
                    'monthly_fee' => round($row['service_fee'], 3),
                    'total'       => round($row['total'], 3),
                    'principal'   => round($row['principal'], 3),
                    'interest'    => round($row['interest'], 3),
                    'balance'     => $row['balance_after'],
                ];
            }

            $contract->payment_schedule = $schedule;
            $contract->save();
            return;
        }

        // --- Legacy path (no payment_day) ---
        $rows = $this->buildAnnuitySchedule($contract, $import_date, $pawnshop_id, $months, $startPgiId);

        $schedule = [];

        foreach ($rows as $row) {
            Payment::create($row);

            $schedule[] = [
                'date'        => $row['date'],
                'payment'     => round($row['amount'], 3),
                'monthly_fee' => round($row['service_fee_payment'], 3),
                'total'       => round($row['amount'] + $row['service_fee_payment'], 3),
                'principal'   => round($row['principal_payment'], 3),
                'interest'    => round($row['interest_payment'], 3),
                'balance'     => round($row['remaining'], 3),
            ];
        }

        $contract->payment_schedule = $schedule;
        $contract->save();
    }
    private function getNextWorkingDay(Carbon $date)
    {
        $holidays = [
            '01-01', // Ամանոր
            '01-02', // Ամանոր
            '01-06', // Սուրբ Ծնունդ
            '01-27', // Հիշատակի օր
            '01-28', // Բանակի օր
            '03-08', // Կանանց տոն
            '04-24', // Ցեղասպանության զոհերի հիշատակի օր
            '05-01', // Աշխատանքի օր
            '05-09', // Հաղթանակի և խաղաղության տոն
            '05-28', // Հանրապետության տոն
            '07-05', // Սահմանադրության օր
            '09-21', // Անկախության տոն
            '12-31', // Ամանոր
        ];

        while (true) {
            $monthDay = $date->format('m-d');

            if (in_array($monthDay, $holidays) || $date->isWeekend()) {
                $date->addDay();
            } else {
                break;
            }
        }

        return $date;
    }

    /**
     * Rebuilds the full payment schedule from contract provided_amount and interest_rate.
     * Duration (months) is counted inclusively from $startDate through contract deadline.
     */
    public function regenerateSchedule(Contract $contract, string $startDate): int
    {
        $start = Carbon::parse($startDate, 'Asia/Yerevan')->startOfDay();
        $deadline = Carbon::parse($contract->deadline, 'Asia/Yerevan')->startOfDay();

        if ($start->gt($deadline)) {
            throw new \InvalidArgumentException('Start date cannot be after contract deadline.');
        }

        // Match contract creation: deadline = start + N months, so N is the month diff (not inclusive +1).
        $months = ($deadline->year - $start->year) * 12 + ($deadline->month - $start->month);
        $months = max(1, $months);

        $hasCompletedPayments = Payment::where('contract_id', $contract->id)
            ->where('status', Contract::STATUS_COMPLETED)
            ->where('type', 'regular')
            ->exists();

        if ($hasCompletedPayments) {
            throw new \InvalidArgumentException('Cannot regenerate schedule when completed payments exist.');
        }

        Payment::where('contract_id', $contract->id)->forceDelete();

        $contract->date = $start->format('Y-m-d');
        $contract->provided_at = $start->format('Y-m-d');
        $contract->deadline_days = (string) $months;
        $contract->save();

        $this->createPayment(
            $contract->fresh(),
            $start->format('Y-m-d'),
            $contract->pawnshop_id,
            $months
        );

        return $months;
    }

    /**
     * Rebuild future regular payments from $startDate; keep completed regular rows.
     */
    public function rebuildScheduleFromDate(Contract $contract, string $startDate, ?int $dealId = null, float $overpaidInterest = 0.0, float $interestAmount = 0.0): int
    {
        $start    = \Illuminate\Support\Carbon::parse($startDate, 'Asia/Yerevan')->startOfDay();
        $deadline = \Illuminate\Support\Carbon::parse($contract->deadline, 'Asia/Yerevan')->startOfDay();

        if ($start->gt($deadline)) {
            throw new \InvalidArgumentException('Re-provide date cannot be after contract deadline.');
        }

        // Get existing initial payments ordered by date (do NOT delete them)
        $existingPayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $count = $existingPayments->count();

        if ($contract->payment_type === 'amortized') {
            // months is calculated inside buildAnnuitySchedule from startDate → deadline
            $newRows = $this->buildAnnuitySchedule($contract, $startDate, $contract->pawnshop_id, null, 1);
        } else {
            // Classic — build schedule data (use existing helper if available)
            $newRows = $this->buildClassicSchedule($contract, $startDate, $contract->pawnshop_id);
        }

        $schedule        = [];
        $paymentChanges  = [];
        $newPayments     = [];

        // Capture paymentChanges from old payments before soft-deleting.
        // new_* values already reflect the rebuilt schedule (overpaid is applied below per row,
        // so we log the raw new row values here — consistent with the original behaviour).
        if ($dealId) {
            foreach ($newRows as $index => $row) {
                if (isset($existingPayments[$index])) {
                    $old = $existingPayments[$index];
                    $paymentChanges[] = [
                        'payment_id'            => $old->id,
                        'old_amount'            => $old->amount,
                        'new_amount'            => $row['amount'],
                        'old_date'              => $old->date,
                        'new_date'              => $row['date'],
                        'old_paid'              => $old->paid,
                        'old_principal_payment' => $old->principal_payment,
                        'new_principal_payment' => $row['principal_payment'],
                        'old_interest_payment'  => $old->interest_payment,
                        'new_interest_payment'  => $row['interest_payment'],
                        'old_remaining'         => $old->remaining,
                        'new_remaining'         => $row['remaining'],
                        'updated_at'            => now()->toDateTimeString(),
                    ];
                }
            }
        }

        // Soft-delete all initial payments — schedule will be fully recreated below.
        Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->delete();

        $remainingOverpaid = max(0.0, $overpaidInterest);

        foreach ($newRows as $index => $row) {
            // Apply overpaid interest to earliest rows first
            $applyToInterest = 0.0;
            if ($remainingOverpaid > 0) {
                $applyToInterest          = min($remainingOverpaid, (float) $row['interest_payment']);
                $remainingOverpaid       -= $applyToInterest;
                $row['interest_payment'] -= $applyToInterest;
                $row['amount']           -= $applyToInterest;
            }
            // Add outstanding debt (≤ 20,000 AMD) to the first month's interest
            if ($index === 0 && $interestAmount > 0) {
                $row['interest_payment']          += $interestAmount;
                $row['original_interest_payment'] += $interestAmount;
                $row['amount']                    += $interestAmount;
                $row['original_amount']           += $interestAmount;
            }

            $newPayment = Payment::create([
                'contract_id'                => $row['contract_id'],
                'date'                       => $row['date'],
                'to_date'                    => $row['to_date'],
                'from_date'                  => $row['from_date'],
                'days'                       => $row['days'],
                'amount'                     => $row['amount'],
                'original_amount'            => $row['original_amount'],
                'principal_payment'          => $row['principal_payment'],
                'original_principal_payment' => $row['original_principal_payment'],
                'interest_payment'           => $row['interest_payment'],
                'original_interest_payment'  => $row['original_interest_payment'],
                'service_fee_payment'        => $row['service_fee_payment'],
                'remaining'                  => $row['remaining'],
                'kasko_amount'               => $row['kasko_amount'],
                'pawnshop_id'                => $row['pawnshop_id'],
                'PGI_ID'                     => $row['PGI_ID'],
                'paid'                       => $applyToInterest > 0 ? $applyToInterest : null,
            ]);

            if ($dealId) {
                $newPayments[] = [
                    'payment_id'        => $newPayment->id,
                    'date'              => $row['date'],
                    'amount'            => $row['amount'],
                    'principal_payment' => $row['principal_payment'],
                    'interest_payment'  => $row['interest_payment'],
                    'remaining'         => $row['remaining'],
                    'created_at'        => now()->toDateTimeString(),
                ];
            }

            $schedule[] = [
                'date'      => $row['date'],
                'payment'   => round($row['amount'], 3),
                'principal' => round($row['principal_payment'], 3),
                'interest'  => round($row['interest_payment'], 3),
                'balance'   => round($row['remaining'], 3),
            ];
        }

        $contract->payment_schedule = $schedule;
        $contract->save();

        if ($dealId && !empty($paymentChanges)) {
            DealAction::create([
                'deal_id'         => $dealId,
                'actionable_id'   => $contract->id,
                'actionable_type' => Contract::class,
                'amount'          => 0,
                'type'            => 'payment_change',
                'description'     => 'Schedule rebuild - payments updated',
                'date'            => $startDate,
                'history'         => ['payment_changes' => $paymentChanges],
            ]);
        }

        if ($dealId && !empty($newPayments)) {
            DealAction::create([
                'deal_id'         => $dealId,
                'actionable_id'   => $contract->id,
                'actionable_type' => Contract::class,
                'amount'          => 0,
                'type'            => 'new_paid',
                'description'     => 'Schedule rebuild - new payments created',
                'date'            => $startDate,
                'history'         => ['new_payments' => $newPayments],
            ]);
        }

        return $count;
    }

//    public function rebuildScheduleFromDate1(Contract $contract, string $startDate, ?int $dealId = null, float $overpaidInterest = 0.0): int
//    {
//        $start    = \Illuminate\Support\Carbon::parse($startDate, 'Asia/Yerevan')->startOfDay();
//        $deadline = \Illuminate\Support\Carbon::parse($contract->deadline, 'Asia/Yerevan')->startOfDay();
//
//        if ($start->gt($deadline)) {
//            throw new \InvalidArgumentException('Re-provide date cannot be after contract deadline.');
//        }
//
//        // Get existing initial payments ordered by date (do NOT delete them)
//        $existingPayments = Payment::where('contract_id', $contract->id)
//            ->where('type', 'regular')
//            ->where('status', 'initial')
//            ->orderBy('date', 'asc')
//            ->orderBy('id', 'asc')
//            ->get();
//
//        $count = $existingPayments->count();
//
//        if ($contract->payment_type === 'amortized') {
//            // months is calculated inside buildAnnuitySchedule from startDate → deadline
//            $newRows = $this->buildAnnuitySchedule($contract, $startDate, $contract->pawnshop_id, null, 1);
//        } else {
//            // Classic — build schedule data (use existing helper if available)
//            $newRows = $this->buildClassicSchedule($contract, $startDate, $contract->pawnshop_id);
//        }
//
//        $schedule        = [];
//        $paymentChanges  = [];
//        $newPayments     = [];
//
//        $remainingOverpaid = max(0.0, $overpaidInterest);
//        dd($remainingOverpaid);
//
//        foreach ($newRows as $index => $row) {
//            $applyToInterest = 0.0;
//            if ($remainingOverpaid > 0) {
//                $applyToInterest      = min($remainingOverpaid, (float) $row['interest_payment']);
//                $remainingOverpaid   -= $applyToInterest;
//                $row['interest_payment'] -= $applyToInterest;
//                $row['amount']           -= $applyToInterest;
//            }
//
//            if (!isset($existingPayments[$index])) {
//               $newPayment = Payment::create([
//                   'contract_id'                => $row['contract_id'],
//                   'date'                       => $row['date'],
//                   'to_date'                    => $row['to_date'],
//                   'from_date'                  => $row['from_date'],
//                   'days'                       => $row['days'],
//                   'amount'                     => $row['amount'],
//                   'original_amount'            => $row['original_amount'],
//                   'principal_payment'          => $row['principal_payment'],
//                   'original_principal_payment' => $row['original_principal_payment'],
//                   'interest_payment'           => $row['interest_payment'],
//                   'original_interest_payment'  => $row['original_interest_payment'],
//                   'service_fee_payment'        => $row['service_fee_payment'],
//                   'remaining'                  => $row['remaining'],
//                   'kasko_amount'               => $row['kasko_amount'],
//                   'pawnshop_id'                => $row['pawnshop_id'],
//                   'PGI_ID'                     => $row['PGI_ID'],
//                   'paid'                       => $applyToInterest > 0 ? $applyToInterest : null,
//               ]);
//
//               if ($dealId) {
//                   $newPayments[] = [
//                       'payment_id'        => $newPayment->id,
//                       'date'              => $row['date'],
//                       'amount'            => $row['amount'],
//                       'principal_payment' => $row['principal_payment'],
//                       'interest_payment'  => $row['interest_payment'],
//                       'remaining'         => $row['remaining'],
//                       'created_at'        => now()->toDateTimeString(),
//                   ];
//               }
//            } else {
//                $payment = $existingPayments[$index];
//                // Already paid amounts from payment_entries (append-only log)
//                $alreadyPaidInterest  = (float) ($payment->original_interest_payment - $payment->interest_payment);
//                $alreadyPaidPrincipal = (float) ($payment->original_principal_payment - $payment->principal_payment);
//
//                if ($dealId) {
//                    $paymentChanges[] = [
//                        'payment_id'            => $payment->id,
//                        'old_amount'            => $payment->amount,
//                        'new_amount'            => $row['amount'],
//                        'old_date'              => $payment->date,
//                        'new_date'              => $row['date'],
//                        'old_paid'              => $payment->paid,
//                        'old_principal_payment' => $payment->principal_payment,
//                        'new_principal_payment' => $row['principal_payment'],
//                        'old_interest_payment'  => $payment->interest_payment,
//                        'new_interest_payment'  => $row['interest_payment'],
//                        'old_remaining'         => $payment->remaining,
//                        'new_remaining'         => $row['remaining'],
//                        'updated_at'            => now()->toDateTimeString(),
//                    ];
//                }
//
//                $payment->update([
//                    'date'                       => $row['date'],
//                    'to_date'                    => $row['to_date'],
//                    'from_date'                  => $row['from_date'],
//                    'days'                       => $row['days'],
//                    'original_amount'            => $row['amount'],
//                    'amount'                     => $row['amount'],
//                    'original_interest_payment'  => $row['interest_payment'],
//                    'interest_payment'           => $row['interest_payment'],
//                    'original_principal_payment' => $row['principal_payment'],
//                    'principal_payment'          => $row['principal_payment'],
//                    'service_fee_payment'        => $row['service_fee_payment'],
//                    'remaining'                  => $row['remaining'],
//                    'PGI_ID'                     => $row['PGI_ID'],
//                    'paid'                       => $applyToInterest > 0 ? $applyToInterest : null,
//                ]);
//
//                $schedule[] = [
//                    'date'      => $row['date'],
//                    'payment'   => round($row['amount'], 3),
//                    'principal' => round($row['principal_payment'], 3),
//                    'interest'  => round($row['interest_payment'], 3),
//                    'balance'   => round($row['remaining'], 3),
//                ];
//            }
//        }
//
//        $contract->payment_schedule = $schedule;
//        $contract->save();
//
//        if ($dealId && !empty($paymentChanges)) {
//            DealAction::create([
//                'deal_id'         => $dealId,
//                'actionable_id'   => $contract->id,
//                'actionable_type' => Contract::class,
//                'amount'          => 0,
//                'type'            => 'payment_change',
//                'description'     => 'Schedule rebuild - payments updated',
//                'date'            => $startDate,
//                'history'         => ['payment_changes' => $paymentChanges],
//            ]);
//        }
//
//        if ($dealId && !empty($newPayments)) {
//            DealAction::create([
//                'deal_id'         => $dealId,
//                'actionable_id'   => $contract->id,
//                'actionable_type' => Contract::class,
//                'amount'          => 0,
//                'type'            => 'new_paid',
//                'description'     => 'Schedule rebuild - new payments created',
//                'date'            => $startDate,
//                'history'         => ['new_payments' => $newPayments],
//            ]);
//        }
//
//        return $count;
//    }

}
