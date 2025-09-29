<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentJournal extends Model
{
    use HasFactory;
    protected $table = 'documents_journal';
    const REMINDER_ORDER_TYPE = 'Հիշարար օրդեր';
    const LOAN_NDM_TYPE = 'Ներգրավված Դրամական Միջոցներ';
    const LOAN_ATTRACTION = 'Վարկի ներգրավում';


    protected $fillable = [
        'date',
        'document_number',
        'document_type',
        'currency_id',
        'amount_amd',
        'amount_currency',
        'partner_id',
        'comment',
        'user_id',
        'journalable_type',
        'journalable_id',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function journalable(): MorphTo
    {
        return $this->morphTo();
    }
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'partner_id');
    }

}
