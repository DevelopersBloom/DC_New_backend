<?php

namespace App\Traits;

use App\Models\Deal;
use App\Models\Order;
use App\Models\Pawnshop;
use Carbon\Carbon;

trait OrderTrait
{
//    public function getOrder($cash,$type){
//        $order = null;
//        if($type === 'in'){
//            if($cash){
//                $order = auth()->user()->pawnshop->order_in;
//                auth()->user()->pawnshop->order_in = auth()->user()->pawnshop->order_in + 1;
//            }else{
//                $order = 'Ա'. auth()->user()->pawnshop->bank_order_in;
//                auth()->user()->pawnshop->bank_order_in = auth()->user()->pawnshop->bank_order_in + 1;
//            }
//        }else{
//            if($cash){
//                $order = auth()->user()->pawnshop->order_out;
//                auth()->user()->pawnshop->order_out = auth()->user()->pawnshop->order_out + 1;
//            }else{
//                $order = 'Ա'.auth()->user()->pawnshop->bank_order_out;
//                auth()->user()->pawnshop->bank_order_out = auth()->user()->pawnshop->bank_order_out + 1;
//            }
//        }
//        auth()->user()->pawnshop->save();
//        return $order;
//    }
    public function getOrder($cash, $type, $pawnshop_id = null)
    {
        $type = $type === 'in' ? 'in' : 'out';
        $lastOrder = Order::
            where('cash',$cash)
            ->latest('id')
            ->value('order');
        $nextOrder = $lastOrder ? (int) preg_replace('/[^0-9]/', '', $lastOrder) + 1 : 1;

        return( $cash ? $nextOrder :  'Ա' . $nextOrder);
    }
    private function createOrderAndDeal($order_id, string $type, ?string $title, $amount, $purpose, $receiver, $cash,$filter_type,$interestAmount = null,$clientId = null)
    {
        $order = $this->createOrder($type, $title, $amount, $order_id, $purpose, $receiver,$cash);
        $this->createDeal($amount, $interestAmount, null, null, null,$type,null,$clientId,$order->id, $cash,$receiver,$purpose,$filter_type);
        return $order->id;
    }
    private function createOrder(string $type, ?string $title, $amount, $order_id, $purpose, $receiver,$cash)
    {
        return Order::create([
            'type' => $type,
            'title' => $title,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $amount,
            'date' => Carbon::now()->format('Y-m-d'),
            'purpose' => $purpose,
            'receiver' => $receiver,
            'cash' => $cash
        ]);
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

}
