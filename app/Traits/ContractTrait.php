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

        if ($isOpen) {
            $this->createOrderHistoryEntry($contract, $client_id, $client_name, 'out', 'opening', $contract->provided_amount, $cash, Contract::CONTRACT_OPENING, $num, $pawnshop_id, $date,null);
        }
        $this->createOrderHistoryEntry($contract, $client_id, $client_name, 'in', 'one_time_payment', $lump_amount, $cash, Contract::LUMP_PAYMENT, $num, $pawnshop_id, $date,Order::ONE_TIME_PAYMENT_FILTER);
//        $this->createOrderHistoryEntry($contract,$client_id, $client_name, 'out', 'opening', $contract->provided_amount, $cash, Contract::CONTRACT_OPENING,$num,$pawnshop_id,$date);
        return $this->createOrderHistoryEntry($contract, $client_id, $client_name, 'out', 'mother_payment', $contract->provided_amount, $cash, Contract::MOTHER_AMOUNT_PAYMENT, $num, $pawnshop_id, $date,Order::MOTHER_PAYMENT);
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
    private function createOrderHistoryEntry($contract, $client_id, $client_name, $type, $historyTypeName, $amount, $cash, $purpose, $num = null, $pawnshop_id, $date = null,$filter=null)
    {
        $order_id = $this->getOrder($cash, $type, $pawnshop_id);
        if ($historyTypeName !== 'opening') {
            // Create an order
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
        // Add history for the order
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
            $deal = $this->createDeal($amount, null, null, null, null, $type, $contract->id, $client_id, $order_id, $cash, null, $purpose, 'contract', $history->id, null, null, $pawnshop_id, $date);
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
//            "daysPassed" => $daysPassed,
//            "endDate" => $currentDate,
//            "totalPayment" => $totalPayment,
//            "totalPaid" => $totalPaid,
            "penaltyAmount" => $penaltyAmount,
            "current_amount" =>$currentAmount > 0 ? $currentAmount : 0,
            "penalty_amount" => $penaltyAmount['penalty_amount'],
            "delay_days" => $penaltyAmount['delay_days']
        ];

    }

    public function calculateCurrentPayment1($contract): array
    {
        $penalty_amount = $this->countPenalty($contract->id);
        $contract_creation_date = \Illuminate\Support\Carbon::parse($contract->date);

        $current_date = Carbon::now();
        $days_passed = $contract_creation_date->diffInDays($current_date);
        $calculatedAmount = $this->calcAmount($contract->left, $days_passed, $contract->interest_rate);
        $total_paid = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')->sum('paid');
//        $penalty_paid = Payment::with('contract_id',$contract->id)
//            ->where('type','penalty')->sum('paid');
        $penalty = $penalty_amount;
        $current_amount = $calculatedAmount - $total_paid + $penalty['penalty_amount'];
        return ["current_amount" => $current_amount > 0 ? $current_amount : 0,
            "penalty_amount" => $penalty['penalty_amount']];
    }

    public function calculateCurrentPayment2($contract): array
    {
        $penalty_amount = $this->countPenalty($contract->id);
        $contract_creation_date = \Illuminate\Support\Carbon::parse($contract->date);
        $current_date = Carbon::now();
        $days_passed = $contract_creation_date->diffInDays($current_date);

        // Calculate the total amount based on the days passed since contract creation
        $calculatedAmount = $this->calcAmount($contract->left, $days_passed, $contract->interest_rate);

        // Get the total amount paid so far
        $total_paid = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->sum('paid');

        // Calculate the penalty, if any
        $penalty = $penalty_amount;

        // Recalculate the future payment amounts (with dates greater than now)
        $futurePayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('date', '>', $current_date)
            ->get();

        foreach ($futurePayments as $futurePayment) {
            // Coeff to recalculate based on the remaining balance after partial payment
            $coeff = $contract->left / ($contract->left + $total_paid);  // Use remaining amount coefficient
            $futurePayment->amount = intval(ceil($futurePayment->amount * $coeff / 10) * 10);
            $futurePayment->save();
        }

        // Calculate the current amount due (considering total paid, penalties, and recalculations)
        $current_amount = $calculatedAmount - $total_paid + $penalty;

        return [
            "current_amount" => $current_amount > 0 ? $current_amount : 0,
            "penalty_amount" => $penalty
        ];
    }
    public function countPenalty1($contract_id, $import_date = null)
    {
        $contract = Contract::where('id', $contract_id)
            ->with('payments')->first();
        $penalty_paid = Payment::where('contract_id', $contract->id)
            ->where('type', 'penalty')
            ->sum('paid');
        $now = $import_date ?? \Carbon\Carbon::now();
        $total_penalty_amount = 0;
        $total_delay_days = 0;
        if ($contract) {
            $penalty_calculated = false;
            foreach ($contract->payments as $payment) {
                // Parse the payment date
                $payment_date = \Carbon\Carbon::parse($payment->date);
                // Check if the payment is overdue and has 'initial' status
                if ($now->gt($payment_date) && $payment->status === 'initial') {
                    // Calculate the overdue days
                    $delay_days = $now->diffInDays($payment_date);

                    // Calculate the penalty for this overdue payment
                    $penalty_amount = $this->calcAmount($contract->left, $delay_days, $contract->penalty);
                    if ($penalty_amount > 0 && !$penalty_calculated) {
                        $total_penalty_amount = $penalty_amount;
                        $penalty_calculated = true; // Set flag to true after first calculation
                    }
                    // Add to the total penalty and delay days
                    //   $total_penalty_amount += $penalty_amount;
                    $total_delay_days += $delay_days;
                }
            }

            // Subtract already paid penalties
            $total_penalty_amount -= $penalty_paid;

            // Save the penalty amount to the contract
            $contract->penalty_amount = $total_penalty_amount > 0 ? $total_penalty_amount : 0;
            $contract->save();

            return [
                'penalty_amount' => $total_penalty_amount > 0 ? $total_penalty_amount : 0,
                'delay_days' => $total_delay_days,
            ];
        }

        return [
            'penalty_amount' => 0,
            'delay_days' => 0,
        ];
    }

    public function countPenalty($contract_id, $import_date = null)
    {
        $contract = Contract::with('payments')->find($contract_id);

        if (!$contract) {
            return [
                'penalty_amount' => 0,
                'delay_days' => 0,
            ];
        }

        $now = $import_date ? \Carbon\Carbon::parse($import_date) : now();

        // Get the last penalty payment date
        $last_penalty = Payment::where('contract_id', $contract->id)
            ->where('type', 'penalty')
            ->where('paid', '>', 0)
            ->orderByDesc('date')
            ->first();
        $last_penalty_date = $last_penalty ? \Carbon\Carbon::parse($last_penalty->date) : null;

        $total_penalty_amount = 0;
        $total_delay_days = 0;
        $penalty_calculated = false;
        $penalty_date_adjusted = false;

        foreach ($contract->payments as $payment) {
            // Only consider unpaid payments
            if ($payment->status !== 'initial') {
                continue;
            }

            $payment_date = \Carbon\Carbon::parse($payment->date);

            // If payment date is before last penalty payment date, adjust it
            if ($last_penalty_date && $payment_date->lt($last_penalty_date)) {
                // Only adjust once
                if (!$penalty_date_adjusted) {
                    $payment_date = $last_penalty_date;
                    $penalty_date_adjusted = true;
                } else {
                    // Skip this payment, already adjusted for one
                    continue;
                }
            }

            // Only calculate if overdue
            if ($now->gt($payment_date)) {
                $delay_days = $now->diffInDays($payment_date);

                // Calculate the penalty once
                if (!$penalty_calculated) {
                    $penalty_amount = $this->calcAmount($contract->left, $delay_days, $contract->penalty);
                    $penalty_calculated = true;
                    $total_penalty_amount += $penalty_amount;
                }

                $total_delay_days += $delay_days;
            }
        }

        // Set the result to contract
        $contract->penalty_amount = $total_penalty_amount > 0 ? $total_penalty_amount : 0;
        $contract->save();

        return [
            'penalty_amount' => $total_penalty_amount,
            'delay_days' => $total_delay_days,
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
