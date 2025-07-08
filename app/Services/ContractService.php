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
        // Check if an item with the given serial number or IMEI exists
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

        // Attach the item to the contract in the pivot table
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

//    public function storeContractItem(int $contract_id,array $data)
//    {
//
//        $item = new Item();
//        $item->category_id = $data['category_id'];
//        $item->contract_id = $contract_id;
//        $category = Category::findOrFail($data['category_id']);
//        switch ($category->name)
//        {
//            case 'electronics':
//                $subcategory = Subcategory::firstOrCreate(
//                    [
//                        'name'        => $data['subcategory'],
//                        'category_id' => $data['category_id'],
//                    ]
//                );
//
//                if ($data['model']) {
//                    $subcategoryItem = SubcategoryItem::firstOrCreate([
//                        'subcategory_id' => $subcategory->id,
//                        'model' => $data['model'],
//                    ]);
//                    $item->model = $subcategoryItem->model;
//                }
//                $item->subcategory =  $subcategory->name;
//                $item->subcategory_item_id = $subcategoryItem->id;
//                break;
//            case 'gold':
//                $subcategory = Subcategory::firstOrCreate(
//                    [
//                        'name' => $data['subcategory'],
//                        'category_id' => $data['category_id']
//                    ]
//                );
//                $item->subcategory =  $subcategory->name;
//                $item->weight = $data['weight'] ?? null;
//                $item->clear_weight = $data['clear_weight'] ?? null;
//                $item->hallmark = $data['hallmark'] ?? null;
//                break;
//            case 'car':
//                $subcategory = Subcategory::firstOrCreate(
//                    [
//                        'name'        => $data['model'],
//                        'category_id' => $data['category_id'],
//                    ]
//                );
//                if ($data['car_make']) {
//                    $subcategoryItem = SubcategoryItem::firstOrCreate([
//                        'subcategory_id' => $subcategory->id,
//                        'model' => $data['car_make'],
//                    ]);
//                    $item->car_make = $subcategoryItem->model;
//                }
//                $item->model = $subcategory->name ?? null;
//                $item->manufacture = $data['manufacture'] ?? null;
//                $item->power = $data['power'] ?? null;
//                $item->license_plate = $data['license_plate'] ?? null;
//                $item->color = $data['color'] ?? null;
//                $item->registration = $data['registration_certificate'] ?? null;
//                $item->identification = $data['identification_number'] ?? null;
//                $item->ownership = $data['ownership_certificate']?? null;
//                $item->issued_by = $data['issued_by']?? null;
//                $item->date_of_issuance = $data['date_of_issuance'] ?? null;
//                break;
//        }
//        $item->save();
//        return $item;
//    }
    public function createContract(int $client_id, array $data, $deadline)
    {
        // Calculate the next contract number
        $maxNum = Contract::max('num') ?? 0;
        $status = isset($data['closed_at']) ? Contract::STATUS_COMPLETED : Contract::STATUS_INITIAL;
        $values = [
            'date' => $data['date'] ?? now()->toDateString(),
            'client_id' => $client_id,
            'num' => $data['num'] ?? $maxNum + 1, //if import from excel , use data['num']
            'estimated_amount' => $data['estimated_amount'],
            'provided_amount' => $data['provided_amount'],
            'left' => $data['left'] ?? $data['provided_amount'],
            'mother' => $data['mother'] ?? $data['provided_amount'], // Default to provided amount
            'interest_rate' => $data['interest_rate'],
            'penalty' => $data['penalty'],
            'deadline' => $deadline,
            'deadline_days' => $data['deadline'],
            'lump_rate' => $data['lump_rate'],
            'description' => $data['description'] ?? null,
            'status' => $status,
            'closed_at' => $data['closed_at'] ?? null,
            'pawnshop_id' => auth()->user()->pawnshop_id ?? $data['pawnshop_id'],
            'user_id' => auth()->user()->id ?? 1,
            'category_id' => $data['category_id'] ?? null
        ];

        // Create and return the contract
        return Contract::create($values);
    }

    public function createContract1(int $client_id, array $data,$deadline)
    {
        $maxNum = Contract::max('num') ?? 0;
        $contract = new Contract();
        $contract->client_id = $client_id;
        $contract->estimated_amount = $data['estimated_amount'];
        $contract->provided_amount = $data['provided_amount'];
        $contract->left = $data['provided_amount'];
        $contract->mother = $data['provided_amount'];
        $contract->interest_rate = $data['interest_rate'];
        $contract->penalty = $data['penalty'];
        $contract->deadline = $deadline;
        $contract->lump_rate = $data['lump_rate'];
        $contract->description = $data['description'] ?? null;
        $contract->status = 'initial';
        $contract->pawnshop_id = auth()->user()->pawnshop_id ?? $data['pawnshop_id'];
        $contract->num = $maxNum + 1;
        $contract->save();
        return $contract;

    }
    public function createPayment(Contract $contract,$import_date = null,$import_pawnshop_id = null)
    {
        $schedule = [];
        $fromDate = $import_date ? Carbon::parse($import_date)->setTimezone('Asia/Yerevan') : Carbon::parse($contract->date)->setTimezone('Asia/Yerevan');
        $pawnshop_id = $import_pawnshop_id ?? auth()->user()->pawnshop_id;
        $toDate = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan');
        $currentDate = $fromDate;
        $pgi_id = 1;
        while ($currentDate <= $toDate)
        {
            $payment = [
                'contract_id' => $contract->id,
                'from_date' => $currentDate->format('d.m.Y'),
            ];

            // Determine the next payment date, or use the deadline if it's the last payment
            $nextPaymentDate = (clone $currentDate)->addMonths();
            $paymentDate  = $nextPaymentDate <=($toDate) ? $nextPaymentDate : $toDate;

            $diffDays = $paymentDate->diffInDays($currentDate);
            $amount = $this->calcAmount($contract->provided_amount, $diffDays, $contract->interest_rate);
            $payment['date'] = $paymentDate->format('Y-m-d');
            $payment['days'] = $diffDays;
            $payment['amount'] = $amount;
            $payment['pawnshop_id'] = $pawnshop_id;
            $payment['mother'] = 0;
            $payment['PGI_ID'] = $pgi_id;

            // Check if it's the last payment
            if ($paymentDate->eq($toDate)) {
                $payment['mother'] = $contract->provided_amount; // Add mother amount for the last payment
                $payment['last_payment'] = true;
            }

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

    public function calcAmount($amount,$days,$rate): int
    {
        return intval(ceil($days * $rate * $amount * 0.01 /10) * 10);
    }
}
