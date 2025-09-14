<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_date',
        'amount',
        'currency_id',
        'comment',
        'debit_account_id',
        'debit_partner_id',
        'credit_account_id',
        'credit_partner_id',
        'is_draft',
        'num',
    ];

    protected $casts = [
        'order_date' => 'date',
        'amount' => 'decimal:2',
        'is_draft' => 'boolean',
    ];
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }
    public function debitPartner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'debit_partner_id');
    }

    public function creditPartner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'credit_partner_id');
    }
}
