<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractReserveHistory extends Model
{
    use HasFactory;

    protected $table = 'contract_reserve_histories';

    protected $fillable = [
        'client_id',
        'classification_id',
        'contract_id',
        'risk_weight',
        'reserve_percent',
        'reserve_amount',
        'total_reserve_amount',
        'provided_amount',
        'date',
        'user_id',
        'meta',
    ];

    protected $casts = [
        'risk_percent' => 'decimal:2',
        'reserve_percent' => 'decimal:2',
        'reserve_amount' => 'decimal:10',
        'date' => 'date',
        'meta' => 'array',
    ];


    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(ClientClassification::class, 'classification_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
