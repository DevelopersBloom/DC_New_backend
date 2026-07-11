<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Prepayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'payment_id',
        'deal_id',
        'amount',
        'principal_amount',
        'interest_amount',
        'due_date',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'due_date'         => 'date',
        'paid_at'          => 'datetime',
        'amount'           => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'interest_amount'  => 'decimal:2',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }
}
