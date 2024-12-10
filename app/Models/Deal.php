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
        'client_id',
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
        'source',
        'created_by',
        'updated_by',
        'filter_type'
    ];
    protected $casts = [
        'cash' => 'boolean'
    ];
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
