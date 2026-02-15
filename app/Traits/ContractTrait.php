<?php

namespace App\Traits;

use App\Models\Category;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Item;
use App\Models\LumpRate;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\Subcategory;
use App\Models\SubcategoryItem;
use Carbon\Carbon;

trait ContractTrait
{
    use OrderTrait;
    /**
     * Helper method to create order and history entries
     */
    private function createOrderAndHistory($contract, $client_id, $client_name, $cash, $category_id, $num = null, $pawnshop_id = null, $date = null, $isOpen = false)
    {
        $historyTypeNames = $isOpen
            ? ['opening', 'one_time_payment', 'mother_payment']
            : ['one_time_payment', 'mother_payment'];

        $historyTypes = HistoryType::whereIn('name', $historyTypeNames)->get();

        $lump_rate = LumpRate::getRateByCategoryAndAmount($contract->provided_amount);
        $lump_amount_original = $contract->provided_amount * ($lump_rate->lump_rate / 100);

        $lump_amount = ($lump_amount_original >= 1375)
            ? ceil($lump_amount_original / 10) * 10
            : floor($lump_amount_original / 10) * 10;

        $lastNumber = $this->getLastOrderNumber();


        if ($isOpen) {
            $numOutOpening = $this->formatOrderNumber(++$lastNumber, 'out', $cash);
            $this->createOrderHistoryEntry(
                $contract, $client_id, $client_name,
                'out', 'opening',
                $contract->provided_amount, $cash,
                Contract::CONTRACT_OPENING,
                $numOutOpening, $pawnshop_id, $date, null
            );
        }
        $numInOneTime = $this->formatOrderNumber(++$lastNumber, 'in', $cash);
        $this->createOrderHistoryEntry(
            $contract, $client_id, $client_name,
            'in', 'one_time_payment',
            $lump_amount, $cash,
            Contract::LUMP_PAYMENT,
            $numInOneTime, $pawnshop_id, $date,
            Order::ONE_TIME_PAYMENT_FILTER
        );

        $numOutMother = $this->formatOrderNumber(++$lastNumber, 'out', $cash);
        return $this->createOrderHistoryEntry(
            $contract, $client_id, $client_name,
            'out', 'mother_payment',
            $contract->provided_amount, $cash,
            Contract::MOTHER_AMOUNT_PAYMENT,
            $numOutMother, $pawnshop_id, $date,
            Order::MOTHER_PAYMENT
        );
    }
    private function getLastOrderNumber(): int
    {
        $last = Order::orderByDesc('id')->value('num');

        return $last ? (int) preg_replace('/\D/', '', $last) : 0;
    }
    private function formatOrderNumber(int $number, string $direction, bool $isCash): string
    {
        $prefix = match($direction) {
            'in'  => $isCash ? 'IN' : 'T-IN',
            'out' => $isCash ? 'OUT' : 'T-OUT',
        };

        return $prefix . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
    private function createOrderAndHistoryEntry($contract, $client_id, $client_name, $cash, $category_id, $num = null, $pawnshop_id = null, $date = null)
    {
        $this->createOrderHistoryEntry(
            $contract, $client_id, $client_name, 'out', 'opening', $contract->provided_amount, $cash, Contract::CONTRACT_OPENING, $num, $pawnshop_id, $date
        );

    }

    /**
     * Helper method to create individual order and history entries
     */
//    private function createOrderHistoryEntry($contract, $client_id, $client_name, $type, $historyTypeName, $amount, $cash, $purpose, $num = null, $pawnshop_id, $date = null,$filter=null)
//    {
//        $order_id = $this->getOrder($cash, $type, $pawnshop_id);
//        if ($historyTypeName !== 'opening') {
//            // Create an order
//            $order = Order::create([
//                'num' => $num,
//                'contract_id' => $contract->id,
//                'type' => $type,
//                'title' => 'Օրդեր',
//                'pawnshop_id' => auth()->user()->pawnshop_id ?? $pawnshop_id,
//                'order' => $order_id,
//                'amount' => $amount,
//                'rep_id' => '2211',
//                'date' => $date ?? \Illuminate\Support\Carbon::now()->format('Y-m-d'),
//                'client_name' => $client_name,
//                'purpose' => $purpose,
//                'cash' => $cash,
//                'filter' => $filter ?? null
//            ]);
//        }
//        $order_id = $order->id ?? null;
//        // Add history for the order
//        $historyType = HistoryType::where('name', $historyTypeName)->first();
//        $history = History::create([
//            'type_id' => $historyType->id,
//            'contract_id' => $contract->id,
//            'user_id' => auth()->user()->id ?? null,
//            'order_id' => $order_id,
//            'date' => $date ?? Carbon::parse($contract->created_at)->setTimezone('Asia/Yerevan')->format('Y.m.d'),
//            'amount' => $amount,
//        ]);
//        if ($historyTypeName !== 'opening') {
//            // Create a deal for the order
//            $deal = $this->createDeal($amount, null, null, null, null, $type, $contract->id, $client_id, $order_id, $cash, null, $purpose, 'contract', $history->id, null, null, $pawnshop_id, $date);
//            return $deal->id;
//        }
//        return 0;
//    }
    private function createOrderHistoryEntry($contract, $client_id, $client_name, $type, $historyTypeName, $amount, $cash, $purpose, $num = null, $pawnshop_id, $date = null,$filter=null)
    {
        if ($historyTypeName !== 'opening') {
            $lastNumber = $this->getLastOrderNumber();

            $num = $this->formatOrderNumber(++$lastNumber, $type, $cash);
        }

        $order_id = $this->getOrder($cash, $type, $pawnshop_id);

        if ($historyTypeName !== 'opening') {
            $order = Order::create([
                'num' => $num,
                'contract_id' => $contract->id,
                'type' => $type,
                'title' => 'Օրդեր',
                'pawnshop_id' => auth()->user()->pawnshop_id ?? $pawnshop_id,
                'order' => $order_id,
                'amount' => $amount,
                'rep_id' => '2211',
                'date' => $date ?? \Illuminate\Support\Carbon::now()->format('Y-m-d'),
                'client_name' => $client_name,
                'purpose' => $purpose,
                'cash' => $cash,
                'filter' => $filter ?? null
            ]);
        }

        $order_id = $order->id ?? null;

        $historyType = HistoryType::where('name', $historyTypeName)->first();
        $history = History::create([
            'type_id' => $historyType->id,
            'contract_id' => $contract->id,
            'user_id' => auth()->user()->id ?? null,
            'order_id' => $order_id,
            'date' => $date ?? Carbon::parse($contract->created_at)->setTimezone('Asia/Yerevan')->format('Y.m.d'),
            'amount' => $amount,
        ]);

        if ($historyTypeName !== 'opening') {
            // Create a deal for the order
            $deal = $this->createDeal(
                $amount,
                null, null, null, null,
                $type,
                $contract->id,
                $client_id,
                $order_id,
                $cash,
                null,
                $purpose,
                'contract',
                $history->id,
                null, null,
                $pawnshop_id,
                $date
            );
            return $deal->id;
        }

        return 0;
    }

    public function getFullContract($id)
    {
        $contract = Contract::where('pawnshop_id', auth()->user()->pawnshop_id)->where('id', $id)
            ->with(['client', 'files', 'category', 'evaluator', 'payments' => function ($payment) {
                $payment->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC");
            }, 'history' => function ($query) {
                $query->with(['type', 'user', 'order'])->orderBy('id', 'DESC');
            }, 'items' => function ($query) {
                $query->with('category');
            }, 'discounts',])->first();
        if ($contract && $contract->evaluator) {
            $contract->evaluator_title = $contract->evaluator->full_name;
        }
        return $contract;
    }

    public function createDeal($amount, $interest_amount, $delay_days, $penalty, $discount, $type, $contract_id, $client_id, $order_id = null, $cash = true, $receiver = null, $purpose = null, $filter_type = null, $history_id = null, $payment_id = null, $source = null, $pawnshop_id = null, $date = null)
    {
        $pawnshop = $pawnshop_id ? Pawnshop::find($pawnshop_id) : auth()->user()->pawnshop;
        if ($type === 'in') {
            if ($cash) {
                $pawnshop->cashbox = $pawnshop->cashbox + $amount;
            } else {
                $pawnshop->bank_cashbox = $pawnshop->bank_cashbox + $amount;
            }
        } else {
            if ($cash) {
                $pawnshop->cashbox = $pawnshop->cashbox - $amount;
            } else {
                $pawnshop->bank_cashbox = $pawnshop->bank_cashbox - $amount;
            }

        }
        if ($amount < 0) {
            $type = $type === 'in' ? 'out' : 'in';
            $amount = -$amount;
        }
        $pawnshop->save();
        return Deal::create([
            'type' => $type,
            'amount' => $amount,
            'interest_amount' => $interest_amount,
            'delay_days' => $delay_days,
            'penalty' => $penalty,
            'discount' => $discount,
            'date' => $date ?? Carbon::now()->format('Y-m-d'),
            'pawnshop_id' => $pawnshop->id,
            'contract_id' => $contract_id,
            'order_id' => $order_id,
            'cashbox' => $pawnshop->cashbox,
            'bank_cashbox' => $pawnshop->bank_cashbox,
            'worth' => $pawnshop->worth,
            'given' => $pawnshop->given,
            'purpose' => $purpose,
            'cash' => boolval($cash),
            'receiver' => $receiver,
            'source' => $source,
            'created_by' => auth()->user()->id ?? 1,
            'client_id' => $client_id,
            'filter_type' => $filter_type,
            'history_id' => $history_id,
            'payment_id' => $payment_id,
        ]);
    }

    public function setContractPenalty($id)
    {
        $contract = Contract::where('id', $id)->with('payments')->first();
        $now = Carbon::now();
        $dateToCheck = null;
        if ($contract) {
            for ($i = 0; $i < count($contract->payments); $i++) {
                $payment = $contract->payments[$i];
                if ($now->gt(Carbon::parse($payment->date)) && $payment->status === 'initial') {
                    $dateToCheck = Carbon::parse($payment->date);
                    break;
                }
            }
            if ($dateToCheck) {
                $difference = $now->diffInDays($dateToCheck);
                $penalty = $contract->left * $difference * $contract->penalty * 0.01;
                $contract->penalty_amount = $penalty;
                $contract->save();
            } else {
                $contract->penalty_amount = 0;
                $contract->save();
            }
        }
    }


    public function createPayment($contract_id, $amount, $type, $payer, $cash)
    {
        $status = ($type === 'penalty' || $type === 'full') ? 'completed' : 'initial';
        $payment = new Payment();
        $payment->amount = $amount;
        $payment->paid = $amount;
        $payment->contract_id = $contract_id;
        $payment->cash = $cash;
        $payment->type = $type;
        $payment->pawnshop_id = auth()->user()->pawnshop_id;
        $payment->date = Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d');
        $payment->status = $status;
        if ($payer) {
            $payment->another_payer = true;
            $payment->name = $payer['name'];
            $payment->surname = $payer['surname'];
            $payment->phone = $payer['phone'];

        }
        $payment->save();
        return $payment;
    }

    public function completePayment()
    {

    }

    public function calcAmount($amount, $days, $rate): int
    {
        return intval(ceil($days * $rate * $amount * 0.01 / 10) * 10);
    }

    public function calculateCurrentPayment($contract)
    {
        if ($contract->closed_at) {
            return [
                "current_amount" => 0,
                "penalty_amount" => 0
            ];
        }
        $penaltyAmount = $this->countPenalty($contract->id);
        $contractCreationDate = Carbon::parse($contract->date);
        $contractEndDate = Payment::where('last_payment',1)->where('contract_id',$contract->id)->first();
        if ($contractEndDate) {
            $paymentDate = Carbon::parse($contractEndDate->date);
            $currentDate = $paymentDate->lt(Carbon::now()) ? $paymentDate : Carbon::now();
        } else {
            $currentDate = Carbon::now();
        }

        $partialPayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'partial')
            ->orderBy('date', 'asc')
            ->get();
        $remainingAmount = $contract->mother;
        $totalPayment = 0;
        $lastPaymentDate = $contractCreationDate;
        if ($partialPayments) {
            foreach ($partialPayments as $partialPayment) {
                $daysPassed = $lastPaymentDate->diffInDays($partialPayment->date);
                $totalPayment += $this->calcAmount($remainingAmount, $daysPassed, $contract->interest_rate);
                $remainingAmount -= $partialPayment->paid;
                $lastPaymentDate = Carbon::parse($partialPayment->date);
            }
        }
        $daysPassed = $lastPaymentDate->diffInDays($currentDate);
        $totalPayment += $this->calcAmount($remainingAmount, $daysPassed, $contract->interest_rate);

        $totalPaid = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')->sum('paid');
        $currentAmount = $totalPayment - $totalPaid + $penaltyAmount['penalty_amount'];
        return [
            "daysPassed" => $daysPassed,
            "endDate" => $currentDate,
            "totalPayment" => $totalPayment,
            "totalPaid" => $totalPaid,
            "penaltyAmount" => $penaltyAmount,
            "current_amount" =>$currentAmount > 0 ? $currentAmount : 0,
            "penalty_amount" => $penaltyAmount['penalty_amount'],
            "delay_days" => $penaltyAmount['delay_days']
        ];

    }


//    public function countPenalty($contract_id, $import_date = null)
//    {
//        $contract = Contract::with('payments')->find($contract_id);
//
//        if (!$contract) {
//            return [
//                'penalty_amount' => 0,
//                'delay_days' => 0,
//            ];
//        }
//
//        $now = $import_date ? Carbon::parse($import_date) : now();
//        $parent_id = null;
//
//        $first_unpayed_payment = Payment::where('contract_id',$contract->id)
//            ->where('status','initial')
//            ->where('type', '!=', 'penalty')
//            ->where('amount','>','0')
//            ->orderBy('date','asc')
//            ->first();
//
//        $lasPayedPenalty = Payment::where('contract_id',$contract->id)
//            ->where('type','penalty')
//            ->where('is_completed',true)
//            ->orderBy('date','desc')
//            ->orderBy('id','desc')
//            ->first();
//
//        $penalty_amount = 0;
//        $delay_days = 0;
//
//        if ($first_unpayed_payment) {
//            $penalty_start_date = Carbon::parse($first_unpayed_payment->date);
//            $parent_id = $first_unpayed_payment->id;
//            if ($lasPayedPenalty) {
//                $lastPayedPenaltyDate = Carbon::parse($lasPayedPenalty->date);
//                if ($lastPayedPenaltyDate->gt($penalty_start_date)) {
////
//                    $penalty_start_date = $lastPayedPenaltyDate;
//                    $parent_id = $lasPayedPenalty->id;
//                }
//            }
//            if ($now->gt($penalty_start_date)) {
//                $delay_days = $now->diffInDays($penalty_start_date);
////                $penalty_amount = $this->calcAmount($contract->left, $delay_days, $contract->penalty);
//                $overdue_amount = $first_unpayed_payment->amount;
//                $penalty_amount = $this->calcAmount($overdue_amount, $delay_days, $contract->penalty);
//                if ($parent_id) {
//                    $penalty_paid =  Payment::where('contract_id', $contract->id)
//                        ->where('type', 'penalty')
//                        ->where('parent_id', $parent_id)
//                        ->sum('paid') ?? 0;
//                } else {
//                    $penalty_paid = 0;
//                }
//
//
//                $penalty_amount -= $penalty_paid;
//            }
//        }
//        $contract->penalty_amount = $penalty_amount;
//        $contract->save();
//
//        return [
//            'payment_date' => $penalty_start_date ?? null,
//            'penalty_amount' =>$penalty_amount,
//            'delay_days' => $delay_days,
//            'parent_id' => $parent_id
//        ];
//    }

