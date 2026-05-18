<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Client;
class PostingRule extends Model
{
    protected $fillable = [
        'business_event_filter',
        'debit_account_id',
        'credit_account_id',
        'debit_partner',
        'credit_partner',
    ];
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function resolveDebitPartnerId($contract = null): ?int
    {
        return $this->resolvePartner($this->debit_partner, $contract);
    }

    public function resolveCreditPartnerId($contract = null): ?int
    {
        return $this->resolvePartner($this->credit_partner, $contract);
    }

    private function resolvePartner(?string $partner, $contract): ?int
    {
        if (!$partner) return null;
        if ($partner === 'client') return $contract?->client_id;
        return Client::where('company_name', $partner)->value('id');
    }


}
