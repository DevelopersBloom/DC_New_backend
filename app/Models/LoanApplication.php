<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\LoanApplicationDocument;

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
        'client_age',
        'client_income',
        'pawnshop_id',
        'created_by',
        'final_estimate_id',
        'final_estimated_amount',
        'provided_amount',
        'provided_currency_id',
        'provided_note',
        'is_employed',
        'employer_name',
        'employer_position',
        'employment_tenure_months',
        'employment_salary',
        'owns_property',
        'owned_property_info',
        'loan_purpose',
        'requested_amount',
        'acra_score',
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
        'final_estimated_amount' => 'decimal:2',
        'client_income'         => 'decimal:2',
        'employment_salary'     => 'decimal:2',
        'requested_amount'      => 'decimal:2',
        'is_employed'           => 'boolean',
        'owns_property'         => 'boolean',
    ];

    protected $appends = ['estimates_average'];

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

    /** Mean of the participants' estimates; shown to the admin as a hint, never applied automatically. */
    public function getEstimatesAverageAttribute(): ?float
    {
        if (!$this->relationLoaded('estimates')) {
            return null;
        }

        return $this->estimates->isEmpty()
            ? null
            : round((float) $this->estimates->avg('estimated_amount'), 2);
    }

    /** The final collateral amount: the admin's figure, or the picked estimate on legacy rows. */
    public function finalEstimateAmount(): ?float
    {
        $amount = $this->final_estimated_amount ?? $this->finalEstimate?->estimated_amount;

        return $amount === null ? null : (float) $amount;
    }

    /** The collateral estimate is locked once the admin has finalized it. */
    public function isEstimateFinalized(): bool
    {
        return $this->final_estimate_id !== null
            || $this->final_estimated_amount !== null
            || $this->estimate_finalized_at !== null;
    }

    public function hasAcraConsent(): bool
    {
        return $this->files()->where('doc_type', LoanApplicationDocument::ACRA_CONSENT)->exists();
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
