<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    use HasFactory;
    protected $fillable = [
        'type',
        'amount',
        'interest_amount',
        'order_id',
        'pawnshop_id',
        'contract_id',
        'cashbox',
        'bank_cashbox',
        'worth',
        'funds',
        'cash',
        'given',
        'insurance',
        'date',
        'delay_days',
        'purpose',
        'receiver',
        'source'
    ];

    public function order(){
        return $this->belongsTo(Order::class);
    }

    public function contract(){
        return $this->belongsTo(Contract::class);
    }
}
