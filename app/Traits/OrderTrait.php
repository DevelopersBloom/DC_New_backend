<?php

namespace App\Traits;

use App\Models\Pawnshop;

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
        $pawnshop = $pawnshop_id ? Pawnshop::find($pawnshop_id) : auth()->user()->pawnshop;
        if (!$pawnshop) {
            throw new \Exception('Pawnshop not found.');
        }

        $order = null;
        if ($type === 'in') {
            if ($cash) {
                $order = $pawnshop->order_in;
                $pawnshop->order_in += 1;
            } else {
                $order = 'Ա' . $pawnshop->bank_order_in;
                $pawnshop->bank_order_in += 1;
            }
        } else {
            if ($cash) {
                $order = $pawnshop->order_out;
                $pawnshop->order_out += 1;
            } else {
                $order = 'Ա' . $pawnshop->bank_order_out;
                $pawnshop->bank_order_out += 1;
            }
        }

        $pawnshop->save();

        return $order;
    }

}
