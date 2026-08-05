<?php

namespace App\Traits;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DocumentJournal;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Models\Prepayment;
use App\Models\PostingRule;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use PhpParser\Comment\Doc;

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

        $lump_rate = $contract->lump_rate;
//        ?? LumpRate::getRateByCategoryAndAmount($contract->provided_amount);
        $lump_amount_original = $contract->provided_amount * ($lump_rate / 100);

        $lump_amount = floor($lump_amount_original / 10) * 10;

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
        if ($lump_amount > 0) {
            $this->createOrderHistoryEntry(
                $contract, $client_id, $client_name,
                'in', 'one_time_payment',
                $lump_amount, $cash,
                Contract::LUMP_PAYMENT,
                $numInOneTime, $pawnshop_id, $date,
                Order::ONE_TIME_PAYMENT_FILTER
            );
        }


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

    /**
     * Bank-only delta disbursement orders/histories/deals for loan re-provide.
     */
    private function createReprovideOrderAndHistory(
        $contract,
        float $deltaAmount,
        $client_id,
        $client_name,
        $category_id,
        $date = null,
        $pawnshop_id = null
    ): int {
        $cash = false;
        $lastNumber = $this->getLastOrderNumber();
        $deal_id = 0;

        $lump_rate = $contract->lump_rate;
        $lump_amount_original = $deltaAmount * ($lump_rate / 100);
        $lump_amount = floor($lump_amount_original / 10) * 10;

        if ($lump_amount > 0) {
            $numInOneTime = $this->formatOrderNumber(++$lastNumber, 'in', $cash);
            $this->createOrderHistoryEntry(
                $contract, $client_id, $client_name,
                'in', 'one_time_payment',
                $lump_amount, $cash,
                Contract::LUMP_PAYMENT,
                $numInOneTime, $pawnshop_id, $date,
                Order::ONE_TIME_PAYMENT_FILTER
            );
        }

        $numOutMother = $this->formatOrderNumber(++$lastNumber, 'out', $cash);

        return $this->createOrderHistoryEntry(
            $contract, $client_id, $client_name,
            'out', 'mother_payment',
            $deltaAmount, $cash,
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
                'filter' => $filter ?? null,
                'user_id' => auth()->id(),
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
        if ($cash) {
            $amount = round($amount, 2);
        }
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
        return $days * $rate / 100 * $amount;
    }

//    public function calculateCurrentPayment($contract,$date = null)
//    {
//        if ($contract->closed_at) {
//            return [
//                "current_amount" => 0,
//                "penalty_amount" => 0,
//                "future_interest_discount" => 0,
//            ];
//        }
//        $penaltyAmount = $this->countPenalty($contract->id,$date);
//        $contractCreationDate = Carbon::parse($contract->date);
//        $contractEndDate = Payment::where('last_payment',1)->where('contract_id',$contract->id)->first();
//        if ($contractEndDate) {
//            $paymentDate = Carbon::parse($contractEndDate->date);
//            $currentDate = $paymentDate->lt(Carbon::now()) ? $paymentDate : Carbon::now();
//        } else {
//            $currentDate = Carbon::now();
//        }
//
//        $partialPayments = Payment::where('contract_id', $contract->id)
//            ->where('type', 'partial')
//            ->orderBy('date', 'asc')
//            ->get();
//        $remainingAmount = $contract->mother;
//        $totalPayment = 0;
//        $lastPaymentDate = $contractCreationDate;
//        if ($partialPayments) {
//            foreach ($partialPayments as $partialPayment) {
//                $daysPassed = $lastPaymentDate->diffInDays($partialPayment->date);
//                $totalPayment += $this->calcAmount($remainingAmount, $daysPassed, $contract->interest_rate);
//                $remainingAmount -= $partialPayment->paid;
//                $lastPaymentDate = Carbon::parse($partialPayment->date);
//            }
//        }
//        $daysPassed = $lastPaymentDate->diffInDays($currentDate);
//        $totalPayment += $this->calcAmount($remainingAmount, $daysPassed, $contract->interest_rate);
//
//        $totalPaid = Payment::where('contract_id', $contract->id)
//            ->where('type', 'regular')->sum('paid');
//        $currentAmount = $totalPayment - $totalPaid + $penaltyAmount['penalty_amount'];
//
//        $futureInterestDiscount = 0.0;
//        $today = Carbon::now('Asia/Yerevan')->startOfDay();
//        $nextInitialRegularPayment = Payment::where('contract_id', $contract->id)
//            ->where('type', 'regular')
//            ->where('status', 'initial')
//            ->orderBy('to_date')
//            ->orderBy('id')
//            ->first();
//
//        if ($nextInitialRegularPayment) {
//            $dueDate = Carbon::parse($nextInitialRegularPayment->to_date ?? $nextInitialRegularPayment->date)
//                ->setTimezone('Asia/Yerevan')
//                ->startOfDay();
//            $futureDays = $today->diffInDays($dueDate, false);
//            if ($futureDays > 0) {
//                $principalBase = max(0, (float) ($contract->provided_amount ?? 0));
//                $dailyRate = (float) ($contract->interest_rate ?? 0) / 100;
//                $futureInterestDiscount = $principalBase * $futureDays * $dailyRate;
//            }
//        }
//
//        return [
//            "daysPassed" => $daysPassed,
//            "endDate" => $currentDate,
//            "totalPayment" => $totalPayment,
//            "totalPaid" => $totalPaid,
//            "penaltyAmount" => $penaltyAmount,
//            "current_amount" =>$currentAmount > 0 ? $currentAmount : 0,
//            "penalty_amount" => $penaltyAmount['penalty_amount'],
//            "delay_days" => $penaltyAmount['delay_days'],
//            "future_interest_discount" => round($futureInterestDiscount, 2),
//        ];
//
//    }
//    public function calculateCurrentPayment($contract, $date = null)
//    {
//        $calculationDate = $date ? Carbon::parse($date)->endOfDay() : Carbon::now();
//
//        if ($contract->closed_at && Carbon::parse($contract->closed_at)->lte($calculationDate)) {
//            return [
//                "current_amount" => 0,
//                "penalty_amount" => 0,
//                "future_interest_discount" => 0,
//            ];
//        }
//
//        $penaltyAmount = $this->countPenalty($contract->id, $calculationDate);
//
//        $contractEndDateRecord = Payment::where('last_payment', 1)
//            ->where('contract_id', $contract->id)
//            ->first();
//
//        if ($contractEndDateRecord) {
//            $endDate = Carbon::parse($contractEndDateRecord->date);
//            $currentDate = $endDate->lt($calculationDate) ? $endDate : $calculationDate;
//        } else {
//            $currentDate = $calculationDate;
//        }
//
//        $partialPayments = Payment::where('contract_id', $contract->id)
//            ->where('type', 'partial')
//            ->where('date', '<=', $calculationDate)
//            ->orderBy('date', 'asc')
//            ->get();
//
//        $remainingAmount = $contract->mother;
//        $totalPayment = 0;
//        $lastPaymentDate = Carbon::parse($contract->date);
//
//        if ($partialPayments) {
//            foreach ($partialPayments as $partialPayment) {
//                $daysPassed = $lastPaymentDate->diffInDays($partialPayment->date);
//                $totalPayment += $this->calcAmount($remainingAmount, $daysPassed, $contract->interest_rate);
//                $remainingAmount -= $partialPayment->paid;
//                $lastPaymentDate = Carbon::parse($partialPayment->date);
//            }
//        }
//
//        $daysPassed = $lastPaymentDate->diffInDays($currentDate);
//        $totalPayment += $this->calcAmount($remainingAmount, $daysPassed, $contract->interest_rate);
//
////        $totalPaid = Payment::where('contract_id', $contract->id)
////            ->where('type', 'regular')
////            ->where('date', '<=', $calculationDate)
////            ->sum('paid');
//
//        $journalId =  DocumentJournal::where('journalable_id', $contract->id)
//            ->where('journalable_type','App\Models\Contract')
//            ->value('id');
//        $totalPaid = DocumentJournal::where('journalable_id', $journalId)
//            ->where('journalable_type','App\Models\DocumentJournal')
//            ->where('document_type',DocumentJournal::PAY_INTEREST_AMOUNT)
//            ->where('date', '<=', $currentDate)
//            ->sum('amount_amd');
//        $currentAmount = $totalPayment - $totalPaid + $penaltyAmount['penalty_amount'];
//
//        $futureInterestDiscount = 0.0;
//        $nextInitialRegularPayment = Payment::where('contract_id', $contract->id)
//            ->where('type', 'regular')
//            ->where('status', 'initial')
//            ->where('to_date', '>', $calculationDate)
//            ->orderBy('to_date')
//            ->orderBy('id')
//            ->first();
//
//        if ($nextInitialRegularPayment) {
//            $dueDate = Carbon::parse($nextInitialRegularPayment->to_date ?? $nextInitialRegularPayment->date)
//                ->startOfDay();
//
//            $futureDays = $calculationDate->diffInDays($dueDate, false);
//
//            if ($futureDays > 0) {
//                $principalBase = max(0, (float) ($contract->provided_amount ?? 0));
//                $dailyRate = (float) ($contract->interest_rate ?? 0) / 100;
//                $futureInterestDiscount = $principalBase * $futureDays * $dailyRate;
//            }
//        }
//
//        return [
//            "daysPassed" => $daysPassed,
//            "endDate" => $currentDate,
//            "totalPayment" => $totalPayment,
//            "totalPaid" => $totalPaid,
//            "penaltyAmount" => $penaltyAmount,
//            "current_amount" => $currentAmount > 0 ? $currentAmount : 0,
//            "penalty_amount" => $penaltyAmount['penalty_amount'],
//            "delay_days" => $penaltyAmount['delay_days'],
//            "future_interest_discount" => round($futureInterestDiscount, 2),
//        ];
//    }
    public function calculateCurrentPayment($contract, $date = null)
    {
        $calculationDate = $date ? Carbon::parse($date)->endOfDay() : Carbon::now();

        if ($contract->closed_at && Carbon::parse($contract->closed_at)->lte($calculationDate)) {
            return [
                "current_amount" => 0,
                "penalty_amount" => 0,
                "future_interest_discount" => 0,
                'interest_amount' => 0,
            ];
        }

        $penaltyAmount = $this->countPenalty($contract->id, $calculationDate);
        $penalty = $penaltyAmount['penalty_amount'];
        $contractEndDateRecord = Payment::where('last_payment', 1)
            ->where('contract_id', $contract->id)
            ->first();

        if ($contractEndDateRecord) {
            $endDate = Carbon::parse($contractEndDateRecord->date);
            $currentDate = $endDate->lt($calculationDate) ? $endDate : $calculationDate;
        } else {
            $currentDate = $calculationDate;
        }

        $scheduledPayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status','initial')
            ->orderBy('id','asc')
//            ->orderBy('from_date', 'asc')
            ->get();

        $interestAmount = 0.0;
        $count = 0;
        $carryPrincipal = 0.0; // unpaid principal from earlier elapsed periods, still accruing interest
        foreach ($scheduledPayments as $payment) {
            $payment = $this->normalizePaymentDates($payment, $contract);
            $fromDate = Carbon::parse($payment->from_date)->startOfDay();
            $toDate   = Carbon::parse($payment->date)->startOfDay(); // payment date = period end
            if ($fromDate->gte($currentDate)) {
                break;
            }
            $count++;
            $balance = (float) ($payment->remaining + $payment->principal_payment) + $carryPrincipal;

            if ($toDate->lte($currentDate)) {
                $alreadyPaidInterest = (float) $payment->entries()->sum('interest_amount');
                $interestAmount += max(0, (float) $payment->interest_payment - $alreadyPaidInterest);

                $collectedPrincipal = (float) $payment->entries()->sum('principal_amount');
                $carryPrincipal += max(0, (float) $payment->principal_payment - $collectedPrincipal);
            } else {
                $daysIntoCurrentPeriod = $fromDate->diffInDays($currentDate);
                $interestAmount += $this->calcAmount(
                    $balance,
                    $daysIntoCurrentPeriod,
                    $contract->interest_rate
                );
                break;
            }
        }
        $journalId = DocumentJournal::where('journalable_id', $contract->id)
            ->where('journalable_type', 'App\Models\Contract')
            ->value('id');

//        $totalPaid = DocumentJournal::where('journalable_id', $journalId)
//            ->where('journalable_type', 'App\Models\DocumentJournal')
//            ->where('document_type', DocumentJournal::PAY_INTEREST_AMOUNT)
//            ->where('date', '<=', $currentDate)
//            ->sum('amount_amd');

//        $interestAmount = $totalAccruedInterest - $totalPaid;

        // Future interest discount
        $futureInterestDiscount = 0.0;
        $nextInitialRegularPayment = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->where('to_date', '>', $calculationDate)
            ->orderBy('to_date')
            ->orderBy('id')
            ->first();

        if ($nextInitialRegularPayment) {
            $dueDate    = Carbon::parse($nextInitialRegularPayment->to_date ?? $nextInitialRegularPayment->date)->startOfDay();
            $futureDays = $calculationDate->diffInDays($dueDate, false);

            if ($futureDays > 0) {
                $principalBase = max(0, (float) ($contract->provided_amount ?? 0));
                $dailyRate     = (float) ($contract->interest_rate ?? 0) / 100;
                $futureInterestDiscount = $principalBase * $futureDays * $dailyRate;
            }
        }
        return [
            "endDate"                  => $currentDate,
//            "totalAccruedInterest"     => $totalAccruedInterest,
//            "totalPaid"                => $totalPaid,
            "penaltyAmount"            => $penaltyAmount,
            "current_amount"           => $interestAmount + $penalty,
            'interest_amount'          => $interestAmount,
            "penalty_amount"           => $penalty,
            "delay_days"               => $penaltyAmount['delay_days'],
            "future_interest_discount" => round($futureInterestDiscount, 2),
        ];
    }
    public function calculateCurrentPayment1($contract, $date = null)
    {
        $calculationDate = $date ? Carbon::parse($date)->endOfDay() : Carbon::now();

        if ($contract->closed_at && Carbon::parse($contract->closed_at)->lte($calculationDate)) {
            return [
                "current_amount" => 0,
                "penalty_amount" => 0,
                "future_interest_discount" => 0,
                'interest_amount' => 0,
            ];
        }

        $penaltyAmount = $this->countPenalty($contract->id, $calculationDate);
        $penalty = $penaltyAmount['penalty_amount'];
        $contractEndDateRecord = Payment::where('last_payment', 1)
            ->where('contract_id', $contract->id)
            ->first();

        if ($contractEndDateRecord) {
            $endDate = Carbon::parse($contractEndDateRecord->date);
            $currentDate = $endDate->lt($calculationDate) ? $endDate : $calculationDate;
        } else {
            $currentDate = $calculationDate;
        }

        $scheduledPayments = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status','initial')
            ->orderBy('id','asc')
//            ->orderBy('from_date', 'asc')
            ->get();

        $interestAmount = 0.0;
        $count = 0;
        $carryPrincipal = 0.0; // unpaid principal from earlier elapsed periods, still accruing interest
        foreach ($scheduledPayments as $payment) {
            $payment = $this->normalizePaymentDates($payment, $contract);
            $fromDate = Carbon::parse($payment->from_date)->startOfDay();
            $toDate   = Carbon::parse($payment->date)->startOfDay(); // payment date = period end
            if ($fromDate->gte($currentDate)) {
                break;
            }
            $count++;
            $balance = (float) ($payment->remaining + $payment->principal_payment) + $carryPrincipal;

            if ($toDate->lte($currentDate)) {
                $entries = $payment->entries()->get();

                $alreadyPaidInterest = (float) $entries->sum('interest_amount');
                if ($entries->isEmpty()) {
                    $alreadyPaidInterest = min(
                        (float) $payment->paid,
                        max(0, (float) $payment->original_interest_payment - (float) $payment->interest_payment)
                    );
                    $interestAmount += max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
                } else {
                    $interestAmount += max(0, (float) $payment->interest_payment - $alreadyPaidInterest);
                }

                $collectedPrincipal = (float) $entries->sum('principal_amount');
                if ($entries->isEmpty()) {
                    $collectedPrincipal = min(
                        (float) $payment->paid,
                        max(0, (float) $payment->original_principal_payment - (float) $payment->principal_payment)
                    );
                }
                $carryPrincipal += max(0, (float) $payment->principal_payment - $collectedPrincipal);
//            }            else {
//                $daysIntoCurrentPeriod = $fromDate->diffInDays($currentDate);
//                $accruedInterest = $this->calcAmount(
//                    $balance,
//                    $daysIntoCurrentPeriod,
//                    $contract->interest_rate
//                );
//
//                $entries = $payment->entries()->get();
//                $alreadyPaidInterest = (float) $entries->sum('interest_amount');
//                if ($entries->isEmpty()) {
//                    $alreadyPaidInterest = min(
//                        (float) $payment->paid,
//                        max(0, (float) $payment->original_interest_payment - (float) $payment->interest_payment)
//                    );
//                }
//                $interestAmount += max(0, $accruedInterest - $alreadyPaidInterest);
//                break;
//            }
                } else {
                $daysIntoCurrentPeriod = $fromDate->diffInDays($currentDate);
                $interestAmount += $this->calcAmount(
                    $balance,
                    $daysIntoCurrentPeriod,
                    $contract->interest_rate
                );
        break;
    }
        }
        $journalId = DocumentJournal::where('journalable_id', $contract->id)
            ->where('journalable_type', 'App\Models\Contract')
            ->value('id');

//        $totalPaid = DocumentJournal::where('journalable_id', $journalId)
//            ->where('journalable_type', 'App\Models\DocumentJournal')
//            ->where('document_type', DocumentJournal::PAY_INTEREST_AMOUNT)
//            ->where('date', '<=', $currentDate)
//            ->sum('amount_amd');

//        $interestAmount = $totalAccruedInterest - $totalPaid;

        // Future interest discount
        $futureInterestDiscount = 0.0;
        $nextInitialRegularPayment = Payment::where('contract_id', $contract->id)
            ->where('type', 'regular')
            ->where('status', 'initial')
            ->where('to_date', '>', $calculationDate)
            ->orderBy('to_date')
            ->orderBy('id')
            ->first();

        if ($nextInitialRegularPayment) {
            $dueDate    = Carbon::parse($nextInitialRegularPayment->to_date ?? $nextInitialRegularPayment->date)->startOfDay();
            $futureDays = $calculationDate->diffInDays($dueDate, false);

            if ($futureDays > 0) {
                $principalBase = max(0, (float) ($contract->provided_amount ?? 0));
                $dailyRate     = (float) ($contract->interest_rate ?? 0) / 100;
                $futureInterestDiscount = $principalBase * $futureDays * $dailyRate;
            }
        }
        return [
            "endDate"                  => $currentDate,
//            "totalAccruedInterest"     => $totalAccruedInterest,
//            "totalPaid"                => $totalPaid,
            "penaltyAmount"            => $penaltyAmount,
            "current_amount"           => $interestAmount + $penalty,
            'interest_amount'          => $interestAmount,
            "penalty_amount"           => $penalty,
            "delay_days"               => $penaltyAmount['delay_days'],
            "future_interest_discount" => round($futureInterestDiscount, 2),
        ];
    }

    /**
     * How much principal (mother amount) still needs to be paid to close the contract —
     * the remaining provided_amount, credited by whatever the prepayment bucket
     * (unpaid Prepayment records) already covers. Used both for the payoff quote shown
     * to the frontend and for the actual full-payment processing, so both agree.
     */
    public function calculateMotherAmountToPay(Contract $contract): array
    {
        $providedAmount = (float) $contract->provided_amount;

        $bucketPrincipalLike = (float) Prepayment::where('contract_id', $contract->id)
            ->where('status', 'unpaid')
            ->get()
            ->sum(fn ($p) => (float) $p->principal_amount + (float) $p->partial_amount);

        $principalFromBucket = min($bucketPrincipalLike, $providedAmount);

        return [
            'provided_amount'       => $providedAmount,
            'principal_from_bucket' => $principalFromBucket,
            'mother_amount_to_pay'  => $providedAmount - $principalFromBucket,
            'prepayment_balance'    => $bucketPrincipalLike,
        ];
    }

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
                $payment_date = Carbon::parse($payment->date);  //2026-01-13

                $lastPaidPenalty = Payment::where('contract_id', $contract->id)
                    ->where('type', 'penalty')
                    ->where('parent_id', $payment->id)
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
                        $max_delay_days = $current_delay_days; //33
                        $first_penalty_start_date = $penalty_start_date; //2026-01-13
                        $primary_parent_id = $payment->id; //659
                    }

                    $current_penalty = $this->calcAmount($payment->amount, $current_delay_days, $contract->penalty);

                    $penalty_paid = Payment::where('contract_id', $contract->id)
                        ->where('type', 'penalty')
                        ->where('parent_id', $payment->id)
                        ->sum('paid') ?? 0;

