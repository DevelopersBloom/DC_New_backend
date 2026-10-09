<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanApplication extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_COLLATERAL_REVIEW = 'collateral_review';
    public const STATUS_LOAN_REVIEW = 'loan_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'client_id',
        'loan_type',
        'status',
        'comments',
        'pawnshop_id',
        'created_by',
        'final_estimate_id',
        'provided_amount',
        'provided_currency_id',
        'provided_note',
        'estimate_finalized_at',
        'estimate_finalized_by',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'contract_id',
    ];

    protected $casts = [
        'approved_at'           => 'datetime',
        'estimate_finalized_at' => 'datetime',
        'provided_amount'       => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function finalEstimate(): BelongsTo
    {
        return $this->belongsTo(LoanApplicationEstimate::class, 'final_estimate_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanApplicationItem::class);
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(LoanApplicationEstimate::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function providedCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'provided_currency_id');
    }

    /** The collateral estimate is locked once the final estimate has been chosen. */
    public function isEstimateFinalized(): bool
    {
        return $this->final_estimate_id !== null;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CONVERTED,
        ], true);
    }
}
