<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LumpRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'min_amount',
        'max_amount',
        'lump_rate',
    ];


    public static function getRateByCategoryAndAmount(float $amount)
    {
        return self::where('min_amount','<=',$amount)
            ->where('max_amount','>=',$amount)
            ->first();
    }
}
