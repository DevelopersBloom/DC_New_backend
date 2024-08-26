<?php

namespace App\Http\Controllers;

use App\Events\Discuss;
use App\Exports\MonthlyExport;
use App\Exports\QuarterExport;
use App\Imports\ContractImport;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Payment;
use App\Traits\ContractTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\IOFactory;
use Illuminate\Http\Response;
class TestController extends Controller
{
    use ContractTrait;
//    private $textValues = [
//        1   => 'մեկ',
//        2   => 'երկու',
//        3   => 'երեք',
//        4   => 'չորս',
//        5   => 'հինգ',
//        6   => 'վեց',
//        7   => 'յոթ',
//        8   => 'ութ',
//        9   => 'ինը',
//        10  => 'տաս',
//        11  => 'տասնմեկ',
//        12  => 'տասներկու',
//        13  => 'տասներեք',
//        14  => 'տասնչորս',
//        15  => 'տասնհինգ',
//        16  => 'տասնվեց',
//        17  => 'տասնյոթ',
//        18  => 'տասնութ',
//        19  => 'տասնինը',
//        20  => 'քսան',
//        30  => 'երեսուն',
//        40  => 'քարասուն',
//        50  => 'հիսուն',
//        60  => 'վաթսուն',
//        70  => 'յոթանասուն',
//        80  => 'ութսուն',
//        90  => 'իննսուն',
//        100 => 'հարյուր'
//        ];
//
//    public function makeMoney($money, $with_sign = false): string
//    {
//        $string = strval($money);
//        if (!$string) {
//            $string = '0';
//        }
//        $result = '';
//        $check = true;
//        while ($check) {
//            if (strlen($string) > 3) {
//                $result = ',' . substr($string, -3) . $result;
//                $string = substr($string, 0, -3);
//            } else {
//                $result = $string . $result;
//                $check = false;
//            }
//        }
//        if (strlen($result) && $with_sign) {
//            $result .= '֏';
//        }
//        return $result;
//    }
//    public function numberToText($number){
//        $number = $number % 1000000;
//        $thousands = intval($number / 1000);
//        $text = '';
//        if($thousands){
//            if($thousands === 1){
//                $text = 'հազար ';
//            }else{
//                $text = $this->digit3($thousands).'հազար ';
//            }
//        }
//        $left = $number % 1000;
//        $text.= $this->digit3($left);
//        $arr = explode(' ',$text);
//        $arr[0] = mb_convert_case($arr[0], MB_CASE_TITLE, "UTF-8");
//        $text = implode(' ',$arr);
//        return $text;
//    }
//    public function digit3($number){
//        if(array_key_exists($number,$this->textValues)){
//            return $this->textValues[$number].' ';
//        }
//        $text = '';
//        $hundreds = intval($number / 100);
//        if($hundreds){
//            if($hundreds === 1){
//                $text = 'հարյուր ';
//            }else{
//                $text = $this->textValues[$hundreds].' '.'հարյուր ';
//            }
//        }
//        $left = $number % 100;
//        if(array_key_exists($left,$this->textValues)){
//            $text .= $this->textValues[$left].' ';
//        }else{
//            $tens = $left - $left%10;
//            $ones = $left%10;
//            if($tens){
//                $text .= $this->textValues[$tens];
//            }
//            if($ones){
//                $text .= $this->textValues[$ones].' ';
//            }
//        }
//        return $text;
//    }
//    public function test1(){
//        $date = Carbon::parse('2024-01-03');
//        $deals_out = Deal::where('date',$date->format('d.m.Y'))->whereIn('type',['regular_out','out'])->get();
//        $deals_in = Deal::where('date',$date->format('d.m.Y'))->where('type','in')->pluck('amount');
//        dump($deals_in);
//        dump($deals_out);
//    }
//    public function test123(){
//        $payments = Payment::where('status','completed')->where('date','03.01.2024')->sum('amount');
//        dd($payments);
//    }
    public function test(Request $request){
        return response()->json([
            'penalty_for_id_1' => $this->setContractPenalty(1)
        ]);
//        $a = intval(300000.97);
//        dd($a);
    }
//    public function test4444(){
//        $iitial_cashbox = 8852134;
//        $in = 0;
//        $out = 0;
//        $date = Carbon::parse('2024-01-01');
//        for($i = 0; $i < 31; $i ++){
//            $deals_out = Deal::where('date',$date->format('d.m.Y'))->whereIn('type',['regular_out','out'])->sum('amount');
//            $deals_in = Deal::where('date',$date->format('d.m.Y'))->where('type','in')->sum('amount');
//            $iitial_cashbox = $iitial_cashbox + $deals_in - $deals_out;
//            dump($date->format('d.m.Y'));
//            dump($iitial_cashbox);
//            $date->addDays();
//        }
//
////        foreach ($deals_in as $deal){
////            $in += $deal->amount;
////        }
////        foreach ($deals_out as $deal){
////            $out += $deal->amount;
////        }
//    }
//    public function testBefoeChange(){
//        $in = 0;
//        $out = 0;
//        $date = Carbon::parse('2024-01-16');
//        $completed_payments = Payment::where('date','16.01.2024')->where('status','completed')->get();
//        $completed_payments1 = Payment::where('date','16.01.2024')->where('status','completed');
//        $contracts = Contract::where('date','16.01.2024')->get();
//        $deals_out = Deal::where('date','16.01.2024')->where('type','regular_out')->get();
//        $deals_in = Deal::where('date','16.01.2024')->where('type','in')->get();
//        foreach ($completed_payments as $payment){
//            $in += $payment->amount + $payment->mother;
//        }
//        foreach ($contracts as $contract){
//            $out += $contract->given;
//            if($contract->one_time_payment){
//                $in += $contract->one_time_payment;
//            }else{
//                if($contract->given>= 400000){
//                    $in += intval(round($contract->given * 0.01 * 2 /10) * 10);
//                }else{
//                    $in += intval(round($contract->given * 0.01 * 2.5 /10) * 10);
//                }
//            }
//        }
//        foreach ($deals_in as $deal){
//            $in += $deal->amount;
//        }
//        foreach ($deals_out as $deal){
//            $out += $deal->amount;
//        }
//        dump('$deals_in amount');
//        dump($deals_in->pluck('amount'));
//        dump('$deals_out  amount');
//        dump($deals_out->pluck('amount'));
//        dump('$contracts given');
//        dump($contracts->pluck('given'));
//        dump('$in');
//        dump($in);
//        dump('$completed_payments');
//        dump($completed_payments1->get()->groupBy(function ($payment){
//            return $payment->contract->ADB_ID;
//        }));
//        dump('$out');
//        dump($out);
//        dump('$in - $out');
//        dump($in - $out);
//        dump(7778802 + $in - $out);
//        dump(round(375 / 10) * 10);
//    }
//    public function testfffff(){
//        $date = Carbon::parse('2010-01-01');
//        $in = Deal::whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [$date])->where('type','in')->sum('amount');
//        $out = Deal::whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [$date])->where('type','out')->sum('amount');
//        $regular_out = Deal::whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [$date])->where('type','regular_out')->sum('amount');
//        dump('in');
//        dump(intval(round($in/1000)));
//        dump('out');
//        dump(intval(round($out/1000)));
//        dump('regular_out');
//        dump(intval(round($regular_out/1000)));
//        dump('26500000 - $regular_out + $in - $out');
//        dump(intval(round((26500000 - $regular_out + $in - $out)/1000)));
//        dump('funds');
//        dump(26500);
//        dump('res');
//        dump(intval(round(4825548/1000)));
//
//    }
//    public function test10(){
//        $cashbox = 4825548;
//        $in_sum = Deal::where('type','in')->sum('amount');
//        $out_sum = Deal::where('type','out')->sum('amount');
//        $initial_cashbox = $cashbox - $in_sum + $out_sum;
//        $deals = Deal::orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') ASC")->orderBy('id','ASC')->get();
//        foreach ($deals as $deal){
//            if($deal->type === 'in'){
//                $initial_cashbox = $initial_cashbox + $deal->amount;
//                $deal->cashbox = $initial_cashbox;
//            }elseif ($deal->type === 'out'){
//                $initial_cashbox = $initial_cashbox - $deal->amount;
//                $deal->cashbox = $initial_cashbox;
//            }
//            $deal->save();
//        }
//        dd(123);
//    }
//    public function test3(){
//        $needle = 'Իրացված է';
//        $str = 'Իրացված է';
//        dd(strpos($str,'Իրացվadwած է'));
//    }


}
