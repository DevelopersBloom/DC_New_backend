<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Journalable;

class LoanNdm extends Model
{
    use HasFactory;

    use Journalable;

    protected $table = 'loan_ndm';

    protected $fillable = [
        'contract_number',
        'client_id',
        'name',
        'currency_id',
        'account_id',
        'interest_account_id',
        'amount',
        'calculate_first_day',
        'contract_date',
        'calc_date',
        'disbursement_date',
        'maturity_date',
        'comment',
        'pawnshop_id',
        'interest_schedule_mode',
        'repayment_start_date',
        'repayment_end_date',
        'day_count_convention',
        'interest_rate',
        'interest_amount',
        'tax_rate',
        'effective_interest_rate',
        'actual_interest_rate',
        'effective_interest_amount',
        'calculate_effective_amount',
        'interest_day_of_month',
        'interest_periodicity_months',
        'interest_last_date',
        'classification_type',
        'notes',
        'income',
        'access_type',
        'department',
        'user_id',
    ];

    protected $casts = [
        'calculate_first_day' => 'boolean',
        'calculate_effective_amount' => 'boolean',
        'contract_date' => 'date',
        'calc_date' => 'date',
        'disbursement_date' => 'date',
        'maturity_date' => 'date',
        'repayment_start_date' => 'date',
        'repayment_end_date' => 'date',
        'interest_last_date' => 'date',
        'amount' => 'decimal:2',
        'income' => 'decimal:2',
        'interest_rate' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'effective_interest_rate' => 'decimal:4',
        'actual_interest_rate' => 'decimal:6',
        'effective_interest_amount' => 'decimal:2',
    ];


    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function interestAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_account_id');
    }

    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function journals()
    {
        return $this->morphMany(DocumentJournal::class, 'journalable');
    }

    public function toJournalRow(): array
    {
        $client = $this->client;

        $partnerName = $client
            ? ($client->type === 'legal'
                ? ($client->company_name ?? '')
                : trim(($client->name ?? '').' '.($client->surname ?? '')))
            : null;

        $partnerCode = $client
            ? ($client->type === 'individual'
                ? ($client->social_card_number ?? null)
                : ($client->tax_number ?? null))
            : null;
        return [
            'date'             => optional($this->contract_date)->format('Y-m-d'),
            'document_number'  => $this->contract_number,
            'document_type'    => 'Ներգրավված Դրամական Միջոցներ',

            'currency_id'      => $this->currency_id,
            'amount_amd'       => $this->amount ?? 0,
            'amount_currency'  => $this->amount_currency,

            'partner_id'      => $this->client_id,

            'comment'          => $this->comment,
            'user_id'          => auth()->user()->id,
        ];
    }
}
