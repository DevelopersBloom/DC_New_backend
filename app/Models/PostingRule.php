<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Client;
class PostingRule extends Model
{
    use HasFactory;
    protected $fillable = [
        'business_event_filter',
        'debit_account_id',
        'credit_account_id',
        'debit_partner_id',
        'credit_partner_id',
    ];

//    public function businessEvent(): BelongsTo
//    {
//        return $this->belongsTo(BusinessEvent::class);
//    }

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
