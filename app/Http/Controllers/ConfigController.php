<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Pawnshop;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function getCashboxList(Request $request){
        $res = $this->calculateCashboxes($request->month);
        return response()->json([
            'cashboxes' => $res
        ]);

    }
    public function calculateCashboxes($month){
        $initial_cashbox = 8852134;
        $days = Carbon::createFromFormat('Y','2024')->month($month)->daysInMonth;
        $start_date = Carbon::parse('2024-01-01');
        $res = [];
        for($i = 1; $i <= $days; $i++){
            $day = $i < 10 ? '0'.$i : $i;
            $date = Carbon::parse('2024'.'-'.$month.'-'.$day);
            if(Carbon::now()->gt($date)){
                $dealsIn = Deal::where('type','in')->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [$start_date])
                    ->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$date])->sum('amount');
                $dealsOut = Deal::whereIn('type',['regular_out','out'])->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [$start_date])
                    ->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$date])->sum('amount');
                $cahbox = $initial_cashbox + $dealsIn - $dealsOut;
                $res[] = [
                    'date' => $date->format('d.m.Y'),
                    'amount' => $cahbox
                ];
            }

        }
        return $res;
    }
    public function setCashboxValue(Request $request){
        $value = $request->amount;
        $new_value = $request->changed_amount;
        $date = $request->date;
        $month = $request->month;
        $type = null;
        $amount = null;
        if($value !== $new_value){
            if($value > $new_value){
                $type = 'out';
                $amount = $value - $new_value ;
            }else{
                $type = 'in';
                $amount = $new_value - $value;
            }
            $lastDeal = Deal::where('date',$date)->orderBy('id','DESC')->first();
            Deal::create([
                'type' => $type,
                'amount' => $amount,
                'date' => $date,
                'insurance' => $lastDeal->insurance,
                'funds' => $lastDeal->funds,
                'pawnshop_id' => 1
            ]);
        }
        $res = $this->calculateCashboxes($month);
        return response()->json([
            'cashboxes' => $res
        ]);
    }

    public function calculateCashboxesFinal(){
        $cashbox = 8852134;
        $deals = Deal::whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [Carbon::parse('2024-01-01')])
            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC")->orderBy('id','ASC')->get();
        foreach ($deals as $deal){
            if($deal->type === 'in'){
                $cashbox = $cashbox + $deal->amount;
            }else{
                $cashbox = $cashbox - $deal->amount;
            }
            $deal->cashbox = $cashbox;
            $deal->save();
        }
        $pawnshop = Pawnshop::where('id',auth()->user()->pawnshop_id)->first();
        $date = Carbon::now();
        $contracts_query = Contract::where('pawnshop_id',auth()->user()->pawnshop_id)->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$date])->where('status','initial');
        $contract_ids = $contracts_query->get()->pluck('id');
        $partial_payments_amount = Payment::where('type','partial')->whereIn('contract_id',$contract_ids)->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$date])->sum('amount');
        $worth = $contracts_query->sum('worth');
        $given = $contracts_query->sum('given');
        $given -= $partial_payments_amount;
        $pawnshop -> worth = $worth;
        $pawnshop -> given = $given;
        $deal = Deal::orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")->orderBy('id','DESC')->first();
        $pawnshop -> funds = $deal->funds;
        $pawnshop -> insurance = $deal->insurance;
        $pawnshop -> cashbox = $cashbox;
        $pawnshop -> save();
        auth()->user()->config->cashboxes_calculated = true;
        auth()->user()->config->save();
        return response()->json([
            'success' => 'success',
            'user' => auth()->user()
        ]);
    }

    public function setBankCashboxValue(Request $request){
        $amount = intval($request->amount);
        auth()->user()->pawnshop->cashbox = auth()->user()->pawnshop->cashbox - $amount;
        auth()->user()->pawnshop->bank_cashbox = $amount;
        auth()->user()->pawnshop->save();
        auth()->user()->config->online_cashbox_set = true;
        auth()->user()->config->save();
        return response()->json([
            'success' => 'success',
            'user' => auth()->user()
        ]);
    }

    public function setOrders(Request $request){
        $order_in = $request->orderIn;
        $bank_order_in = $request->bankOrderIn;
        $order_out = $request->orderOut;
        $bank_order_out = $request->bankOrderOut;
        auth()->user()->pawnshop->order_in = $order_in + 1;
        auth()->user()->pawnshop->bank_order_in = $bank_order_in + 1;
        auth()->user()->pawnshop->order_out = $order_out + 1;
        auth()->user()->pawnshop->bank_order_out = $bank_order_out + 1;
        auth()->user()->pawnshop->save();
        auth()->user()->config->orders_set = true;
        auth()->user()->config->save();
        return response()->json([
            'success' => 'success',
            'user' => auth()->user()
        ]);

    }
}
