<?php

namespace App\Models;

use App\Traits\Journalable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReminderOrder extends Model
{
    use HasFactory,Journalable;

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
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }
    public function toJournalRow(): array
    {
        return [
            'date'             => optional($this->order_date)->format('Y-m-d'),
            'document_number'  => (string) $this->num,
            'document_type'    => 'Հիշարար Օրդեր',

            'currency_id'      => $this->currency_id,
            'amount_amd'       => $this->amount ?? 0,

            'partner_id'       => $this->debit_partner_id,

            'comment'          => $this->comment,
            'user_id'          => $this->user_id ?? auth()->id(),
        ];
    }
    public function applyJournalUpdate(\App\Models\DocumentJournal $journal, array $data): void
    {
        $map = [
            'date'            => 'order_date',
            'document_number' => 'num',
            'currency_id'     => 'currency_id',
            'amount_amd'      => 'amount',
            'partner_id'      => 'debit_partner_id',
            'comment'         => 'comment',
        ];
        foreach ($map as $jKey => $mKey) {
            if (array_key_exists($jKey, $data)) {
                $this->{$mKey} = $data[$jKey];
            }
        }
        $this->save();
    }
}
