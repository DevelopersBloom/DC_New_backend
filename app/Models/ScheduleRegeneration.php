<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleRegeneration extends Model
{
    protected $fillable = [
        'contract_id', 'user_id', 'effective_date', 'current_payment_id', 'anchor_day', 'reason',
        'balance', 'old_total_interest', 'new_total_interest', 'old_monthly', 'new_monthly',
        'old_last_date', 'new_last_date', 'removed_payment_ids', 'created_payment_ids',
        'moved_entries', 'current_row_before', 'old_payment_schedule', 'reverted_at', 'reverted_by',
    ];

    protected $casts = [
        'effective_date'       => 'date:Y-m-d',
        'old_last_date'        => 'date:Y-m-d',
        'new_last_date'        => 'date:Y-m-d',
        'removed_payment_ids'  => 'array',
        'created_payment_ids'  => 'array',
        'moved_entries'        => 'array',
        'current_row_before'   => 'array',
        'old_payment_schedule' => 'array',
        'reverted_at'          => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->reverted_at === null;
    }
}
