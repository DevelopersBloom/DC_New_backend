<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ContractAmountHistory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contract_id',
        'amount_type',   // provided_amount, estimated_amount
        'amount',
        'type',          // 'in' or 'out'
        'category_id',
        'deal_id',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
