<?php

namespace App\Http\Controllers;

use App\Events\DiscountResponse;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Order;
use App\Models\Payment;
use App\Traits\ContractTrait;
use App\Traits\FileTrait;
use App\Traits\HistoryTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class PaymentController extends Controller
{
    use ContractTrait, FileTrait, HistoryTrait;
    public function getPayments($id){
        $contract = $this->getFullContract($id);
        return response()->json([
             'contract' => $contract,
        ]);
    }

    public function makePayment(Request $request){
        $contract = Contract::where('id',$request->contract_id)->first();
        $amount = $request->amount;
        $penalty = $request->penalty;
        $payer = $request->payer;
        $cash = $request->cash;
        $paymentsSum = 0;
        foreach ($request -> payments as $item){
            $paymentsSum += $item['final'];
        }
        $order = $this->generateOrderIn($request);
        $this->createHistory($request, $order->id);
        if($amount){
            if($penalty){
                if($amount <= $penalty){
                    $this->createPayment($contract -> id,$amount,'penalty',$payer,$cash);
                    $amount = 0;
                }else{
                    $this->createPayment($contract -> id,$penalty,'penalty',$payer,$cash);
                    $amount -= $penalty;
                }
            }
            if($amount){
                foreach ($request -> payments as $item){
                    $payment = Payment::where('id', $item['id'])->first();
                    if(!$payment){
                        return response()->json([
                            'success' => 'error'
                        ]);
                    }
                    $paymentFinal = $item['final'];
                    if($amount >= $paymentFinal){
                        $payment->status = 'completed';
                        $payment->paid = $item['final'];
                        $payment->date = Carbon::now()->format('d.m.Y');
                        $payment->penalty = $item['penalty'];
                        $payment->cash = $request->cash;
                        if($payer){
                            $payment->another_payer = true;
                            $payment->name = $payer['name'];
                            $payment->surname = $payer['surname'];
                            $payment->phone = $payer['phone'];
                        }
                        $payment->save();
                        $contract->collected = $contract->collected + $paymentFinal;
                        $amount -= $paymentFinal;
                    }else{
                        $payment->amount -= $amount;
                        $payment->paid = $amount;
                        $contract->collected = $contract->collected + $amount;
                        $amount = 0;
                        $payment->save();
                    }
                }
                if($amount){
                    $decrease = $amount % 1000;
                    $amount = $amount - $decrease;
                    $nextPayment = Payment::where('contract_id',$request->contract_id)->where('status','initial')->first();
                    if($nextPayment){
                        $nextPayment->amount = $nextPayment->amount - $decrease;
                        $nextPayment->paid = $decrease;
                        $contract->collected = $contract->collected + $decrease;
                        $nextPayment->save();
                    }
                    $this->payPartial($request->contract_id,$amount,$request->payer,false,$cash);
                }
            }
            $this->createDeal($request->amount,'in',$contract->id,$order->id,$cash);
        }else{
            if($penalty){
                $this->createPayment($contract -> id,$penalty,'penalty',$payer,$cash);
            }
            foreach ($request -> payments as $item){
                $payment = Payment::where('id', $item['id'])->first();
                if(!$payment){
                    return response()->json([
                        'success' => 'error'
                    ]);
                }
                $payment->status = 'completed';
                $payment->paid = $payment->amount;
                $payment->date = Carbon::now()->format('d.m.Y');
                $payment->penalty = $item['penalty'];
                $payment->cash = $request->cash;
                if($request->payer){
                    $payment->another_payer = true;
                    $payment->name = $request->payer['name'];
                    $payment->surname = $request->payer['surname'];
                    $payment->phone = $request->payer['phone'];
                }
                if($payment->amount < 0){
                    $payment->amount = 0;
                }
                $payment->save();
                $contract->collected = $contract->collected + $payment->amount + $payment->mother;
            }
            $dealAmount = $penalty ? $penalty + $paymentsSum : $paymentsSum;
            $this->createDeal($dealAmount,'in',$contract->id,$order->id,$cash);
        }

        $paymentsLeft = $contract->payments -> filter(function ($value, int $key) {
            return $value->status === 'initial';
        });
        if(!count($paymentsLeft)){
            $contract->status = 'completed';
            $contract->left = 0;
        }
        $contract -> save();
        $contract = $this->getFullContract($request->contract_id);

        return response()->json([
            'success' => 'success',
            'contract' => $contract,
            'data' => $request->all()
        ]);

    }
    public function makeFullPayment(Request $request){
        $contract = Contract::where('id',$request->contract_id)->first();
        $amount = $request->amount;
        $payer = $request->payer;
        $cash = $request->cash;
        if(!$contract){
            return response() -> json([
                'success' => 'error'
            ]);
        }
        auth()->user()->pawnshop->given = auth()->user()->pawnshop->given - $contract->left;
        auth()->user()->pawnshop->save();
        $payments = Payment::where('contract_id',$request->contract_id)->get();
        foreach ($payments as $index => $payment){
            if($payment->status === 'initial'){
                $payment->delete();
            }
        }
        $this->createPayment($contract -> id,$amount,'full',$payer,$cash);
        $type = HistoryType::where('name','full_payment')->first();
        $purpose = 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
        if($request->hasPenalty){
            $purpose.= ', տուգանք';
        }
        $new_order = $this->generateOrder($contract,$request -> amount,$purpose,'in',$cash);
        History::create([
            'amount' => $request -> amount,
            'type_id' => $type->id,
            'user_id' => auth()->user()->id,
            'order_id' => $new_order->id,
            'contract_id' => $contract->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y')
        ]);
        $this->createDeal($request -> amount,'in',$contract->id,$new_order->id,$cash);
        $contract->status = 'completed';
        $contract->left = 0;
        $contract->collected = $contract->collected + $request -> amount;
        $contract->save();
        $contract = $this->getFullContract($request->contract_id);
        return response()->json([
            'all' => $request->all(),
            'contract' =>$contract,
        ]);
    }
    public function requestDiscount(Request $request){
        $contract = Contract::where('id',$request->contract_id)->first();
        if($contract){
            Discount::create([
                'amount' => $request->discount,
                'user_id' => auth()->user()->id,
                'contract_id' => $contract->id,
                'pawnshop_id' => auth()->user()->pawnshop_id
            ]);
            $contract = $this->getFullContract($contract->id);
            return response() -> json([
                'success' => 'success',
                'all' => $request->all(),
                'contract' => $contract
            ]);
        }
        return response() -> json([
            'success' => 'error',
            'all' => $request->all(),
        ]);
    }
    public function answerDiscount(Request $request){
        $discount = Discount::where('id',$request->id)->first();
        if($discount){
            if($request->answer === 'accept'){
                $discount->status = 'accepted';
                $discount->save();
                $history_type = HistoryType::where('name','discount')->first();
                History::create([
                    'amount' => $discount->amount,
                    'user_id' => auth()->user()->id,
                    'type_id' => $history_type->id,
                    'contract_id' => $discount->contract_id,
                    'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y')
                ]);
            }else if($request->answer === 'reject'){
                $discount->status = 'rejected';
                $discount->save();
            }
            event(new DiscountResponse(auth()->user()->id, auth()->user()->pawnshop_id,$discount->contract_id));
            $contract = $this->getFullContract($discount->contract_id);
            return response() -> json([
                'success' => 'success',
                'all' => $request->all(),
                'contract' => $contract
            ]);
        }
        return response() -> json([
            'success' => 'error',
            'all' => $request->all(),
        ]);
    }
    public function calcAmount($amount,$days,$rate){
        return intval(ceil($days * $rate * $amount * 0.01 /10) * 10);
    }
    public function payPartial($contract_id,$partial_amount,$payer,$with_history,$cash){
        $contract = Contract::where('id',$contract_id)->first();
        $payments = Payment::where('contract_id',$contract_id)->where('type','regular')->get();
        $now  = Carbon::now();
        $partial = $partial_amount;
        $daysToCalc = 0;
        $startedToChange = false;
        foreach ($payments as $index => $payment){
            $dateToCheck = Carbon::parse($payment->date);
            if($dateToCheck->gt($now)){
                if($startedToChange){
                    $coeff = ($contract->left - $partial) / $contract->left;
                    $payment->amount = intval(ceil($payment->amount * $coeff /10) * 10);
                }else{
                    $startedToChange = true;
                    if($index === 0){
                        $daysToCalc = $now->diffInDays(Carbon::parse($contract->date));
                    }else{
                        $daysToCalc = $now->diffInDays(Carbon::parse($payments[$index - 1]->date));
                    }
                    $daysLeft = $payment->days - $daysToCalc;
                    $sum = $payment->amount;
                    $amount = $contract->left;
                    $sum-= $this->calcAmount($amount,$daysLeft,$contract->rate);
                    $amount = $contract->left - $partial;
                    $sum+= $this->calcAmount($amount,$daysLeft,$contract->rate);
                    $payment->amount = $sum;
                }
                $payment->save();
            }
            if($payment->last_payment){
                $payment->mother = $contract->left - $partial;
                $payment->save();
            }
        }
        $contract->left = $contract->left - $partial_amount;
        $contract->collected = $contract->collected + $partial_amount;
        $contract->save();
        auth()->user()->pawnshop->given = auth()->user()->pawnshop->given - $partial;
        auth()->user()->pawnshop -> save();
        if($with_history){
            $type = HistoryType::where('name','partial_payment')->first();
            $client_name = $contract->name.' '.$contract->surname.' '.$contract->middle_name;
            $order_id = $this->getOrder($cash,'in');
            $res = [
                'contract_id' => $contract->id,
                'type' => 'in',
                'title' => 'Օրդեր',
                'pawnshop_id' => auth()->user()->pawnshop_id,
                'order' => $order_id,
                'amount' => $partial,
                'rep_id' => '2211',
                'date' => Carbon::now()->format('d.m.Y'),
                'client_name' => $client_name,
                'purpose' => 'Մասնակի մարում',
            ];
            $new_order = Order::create($res);
            History::create([
                'amount' => $partial,
                'user_id' => auth()->user()->id,
                'type_id' => $type->id,
                'order_id' => $new_order->id,
                'contract_id' => $contract->id,
                'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y')
            ]);
            $this->createDeal($partial,'in',$contract_id,$new_order->id,$cash);

            $partial_payment = new Payment();
            $partial_payment->amount = $partial;
            $partial_payment->paid = $partial;
            $partial_payment->cash = $cash;
            $partial_payment->contract_id = $contract -> id;
            $partial_payment->type = 'partial';
            $partial_payment->pawnshop_id = auth()->user()->pawnshop_id;
            $partial_payment->date = Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y');
            $partial_payment->status = 'completed';
            if($payer){
                $partial_payment->another_payer = true;
                $partial_payment->name = $payer['name'];
                $partial_payment->surname = $payer['surname'];
                $partial_payment->phone = $payer['phone'];
            }
            $partial_payment->save();
        }


        $contract = $this->getFullContract($contract_id);
        return $contract;
    }
    public function makePartialPayment(Request $request){
        $contract = $this->payPartial($request->contract_id,$request->amount,$request->payer,true,$request->cash);
        return response()->json([
            'all' => $request->all(),
            'contract' =>$contract,
        ]);
    }
}
