<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEntry extends Model
{
    protected $fillable = [
        'payment_id',
        'contract_id',
        'deal_id',
        'pawnshop_id',
        'user_id',
        'reference',
        'amount',
        'principal_amount',
        'interest_amount',
        'penalty_amount',
        'balance_before',
        'balance_after',
        'document_number',
        'document_type',
        'date',
        'cash',
        'meta_data',
        'notes',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'interest_amount'  => 'decimal:2',
        'penalty_amount'   => 'decimal:2',
        'balance_before'   => 'decimal:2',
        'balance_after'    => 'decimal:2',
        'cash'             => 'boolean',
        'date'             => 'date:Y-m-d',
        'meta_data'        => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
