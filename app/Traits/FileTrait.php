<?php

namespace App\Traits;

use App\Models\Contract;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\TemplateProcessor;

trait FileTrait
{
    use CalculationTrait, OrderTrait;
    public function getOrderPurpose($request,$payments){
        $purpose = '';
        $amount = $request->amount;
        $penalty = (integer)$request->penalty;
        $has_last_payment = false;
        if($amount){
            if($penalty>=0){
                if($amount <= $penalty){
                    $purpose = 'տուգանք';
                }elseif($request->payments){
                    $amount -= $penalty;
                    $payments_count = 0;
                    foreach ($request -> payments as $item){
                        $paymentFinal = $item['final'];
                        if($amount >= $paymentFinal){
                            $amount -= $paymentFinal;
                            $payments_count++;
                            if($item['last_payment']){
                                $has_last_payment = true;
                            }
                        }else{
                            $amount = 0;
                        }
                    }
                    if($payments_count){
                        if($has_last_payment){
                            $purpose.= 'Վարկի մարում՝ տոկոսագւմար և մայր գումար, տուգանք';
                        }else{
                            $purpose.= 'հերթական '.$this->textValues[$payments_count].' ամսվա տոկոսագումար, տուգանք';
                        }
                    }else{
                        $purpose = 'տուգանք, տոկոսագումար';
                    }
                    if($amount && $amount >= 1000 && !$has_last_payment){
                        $purpose.= ', մասնակի մարում';
                    }
                }
            }elseif($request->payments){
                $payments_count = 0;
                foreach ($request -> payments as $item){
                    $paymentFinal = $item['final'];
                    if($amount >= $paymentFinal){
                        $amount -= $paymentFinal;
                        $payments_count++;
                        if($item['last_payment']){
                            $has_last_payment = true;
                        }
                    }else{
                        $amount = 0;
                    }
                }
                if($payments_count){
                    if($has_last_payment){
                        $purpose.= 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
                    }else{
                        $purpose.= 'հերթական '.$this->textValues[$payments_count].' ամսվա տոկոսագումար';
                    }
                }
                if($amount && $amount >= 1000 && !$has_last_payment){
                    $purpose.= ', մասնակի մարում';
                }
            }
        }else{
            $payments_count = 0;
            foreach ($request -> payments as $item){
                if($item['last_payment']){
                    $has_last_payment = true;
                }
                $payments_count++;
            }
            if($has_last_payment){
                $purpose.= 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
            }else{
                $purpose.= 'հերթական '.$this->textValues[$payments_count].' ամսվա տոկոսագումար';
            }
            if($penalty){
                $purpose.= ', տուգանք';
            }
        }
        return $purpose;
    }
    public function getOrderPurposeNew($request,$payments){
        $purpose = '';
        $amount = $request->amount;
        $penalty = (integer)$request->penalty;
        $has_last_payment = false;
        if($amount){
            if($penalty>=0){
                if($amount <= $penalty){
                    $purpose = 'տուգանք';
                }elseif($payments){
                    $amount -= $penalty;
                    $payments_count = 0;
                    foreach ($payments as $item){
                        $paymentFinal = $item['final'];
                        if($amount >= $paymentFinal){
                            $amount -= $paymentFinal;
                            $payments_count++;
                            if($item['last_payment']){
                                $has_last_payment = true;
                            }
                        }else{
                            $amount = 0;
                        }
                    }
                    if($payments_count){
                        if($has_last_payment){
                            $purpose.= 'Վարկի մարում՝ տոկոսագւմար և մայր գումար, տուգանք';
                        }else{
                            $purpose.= 'հերթական '.$this->textValues[$payments_count].' ամսվա տոկոսագումար, տուգանք';
                        }
                    }else{
                        $purpose = 'տուգանք, տոկոսագումար';
                    }
                    if($amount && $amount >= 1000 && !$has_last_payment){
                        $purpose.= ', մասնակի մարում';
                    }
                }
            }elseif($request->payments){
                $payments_count = 0;
                foreach ($request -> payments as $item){
                    $paymentFinal = $item['final'];
                    if($amount >= $paymentFinal){
                        $amount -= $paymentFinal;
                        $payments_count++;
                        if($item['last_payment']){
                            $has_last_payment = true;
                        }
                    }else{
                        $amount = 0;
                    }
                }
                if($payments_count){
                    if($has_last_payment){
                        $purpose.= 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
                    }else{
                        $purpose.= 'հերթական '.$this->textValues[$payments_count].' ամսվա տոկոսագումար';
                    }
                }
                if($amount && $amount >= 1000 && !$has_last_payment){
                    $purpose.= ', մասնակի մարում';
                }
            }
        }else{
            $payments_count = 0;
            foreach ($payments as $item){
                if($item['last_payment']){
                    $has_last_payment = true;
                }
                $payments_count++;
            }
            if($has_last_payment){
                $purpose.= 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
            }else{
                $purpose.= 'հերթական '.$this->textValues[$payments_count].' ամսվա տոկոսագումար';
            }
            if($penalty){
                $purpose.= ', տուգանք';
            }
        }
        return $purpose;
    }
    public function getOrderAmount($request){
        $order_amount = 0;
        $amount = $request->amount;
        $penalty = $request->penalty;
        if($amount){
            return $amount;
        }
        foreach ($request -> payments as $item){
            $order_amount += $item['final'];
        }
        if($penalty){
            $order_amount += $penalty;
        }
        return $order_amount;
    }

    public function getOrderAmountNew($request,$payments){
        $order_amount = 0;
        $amount = $request->amount;
        $penalty = $request->penalty;
        if($amount){
            return $amount;
        }
        foreach ($payments as $item){
            $order_amount += $item['final'];
        }
        if($penalty){
            $order_amount += $penalty;
        }
        return $order_amount;
    }
    public function generateOrderIn($request){
        $contract = Contract::where('id',$request->contract_id)->first();
        $client_name = $contract->name.' '.$contract->surname.' '.$contract->middle_name;
        $purpose = $this->getOrderPurpose($request);
        $amount = $this->getOrderAmount($request);
        $order_id = $this->getOrder($request->cash,'in');
        $res = [
            'contract_id' => $contract->id,
            'type' => 'in',
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $amount,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('d.m.Y'),
            'client_name' => $client_name,
            'purpose' => $purpose,
        ];
        $new_order = Order::create($res);
        return $new_order;
    }
    public function generateOrderInNew($request,$payments)
    {
        $contract = Contract::where('id',$request->contract_id)->first();
        $client_name = $contract->name.' '.$contract->surname.' '.$contract->middle_name;
        $purpose = $this->getOrderPurposeNew($request,$payments);
        $amount = $this->getOrderAmountNew($request,$payments);
        $order_id = $this->getOrder($request->cash,'in');
        $res = [
            'contract_id' => $contract->id,
            'type' => 'in',
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $amount,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('Y-m-d'),
            'client_name' => $client_name,
            'purpose' => $purpose,
        ];
        $new_order = Order::create($res);
        return $new_order;
    }

    public function generateOrder($contract,$amount, $purpose,$type,$cash){
        $client_name = $contract->client->name.' '.$contract->client->surname.' '.$contract->client->middle_name;
        $order_id = $this->getOrder($cash,$type);
        $res = [
            'contract_id' => $contract->id,
            'num' => $contract->num,
            'type' => $type,
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $amount,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('Y.m.d'),
            'client_name' => $client_name,
            'purpose' => $purpose,
        ];
        $new_order = Order::create($res);
        return $new_order;
    }

}
