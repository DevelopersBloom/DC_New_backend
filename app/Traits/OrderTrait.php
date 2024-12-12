<?php

namespace App\Traits;

trait OrderTrait
{
    public function getOrder($cash,$type){
        $order = null;
        if($type === 'in'){
            if($cash){
                $order = auth()->user()->pawnshop->order_in;
                auth()->user()->pawnshop->order_in = auth()->user()->pawnshop->order_in + 1;
            }else{
                $order = 'Ա'. auth()->user()->pawnshop->bank_order_in;
                auth()->user()->pawnshop->bank_order_in = auth()->user()->pawnshop->bank_order_in + 1;
            }
        }else{
            if($cash){
                $order = auth()->user()->pawnshop->order_out;
                auth()->user()->pawnshop->order_out = auth()->user()->pawnshop->order_out + 1;
            }else{
                $order = 'Ա'.auth()->user()->pawnshop->bank_order_out;
                auth()->user()->pawnshop->bank_order_out = auth()->user()->pawnshop->bank_order_out + 1;
            }
        }
        auth()->user()->pawnshop->save();
        return $order;
    }
}
