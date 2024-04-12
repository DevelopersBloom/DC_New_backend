<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Item;
use App\Models\Order;
use App\Models\Payment;
use App\Models\History;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ContractSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        for($p = 1; $p < 3; $p++){
            for($i = 0; $i < 50; $i++){
                $name = 'Nm_Psh'.$p.'_'.$i;
                $surname = 'Snm_Psh'.$p.'_'.$i;
                $middle_name = 'Md_Psh'.$p.'_'.$i;
                $passport = 'AN'.rand(1000,9999).'NB';
                $address = 'ք.Երևան '.Str::random(rand(10,13)).' '.rand(10,60).'/'.rand(1,4);
                $phone1 = '077 '.rand(100000,999999);
                $phone2 = '098 '.rand(100000,999999);
                $email = Str::random(rand(6,10)).'@gmail.com';
                $comment = Str::random(rand(6,10));
                $dob = Carbon::parse(date('d.m.Y',mt_rand(Carbon::parse('1960-01-01')->timestamp, Carbon::parse('2000-11-01')->timestamp)))->format('d.m.Y');
                $passport_given = rand(1,10);
                if($passport_given === 10){
                    $passport_given = '0'.$passport_given;
                }else{
                    $passport_given = '00'.$passport_given;
                }
                $bank = 'Ամերիաբանկ';
                $card = '';
                for($card_i = 0;$card_i < 16 ;$card_i++){
                    $card.= rand(0,9);
                    if($card_i%4 === 3){
                        $card.= ' ';
                    }
                }
                $client = Client::create([
                    'name'      => $name,
                    'surname'   => $surname,
                    'middle_name' => $middle_name,
                    'passport'  => $passport,
                    'address'   => $address,
                    'pawnshop_id' => $p,
                    'phone1'     => $phone1,
                    'phone2'     => $phone2,
                    'email'     => $email,
                    'bank'     => $bank,
                    'card'     => $card,
                    'comment'   => $comment,
                    'dob'   => $dob,
                    'passport_given'   => $passport_given,
                ]);
                $contracts_count = rand(2,5);
                $contracts_count = 10;
                for($k = 0; $k < $contracts_count; $k++){
                    $given = rand(30,500) * 1000;
                    $one_time_payment = $given>=400000 ? $given * 0.01 * 2 : $given * 0.01 * 2.5;
                    $date = Carbon::parse(date('d.m.Y',mt_rand(Carbon::parse('2023-11-01')->timestamp, Carbon::parse('2024-02-01')->timestamp)));
                    $deadline = clone $date;
                    $deadline = $deadline->addMonths(rand(4,5))->format('d.m.Y');
                    $user_id = rand(1,4);
                    $status_rand = rand(1,10);
                    $status = 'initial';
                    if($status_rand > 8){
                        $status = 'executed';
                    }elseif ($status_rand > 6){
                        $status = 'completed';
                    }
                    $close_date = null;
                    if($status === 'executed' || $status === 'completed'){
                        $close_date = $deadline;
                    }
                    $payment_status = $status === 'initial' || $status === 'completed' ? $status : 'initial';
                    $executed = $status === 'executed' ? rand(100,150) * 1000 : null;
                    $category_id = rand(1,8);
                    $info = 'ծնվ. '.$client->dob.'թ., '.$client->passport.' տրվ. '.$client->passport_given;
                    $contract = Contract::create([
                        'name'      => $client -> name,
                        'surname'   => $client -> surname,
                        'middle_name'   => $client -> middle_name,
                        'passport'  => $client -> passport,
                        'info' => $info,
                        'address'   => $client -> address,
                        'phone1'     => $client -> phone1,
                        'phone2'     => $client -> phone2,
                        'email'     => $client -> email,
                        'bank'     => $client -> bank,
                        'card'     => $client -> card,
                        'comment'   => $client -> comment,
                        'client_id' => $client -> id,
                        'dob' => $client -> dob,
                        'passport_given' => $client -> passport_given,
                        'user_id' => $user_id,
                        'worth'  => $given + rand(0,100) * 1000,
                        'given'  => $given,
                        'left'  => $given,
                        'executed' => $executed,
                        'rate'  => 0.4,
                        'penalty'  => 0.13,
                        'one_time_payment'  => $one_time_payment,
                        'deadline'  => $deadline,
                        'date'  => $date->format('d.m.Y'),
                        'close_date' => $close_date,
                        'category_id'  => $category_id,
                        'evaluator_id'  => rand(1,4),
                        'pawnshop_id'  => $p,
                        'status' => $status
                    ]);
                    $items_count = rand(1,3);
                    for($item_i = 0; $item_i < $items_count; $item_i++){
                        $item = [
                            'contract_id' => $contract->id,
                            'category_id' => $category_id
                        ];
                        if($category_id === 1){
                            $item['weight'] = rand(100,200);
                            $item['clear_weight'] = rand(100,200);
                            $item['type'] = rand(100,200);
                        }
                        Item::create($item);
                    }

                    $client_name = $contract->name.' '.$contract->surname.' '.$contract->middle_name;
                    $res = [
                        'contract_id' => $contract->id,
                        'type' => 'out',
                        'title' => 'Օրդեր',
                        'pawnshop_id' => $p,
                        'order' => 1,
                        'amount' => $contract->given,
                        'rep_id' => '2211',
                        'date' => Carbon::parse($contract->date)->format('d.m.Y'),
                        'client_name' => $client_name,
                        'purpose' => 'վարկ',
                    ];
                    $last_order = Order::where('pawnshop_id',$contract->pawnshop_id)->orderBy('id','desc')->first();
                    if($last_order){
                        $res['order'] = $last_order->order + 1;
                    }
                    $new_order = Order::create($res);
                    Deal::create([
                        'type' => 'out',
                        'amount' => $given,
                        'order_id' => $new_order->id,
                        'pawnshop_id' => $p,
                        'contract_id' => $contract->id,
                        'cashbox' => rand(4000,7000) * 1000,
                        'bank_cashbox' => rand(3000,4000) * 1000,
                        'insurance' => 60000000,
                        'date' => $contract->date,
                        'worth' => 31320000,
                        'given' => 31320000,
                    ]);
                    History::create([
                        'type_id' => 1,
                        'user_id' => $user_id,
                        'order_id' => $new_order->id,
                        'contract_id' => $contract->id,
                        'date' => $date->format('d.m.Y'),
                        'amount' => $given
                    ]);
                    $res = [
                        'contract_id' => $contract->id,
                        'type' => 'in',
                        'title' => 'Օրդեր',
                        'pawnshop_id' => $p,
                        'order' => 1,
                        'amount' => $contract->one_time_payment,
                        'rep_id' => '2211',
                        'date' => Carbon::parse($contract->date)->format('d.m.Y'),
                        'client_name' => $client_name,
                        'purpose' => 'Մինավագ վճար',
                    ];
                    $last_order = Order::where('pawnshop_id',$contract->pawnshop_id)->orderBy('id','desc')->first();
                    if($last_order){
                        $res['order'] = $last_order->order + 1;
                    }
                    $new_order = Order::create($res);
                    History::create([
                        'type_id' => 2,
                        'user_id' => $user_id,
                        'order_id' => $new_order->id,
                        'contract_id' => $contract->id,
                        'date' => $date->format('d.m.Y'),
                        'amount' => $contract->one_time_payment
                    ]);
                    $toDate = Carbon::parse($deadline)->setTimezone('Asia/Yerevan')->format('d.m.Y');
                    $fromDate = Carbon::parse($date)->setTimezone('Asia/Yerevan')->format('d.m.Y');
                    $toDate = Carbon::parse($toDate);
                    $fromDate = Carbon::parse($fromDate);


                    $months = $toDate->diffInMonths($fromDate);
                    $dateToCalc = $fromDate;

                    for($j = 0; $j < $months; $j++){
                        $payment = [];
                        $paymentDate = clone $dateToCalc;
                        $paymentDate->addMonth();
                        $diffFays = $paymentDate -> diffInDays($dateToCalc);
                        $amount = $given * 0.4 * 0.01 * $diffFays;
                        $mother = 0;
                        if($j === $months - 1){
                            $mother = $given;
                            Payment::create([
                                'contract_id' => $contract->id,
                                'date' => $paymentDate->format('d.m.Y'),
                                'amount' => $amount,
                                'pawnshop_id'  => $p,
                                'from_date' => $dateToCalc->format('d.m.Y'),
                                'status' => $payment_status,
                                'last_payment' => true,
                                'mother' => $mother,
                            ]);
                        }else{
                            Payment::create([
                                'contract_id' => $contract->id,
                                'date' => $paymentDate->format('d.m.Y'),
                                'from_date' => $dateToCalc->format('d.m.Y'),
                                'amount' => $amount,
                                'status' => $payment_status,
                                'pawnshop_id'  => $p,
                                'mother' => $mother,
                            ]);
                        }

                        $dateToCalc = $paymentDate;
                    }
                }
            }
        }

    }
}
