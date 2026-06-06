<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'PGI_ID',
        'amount',
        'original_amount',
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
        'is_completed',
        'parent_id',
        'discount_amount',
        'principal_payment',
        'original_principal_payment',
        'interest_payment',
        'original_interest_payment',
        'remaining',
        'to_date',
        'effective_payment',
        'kasko_amount',
        'kasko_paid',
        'service_fee_payment'
    ];
    protected $casts = [
        'mother'             => 'decimal:2',
        'amount'             => 'decimal:2',
        'original_amount'    => 'decimal:2',
        'paid'               => 'decimal:2',
        'effective_payment'  => 'decimal:2',
        'principal_payment'  => 'decimal:2',
        'original_principal_payment'   => 'decimal:2',
        'interest_payment'   => 'decimal:2',
        'original_interest_payment'    => 'decimal:2',
        'remaining'          => 'decimal:2',
        'kasko_amount'       => 'decimal:2',
        'kasko_paid'         => 'boolean'
    ];

    public function contract(){
        return $this->belongsTo(Contract::class);
    }
    public function discount(){
        return $this->hasOne(Discount::class);
    }

    public function entries()
    {
        return $this->hasMany(PaymentEntry::class);
    }
}
