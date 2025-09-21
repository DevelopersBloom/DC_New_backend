<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanNdm extends Model
{
    use HasFactory;

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
}
