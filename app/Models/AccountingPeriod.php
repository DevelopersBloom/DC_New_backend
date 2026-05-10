<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    protected $fillable = ['year', 'month', 'closed_at', 'closed_by'];

    protected $casts = [
        'closed_at' => 'datetime',
        'year'      => 'integer',
        'month'     => 'integer',
    ];

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public static function isClosed(string $date): bool
    {
        $d = Carbon::parse($date);

        return static::where('year', $d->year)
            ->where('month', $d->month)
            ->whereNotNull('closed_at')
            ->exists();
    }
}