//                    $total_penalty_amount += ($current_penalty - $penalty_paid);
                    $total_penalty_amount += ($current_penalty);


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

    private function createAccountingTransaction($contract, $amount, $ruleKey, $comment, $dealId, $date = null)
    {
        $rule = PostingRule::where('business_event_filter', $ruleKey)->first();
        if (!$rule) return;

        $date = $date ?? Carbon::now()->format('Y-m-d');
        $debitAccountId = $rule->debit_account_id;
        $creditAccountId = $rule->credit_account_id;
        $debetPartnerId = Client::where('company_name', 'Diamond Credit')->first()->id ?? 1;

        return DB::transaction(function () use ($contract, $amount, $ruleKey, $comment, $dealId, $date, $debitAccountId, $creditAccountId, $debetPartnerId, $rule) {
            $nextDocNum = Transaction::getNextDocumentNumber();

            $journalDoc = DocumentJournal::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => $ruleKey,
                'amount_amd' => $amount,
                'debit_partner_id' => $rule->resolveDebitPartnerId($contract),
                'credit_partner_id' => $rule->resolveCreditPartnerId($contract),
                'comment' => $comment,
                'debit_account_id' => $debitAccountId,
                'credit_account_id' => $creditAccountId,
                'user_id' => auth()->id(),
                'journalable_type' => Contract::class,
                'journalable_id' => $contract->id,
                'deal_id' => $dealId,
                'contract_id' => $contract->id,
            ]);

            Transaction::create([
                'date' => $date,
                'document_number' => $nextDocNum,
                'document_type' => $ruleKey,
                'debit_account_id' => $debitAccountId,
                'debit_partner_id' => $rule->resolveDebitPartnerId($contract),
                'credit_account_id' => $creditAccountId,
                'credit_partner_id' => $rule->resolveCreditPartnerId($contract),
                'amount_amd' => $amount,
                'comment' => $comment,
                'user_id' => auth()->id(),
                'transactionable_type' => DocumentJournal::class,
                'transactionable_id' => $journalDoc->id,
                'contract_id' => $contract->id,
            ]);

            return $journalDoc->id;
        });
    }

    protected function normalizePaymentDates($payment, $contract)
    {
        if ($payment->from_date && $payment->days) {
            return $payment;
        }

        $toDate = Carbon::parse($payment->to_date);

        $prevPayment = Payment::where('contract_id', $contract->id)
            ->where('to_date', '<', $payment->to_date)
            ->orderBy('to_date', 'desc')
            ->first();

        if ($prevPayment) {
            $fromDate = Carbon::parse($prevPayment->to_date);
        } else {
            $fromDate = Carbon::parse($contract->date);
        }

        $days = $toDate->diffInDays($fromDate);

        $payment->from_date = $fromDate->format('Y-m-d');
        $payment->days = $days;
        $payment->save();
        return $payment;
    }
    protected function calculateCurrentAmortizedBalance(Contract $contract): float
    {
        $initialProvided = (float)$contract->mother;
        $fees = $initialProvided * ($contract->lump_rate / 100);
        $netAmount = $initialProvided - $fees;

        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();

        if (!$journal) return $netAmount;

        $effectiveAccrualsSum = DocumentJournal::where('journalable_id', $journal->id)
            ->where('journalable_type', DocumentJournal::class)
            ->where('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT)
            ->sum('amount_amd');

        $nominalAccrualsSum = DocumentJournal::where('journalable_id', $journal->id)
            ->where('journalable_type', DocumentJournal::class)
            ->where('document_type', DocumentJournal::INTEREST_REPAYMENT)
            ->sum('amount_amd');

        $motherPaymentsSum = DocumentJournal::where('journalable_id', $journal->id)
            ->where('journalable_type', DocumentJournal::class)
            ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
            ->sum('amount_amd');

        return $netAmount + $effectiveAccrualsSum - $nominalAccrualsSum - $motherPaymentsSum;
    }
    private function createJournalAndTransaction(
        $date,
        &$docNum,
        $type,
        $amount,
        $comment,
        $debitAccount,
        $creditAccount,
        $dealId,
        $journalableId,
        $contractId = null
    ) {
        $journal = DocumentJournal::create([
            'date'               => $date,
            'document_number'    => $docNum,
            'document_type'      => $type,
            'amount_amd'         => $amount,
            'comment'            => $comment,
            'debit_account_id'   => $debitAccount,
            'credit_account_id'  => $creditAccount,
            'user_id'            => auth()->id(),
            'journalable_type'   => DocumentJournal::class,
            'journalable_id'     => $journalableId,
            'deal_id'            => $dealId,
            'contract_id'        => $contractId,
        ]);

        Transaction::create([
            'date'               => $date,
            'document_number'    => $docNum,
            'document_type'      => $type,
            'debit_account_id'   => $debitAccount,
            'debit_currency_id'  => 1,
            'credit_account_id'  => $creditAccount,
            'credit_currency_id' => 1,
            'amount_amd'         => $amount,
            'comment'            => $comment,
            'user_id'            => auth()->id(),
            'is_system'          => false,
            'disbursement_date'  => $date,
            'transactionable_type'=> DocumentJournal::class,
            'transactionable_id' => $journal->id,
            'contract_id'        => $contractId,
        ]);

        $docNum++;

        return $journal;
    }

    private function getPostingRule(string $filter): PostingRule
    {
        $rule = PostingRule::where('business_event_filter', $filter)->first();

        if (!$rule) {
            throw new \RuntimeException("Posting rule not found: {$filter}");
        }

        return $rule;
    }

    private function resolveEvent(string $base, string $class, bool $cash, $filter = null): string
    {
        $prefix = ($class === 'loss' && $filter === 'principal') ? 'pay_principal' : $base;
        $suffix = ($class === 'loss' ? '_loss' : '') . ($cash ? '_cash' : '');

        return $prefix . $suffix;
    }
//    private function resolveEvent(string $base, string $class, bool $cash,$filter=null): string
//    {
//        if ($class === 'loss') {
//            if ($cash) {
//                if ($filter == 'principal') {
//                    return 'pay_principal_loss_cash';
//
//                } else {
//                    return "{$base}_loss_cash";
//                }
//            }
//            if ($filter == 'principal') {
//                return 'pay_principal_loss';
//            } else {
//                return "{$base}_loss";
//            }
//        }
//
//        if ($cash) {
//            return "{$base}_cash";
//        }
//
//        return $base;
//    }
    private function postEntry(
        $date,
        &$docNum,
        $type,
        $amount,
        $comment,
        $debit,
        $credit,
        $dealId,
        $journalId,
        $clientId = null,
        $contractId = null,
        $rule = null,
        $contract = null
    ) {
        $journal = DocumentJournal::create([
            'date' => $date,
            'document_number' => $docNum,
            'document_type' => $type,
            'amount_amd' => $amount,
            'debit_partner_id' => $rule?->resolveDebitPartnerId($contract),
            'credit_partner_id' => $rule?->resolveCreditPartnerId($contract),
            'comment' => $comment,
            'debit_account_id' => $debit,
            'credit_account_id' => $credit,
            'user_id' => auth()->id(),
            'journalable_type' => DocumentJournal::class,
            'journalable_id' => $journalId,
            'deal_id' => $dealId,
            'contract_id' => $contractId,
        ]);

        Transaction::create([
            'date' => $date,
            'document_number' => $docNum,
            'document_type' => $type,
            'debit_account_id' => $debit,
            'credit_account_id' => $credit,
            'debit_partner_id' => $rule?->resolveDebitPartnerId($contract),
            'credit_partner_id' => $rule?->resolveCreditPartnerId($contract),
            'debit_currency_id' => 1,
            'credit_currency_id' => 1,
            'amount_amd' => $amount,
            'comment' => $comment,
            'user_id' => auth()->id(),
            'disbursement_date' => $date,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id' => $journal->id,
            'contract_id' => $contractId,
        ]);

        $docNum++;

        return $journal;
    }
}

