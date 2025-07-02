<?php

namespace App\Traits;

trait CalculationTrait
{
    public $textValues = [
        1   => 'մեկ',
        2   => 'երկու',
        3   => 'երեք',
        4   => 'չորս',
        5   => 'հինգ',
        6   => 'վեց',
        7   => 'յոթ',
        8   => 'ութ',
        9   => 'ինը',
        10  => 'տաս',
        11  => 'տասնմեկ',
        12  => 'տասներկու',
        13  => 'տասներեք',
        14  => 'տասնչորս',
        15  => 'տասնհինգ',
        16  => 'տասնվեց',
        17  => 'տասնյոթ',
        18  => 'տասնութ',
        19  => 'տասնինը',
        20  => 'քսան',
        30  => 'երեսուն',
        40  => 'քարասուն',
        50  => 'հիսուն',
        60  => 'վաթսուն',
        70  => 'յոթանասուն',
        80  => 'ութսուն',
        90  => 'իննսուն',
        100 => 'հարյուր'
    ];
    public function makeMoney($money, $with_sign = false): string
    {
        $string = strval($money);
        if (!$string) {
            $string = '0';
        }
        $result = '';
        $check = true;
        while ($check) {
            if (strlen($string) > 3) {
                $result = '.' . substr($string, -3) . $result;
                $string = substr($string, 0, -3);
            } else {
                $result = $string . $result;
                $check = false;
            }
        }
        if (strlen($result) && $with_sign) {
            $result .= '֏';
        }
        return $result;
    }
    // public function numberToText($number){
    //     $number = $number % 1000000;
    //     $thousands = intval($number / 1000);
    //     $text = '';
    //     if($thousands){
    //         if($thousands === 1){
    //             $text = 'հազար ';
    //         }else{
    //             $text = $this->digit3($thousands).'հազար ';
    //         }
    //     }
    //     $left = $number % 1000;
    //     $text.= $this->digit3($left);
    //     $arr = explode(' ',$text);
    //     $arr[0] = mb_convert_case($arr[0], MB_CASE_TITLE, "UTF-8");
    //     $text = implode(' ',$arr);
    //     return $text;
    // }
    public function numberToText($number)
{
    $text = '';

    $millions = intval($number / 1000000);
    if ($millions) {
        if ($millions === 1) {
            $text .= 'մեկ միլիոն ';
        } else {
            $text .= $this->digit3($millions) . 'միլիոն ';
        }
    }

    $number = $number % 1000000;
    $thousands = intval($number / 1000);
    if ($thousands) {
        if ($thousands === 1) {
            $text .= 'հազար ';
        } else {
            $text .= $this->digit3($thousands) . 'հազար ';
        }
    }

    $left = $number % 1000;
    $text .= $this->digit3($left);

    $arr = explode(' ', trim($text));
    if (isset($arr[0])) {
        $arr[0] = mb_convert_case($arr[0], MB_CASE_TITLE, "UTF-8");
    }
    $text = implode(' ', $arr);

    return $text;
}

    public function digit3($number){
        if(array_key_exists($number,$this->textValues)){
            return $this->textValues[$number].' ';
        }
        $text = '';
        $hundreds = intval($number / 100);
        if($hundreds){
            if($hundreds === 1){
                $text = 'հարյուր ';
            }else{
                $text = $this->textValues[$hundreds].' '.'հարյուր ';
            }
        }
        $left = $number % 100;
        if(array_key_exists($left,$this->textValues)){
            $text .= $this->textValues[$left].' ';
        }else{
            $tens = $left - $left%10;
            $ones = $left%10;
            if($tens){
                $text .= $this->textValues[$tens];
            }
            if($ones){
                $text .= $this->textValues[$ones].' ';
            }
        }
        return $text;
    }
}