    public function countPenalty($contract_id, $import_date = null)
    {
        $contract = Contract::find($contract_id);

        if (!$contract) {
            return [
                'penalty_amount' => 0,
                'delay_days' => 0,
            ];
        }

        $now = $import_date ? Carbon::parse($import_date) : now();

        $overdue_payments = Payment::where('contract_id', $contract->id)
            ->where('status', 'initial')
            ->where('type', '!=', 'penalty')
            ->where('amount', '>', '0')
            ->where('date', '<', $now->toDateTimeString())
            ->orderBy('date', 'asc')
            ->get();

        $total_penalty_amount = 0;
        $max_delay_days = 0;
        $first_penalty_start_date = null;
        $primary_parent_id = null;

        if ($overdue_payments->isNotEmpty()) {
            foreach ($overdue_payments as $payment) {
                $payment_date = Carbon::parse($payment->date);

                $lastPaidPenalty = Payment::where('contract_id', $contract->id)
                    ->where('type', 'penalty')
                    ->where('parent_id', $payment->id)
                    ->where('is_completed', true)
                    ->orderBy('date', 'desc')
                    ->first();

                $penalty_start_date = $payment_date;
                if ($lastPaidPenalty) {
                    $lastPaidDate = Carbon::parse($lastPaidPenalty->date);
                    if ($lastPaidDate->gt($penalty_start_date)) {
                        $penalty_start_date = $lastPaidDate;
                    }
                }

                if ($now->gt($penalty_start_date)) {
                    $current_delay_days = $now->diffInDays($penalty_start_date);

                    if ($current_delay_days > $max_delay_days) {
                        $max_delay_days = $current_delay_days;
                        $first_penalty_start_date = $penalty_start_date;
                        $primary_parent_id = $payment->id;
                    }

                    $current_penalty = $this->calcAmount($payment->amount, $current_delay_days, $contract->penalty);

                    $penalty_paid = Payment::where('contract_id', $contract->id)
                        ->where('type', 'penalty')
                        ->where('parent_id', $payment->id)
                        ->sum('paid') ?? 0;

                    $total_penalty_amount += ($current_penalty - $penalty_paid);
                }
            }
        }

        $contract->penalty_amount = max(0, $total_penalty_amount);
        $contract->save();

        return [
            'payment_date' => $first_penalty_start_date,
            'penalty_amount' => max(0, $total_penalty_amount),
            'delay_days' => $max_delay_days,
            'parent_id' => $primary_parent_id
        ];
    }
    public function createImportPayment(Contract $contract)
    {
        $fromDate = Carbon::parse($contract->created_at)->setTimezone('Asia/Yerevan');
        $toDate = Carbon::parse($contract->deadline)->setTimezone('Asia/Yerevan');
        $currentDate = $fromDate;
        $pgi_id = 1;
        while ($currentDate->lt($toDate)) {
            $payment = [
                'contract_id' => $contract->id,
                'from_date' => $currentDate->format('Y-m-d'),
            ];

            // Determine the next payment date, or use the deadline if it's the last payment
            $nextPaymentDate = (clone $currentDate)->addMonths();
            $paymentDate = $nextPaymentDate->lt($toDate) ? $nextPaymentDate : $toDate;

            $diffDays = $paymentDate->diffInDays($currentDate);
            $amount = $this->calcAmount($contract->provided_amount, $diffDays, $contract->interest_rate);
            $payment['date'] = $paymentDate->format('Y-m-d');
            $payment['to_date'] = $paymentDate->format('Y-m-d');
            $payment['days'] = $diffDays;
            $payment['amount'] = $amount;
            $payment['pawnshop_id'] = auth()->user()->pawnshop_id;
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
            $currentDate = $nextPaymentDate;
        }
    }

}
