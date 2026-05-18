<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriodLock extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_date',
        'to_date',
        'is_closed',
        'reason',
        'closed_by',
    ];

    protected $casts = [
        'from_date' => 'date:Y-m-d',
        'to_date' => 'date:Y-m-d',
        'is_closed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
