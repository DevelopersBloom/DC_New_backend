<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory,SoftDeletes;
    const REMINDER_ORDER_TYPE = 'Հիշարար օրդեր';
    const LOAN_NDM_TYPE = 'Ներգրավված Դրամական Միջոցներ';
    protected $fillable = [
        'date',
        'document_number',
        'document_type',

        'debit_account_id',
        'debit_partner_code',
        'debit_partner_name',
        'debit_currency_id',
        'debit_partner_id',

        'credit_account_id',
        'credit_partner_code',
        'credit_partner_name',
        'credit_currency_id',
        'credit_partner_id',

        'amount_amd',
        'amount_currency',
        'amount_currency_id',

        'comment',
        'user_id',
        'is_system',
        'disbursement_date',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_system'  => 'boolean',
        'amount_amd' => 'decimal:2',
        'amount_currency' => 'decimal:2',
    ];


    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }


    public function debitCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'debit_currency_id');
    }

    public function creditCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'credit_currency_id');
    }

    public function amountCurrencyRelation(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'amount_currency_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    public function scopeByDocument($query, string $type = null, string $number = null)
    {
        if ($type)   $query->where('document_type', $type);
        if ($number) $query->where('document_number', $number);
        return $query;
    }

    public function scopeBetweenDates($query, $from = null, $to = null)
    {
        if ($from && $to) {
            return $query->whereBetween('date', [$from, $to]);
        }
        if ($from) return $query->where('date', '>=', $from);
        if ($to)   return $query->where('date', '<=', $to);
        return $query;
    }
    public function scopeUpToDate(Builder $q,$date): Builder
    {
        return $q->whereDate('date','<=',Carbon::parse($date)->toDateString());
    }
    public function debitPartner()
    {
        return $this->belongsTo(Client::class, 'debit_partner_id');
    }

    public function creditPartner()
    {
        return $this->belongsTo(Client::class, 'credit_partner_id');
    }
    public function getDebitPartnerCodeAttribute()
    {
        $p = $this->debitPartner;
        if (!$p) return null;

        return $p->type === 'individual'
            ? ($p->social_card_number ?? null)
            : ($p->tax_number ?? null);
    }

    public function getDebitPartnerNameAttribute()
    {
        $p = $this->debitPartner;
        if (!$p) return null;

        return $p->type === 'legal'
            ? ($p->company_name ?? '')
            : trim(($p->name ?? '') . ' ' . ($p->surname ?? ''));
    }

    public function getCreditPartnerCodeAttribute()
    {
        $p = $this->creditPartner;
        if (!$p) return null;

        return $p->type === 'individual'
            ? ($p->social_card_number ?? null)
            : ($p->tax_number ?? null);
    }

    public function getCreditPartnerNameAttribute()
    {
        $p = $this->creditPartner;
        if (!$p) return null;

        return $p->type === 'legal'
            ? ($p->company_name ?? '')
            : trim(($p->name ?? '') . ' ' . ($p->surname ?? ''));
    }
}
