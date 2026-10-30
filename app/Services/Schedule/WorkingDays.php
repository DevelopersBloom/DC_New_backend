<?php

namespace App\Services\Schedule;

use Carbon\Carbon;

/**
 * Single source of the working-day rule used for every schedule due date:
 * weekends and the fixed public holidays below move the date to the next day.
 */
class WorkingDays
{
    public const HOLIDAYS = [
        '01-01', // Ամանոր
        '01-02', // Ամանոր
        '01-06', // Սուրբ Ծնունդ
        '01-27', // Հիշատակի օր
        '01-28', // Բանակի օր
        '03-08', // Կանանց տոն
        '04-24', // Ցեղասպանության զոհերի հիշատակի օր
        '05-01', // Աշխատանքի օր
        '05-09', // Հաղթանակի և խաղաղության տոն
        '05-28', // Հանրապետության տոն
        '07-05', // Սահմանադրության օր
        '09-21', // Անկախության տոն
        '12-31', // Ամանոր
    ];

    /** Moves $date (in place, like the original helper) to the next working day. */
    public static function next(Carbon $date): Carbon
    {
        while (in_array($date->format('m-d'), self::HOLIDAYS, true) || $date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }
}
