<?php

namespace App\Traits;

use App\Models\History;
use App\Models\HistoryType;
use Carbon\Carbon;

trait HistoryTrait
{
    public function createHistory($request,$order_id = null,$interest_amount=null,$delay_days=null,$penalty=null,
            $discount=null)
    {
        $history = new History();
        $history->contract_id = $request->contract_id;
        if (auth()->user()){
            $history->user_id =  auth()->user()->id;
        }
        $history->date = $request->date ?? Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d');
        $history->order_id = $order_id;
        $history->interest_amount = $interest_amount;
        $history->penalty = $penalty;
        $history->discount = $discount;
        $history->delay_days = $delay_days;

        $amount = $request->amount;

        if($amount){
            $history->amount = $amount;
        }else{
            $sum = 0;
            foreach ($request->payments as $item){
                $sum += $item['final'];
            }
            if($penalty){
                $sum += $penalty;
            }
            $history->amount = $sum;
        }
        if($amount && $penalty && $amount <= $penalty){
            $history_type = HistoryType::where('name','penalty_payment')->first();
            $history->type_id = $history_type->id;
            $history->save();
            return $history;
        }
        $history_type = HistoryType::where('name','regular_payment')->first();
        $history->type_id = $history_type->id;
        $history->save();
        return $history;
    }
}
