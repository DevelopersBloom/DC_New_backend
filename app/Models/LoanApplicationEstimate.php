<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApplicationEstimate extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_application_id',
        'user_id',
        'estimated_amount',
        'currency_id',
        'note',
        'history',
    ];

    protected $casts = [
        'estimated_amount' => 'decimal:2',
        'history'          => 'array',
    ];

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
