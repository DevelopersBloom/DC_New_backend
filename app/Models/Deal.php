<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    use HasFactory;
    const HISTORY =  'history';
    const IN_DEAL = 'in';
    const OUT_DEAL = 'out';
    const EXPENSE_DEAL = 'expense';

    protected $fillable = [
        'type',
        'amount',
        'penalty',
        'discount',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
