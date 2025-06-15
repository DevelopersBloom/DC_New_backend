<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory , SoftDeletes;

    protected $fillable = [
        'PGI_ID',
        'amount',
        'paid',
        'penalty',
        'type',
        'last_payment',
        'contract_id',
        'mother',
        'cash',
        'pawnshop_id',
        'date',
        'from_date',
        'days',
        'status',
        'name',
        'surname',
        'passport',
        'phone',
        'another_payer',
        'is_completed'
    ];

    public function contract(){
        return $this->belongsTo(Contract::class);
    }
    public function discount(){
        return $this->hasOne(Discount::class);
    }
}
