<?php

namespace App\Traits;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Payment;
use Carbon\Carbon;

trait ContractTrait
{
    public function getFullContract($id)
    {
        $contract = Contract::where('pawnshop_id',auth()->user()->pawnshop_id)->where('id', $id)
            ->with(['client','files','category','evaluator','payments' => function($payment){
                $payment->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC");
            },'history' => function($query){
                $query->with(['type','user','order'])->orderBy('id','DESC');
            },'items' => function($query){
                $query->with('category');
            },'discounts',])->first();
        if($contract && $contract->evaluator){
            $contract->evaluator_title = $contract->evaluator->full_name;
        }
        return $contract;
    }

    public function createDeal($amount,$interest_amount,$type,$contract_id,$order_id = null,$cash = true,$purpose = null,$receiver = null,$source = null){
        if($type === 'in'){
            if($cash){
                auth()->user()->pawnshop->cashbox = auth()->user()->pawnshop->cashbox + $amount;
            }else{
                auth()->user()->pawnshop->bank_cashbox = auth()->user()->pawnshop->bank_cashbox + $amount;
            }
        }else{
            if($cash){
                auth()->user()->pawnshop->cashbox = auth()->user()->pawnshop->cashbox - $amount;
            }else{
                auth()->user()->pawnshop->bank_cashbox = auth()->user()->pawnshop->bank_cashbox - $amount;
            }

        }
        if($amount < 0){
            $type = $type === 'in' ? 'out' : 'in';
            $amount = -$amount;
        }
        auth()->user()->pawnshop->save();
        Deal::create([
            'type' => $type,
            'amount' => $amount,
            'interest_amount' => $interest_amount,
            'date' => Carbon::now()->format('d.m.Y'),
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'contract_id' => $contract_id,
            'order_id' => $order_id,
            'cashbox' => auth()->user()->pawnshop->cashbox,
            'bank_cashbox' => auth()->user()->pawnshop->bank_cashbox,
            'worth' => auth()->user()->pawnshop->worth,
            'given' => auth()->user()->pawnshop->given,
            'purpose' => $purpose,
            'cash' => $cash,
            'receiver' => $receiver,
            'source' => $source,
        ]);
    }
    public function setContractPenalty($id){
        $contract = Contract::where('id', $id)->with('payments')->first();
        $now = Carbon::now();
        $dateToCheck = null;
        if($contract){
            for($i = 0; $i < count($contract->payments);$i++){
                $payment = $contract->payments[$i];
                if($now ->gt(Carbon::parse($payment->date)) && $payment->status === 'initial'){
                    $dateToCheck = Carbon::parse($payment->date);
                    break;
                }
            }
            if ($dateToCheck){
                $difference = $now->diffInDays($dateToCheck);
                $penalty = $contract->left * $difference * $contract->penalty * 0.01;
                $contract->penalty_amount = $penalty;
                $contract->save();
            }else{
                $contract->penalty_amount = 0;
                $contract->save();
            }
        }

    }



    public function createPayment($contract_id,$amount,$type,$payer,$cash){
        $status = ($type === 'penalty' ||  $type === 'full') ? 'completed' : 'initial';
        $payment = new Payment();
        $payment->amount = $amount;
        $payment->paid = $amount;
        $payment->contract_id = $contract_id;
        $payment->cash = $cash;
        $payment->type = $type;
        $payment->pawnshop_id = auth()->user()->pawnshop_id;
        $payment->date = Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y');
        $payment->status = $status;
        if($payer){
            $payment->another_payer = true;
            $payment->name = $payer['name'];
            $payment->surname = $payer['surname'];
            $payment->phone = $payer['phone'];

        }
        $payment->save();
        return $payment;
    }

    public function completePayment(){

    }

    public function calcAmount($amount,$days,$rate): int
    {
        return intval(ceil($days * $rate * $amount * 0.01 /10) * 10);
    }
    public function calculateCurrentPayment($contract): array
    {
        $penalty_amount = $this->countPenalty($contract->id);
        $contract_creation_date = \Illuminate\Support\Carbon::parse($contract->created_at);
        $current_date = Carbon::now();
        $days_passed = $contract_creation_date->diffInDays($current_date);
        $calculatedAmount = $this->calcAmount($contract->left, $days_passed, $contract->interest_rate);
        $total_paid = Payment::where('contract_id', $contract->id)
            ->where('type','regular')->sum('paid');
//        $penalty_paid = Payment::with('contract_id',$contract->id)
//            ->where('type','penalty')->sum('paid');
        $penalty = $penalty_amount ;
        $current_amount = $calculatedAmount - $total_paid + $penalty;

        return ["current_amount" => $current_amount > 0 ? $current_amount : 0,
                "penalty_amount"=>$penalty];
    }
    public function calculateCurrentPayment1($contract): array
    {
        $penalty_amount = $this->countPenalty($contract->id);
        $contract_creation_date = \Illuminate\Support\Carbon::parse($contract->created_at);
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


    public function countPenalty($contract_id)
    {
        $contract = Contract::where('id', $contract_id)->with('payments')->first();
        $penalty_paid = Payment::with('contract_id',$contract->id)
            ->where('type','penalty')->sum('paid');
        $now = \Carbon\Carbon::now();
        $dateToCheck = null;
        $penalty_amount = 0;
        if ($contract) {
            for ($i = 0; $i < count($contract->payments); $i++) {
                $payment = $contract->payments[$i];

                if ($now ->gt(\Carbon\Carbon::parse($payment->date)) && $payment->status === 'initial') {
                    $dateToCheck = Carbon::parse($payment->date);
                    break;
                }

            }
            if ($dateToCheck){
                $difference = $now->diffInDays($dateToCheck);
                $penalty_amount =( $this->calcAmount($contract->left, $difference, $contract->penalty) - $penalty_paid);
                $contract->penalty_amount = $penalty_amount;
                $contract->save();
            }else{
                $contract->penalty_amount = 0;
                $contract->save();
            }
            return $penalty_amount > 0 ? $penalty_amount : 0;
        }

    }
}
