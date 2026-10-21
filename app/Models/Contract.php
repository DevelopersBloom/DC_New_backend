<?php

namespace App\Models;

use App\Traits\ContractTrait;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
class Contract extends Model
{
    use HasFactory;
    use SoftDeletes;
    use ContractTrait;


    public array $historyContext = [];

    protected static function boot(): void
    {
        parent::boot();

        static::updated(function (Contract $contract) {
            $trackedFields = ['provided_amount', 'estimated_amount'];

            foreach ($trackedFields as $field) {
                $changes = $contract->getChanges();
                if (!array_key_exists($field, $changes)) {
                    continue;
                }

                $original = (float) $contract->getOriginal($field);
                $new      = (float) $contract->$field;

                if ($original == $new) {
                    continue;
                }

                ContractAmountHistory::create([
                    'contract_id' => $contract->id,
                    'amount'      => abs($new - $original),
                    'amount_type' => $field,
                    'type'        => $new < $original ? 'out' : 'in',
                    'date'        => $contract->historyContext['date'] ?? now()->toDateString(),
                    'deal_id'     => $contract->historyContext['deal_id'] ?? null,
                    'category_id' => $contract->category_id,
                    'pawnshop_id' => $contract->historyContext['pawnshop_id']
                                     ?? (auth()->user()->pawnshop_id ?? 1),
                ]);
            }
        });
    }

    const CONTRACT_OPENING = 'Պայմանագրի բացում';
    const LUMP_PAYMENT = 'Միանվագ վճար';
    const MOTHER_AMOUNT_PAYMENT = 'ՄԳ տրամադրում';
    const REGULAR_PAYMENT = 'Հերթական վճարում';
    const FULL_PAYMENT = 'Ամբողջական վճարում';
    const PARTIAL_PAYMENT ='Մասնակի վճարում';
    const PENALTY = 'Տուգանք';
    const STATUS_INITIAL = 'initial';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXECUTED = 'executed';
    const STATUS_TAKEN  = 'taken';
    const STATUS_CANCELLED = 'cancelled';

    /** Reporting codes for cancelling a contract that was never disbursed. */
    const CANCEL_REASON_CODES = ['client_declined', 'no_funds', 'collateral_issue', 'wrong_data', 'other'];
//    protected $appends = ['is_overdue','not_payed_amount'];
    protected $appends = ['is_overdue'];

    protected $fillable=[
        'collected',
        'rate',
        'penalty',
        'penalty_amount',
        'one_time_payment',
        'executed',
        'deadline',
        'deadline_days',
        'status',
        'date',
        'close_date',
        'pawnshop_id',
        'category_id',
        'evaluator_id',
        'client_id',
        'user_id',
        'dob',
        'info',
        'extended',
        'passport_given',
        'category_id',
        'closed_at',
        'estimated_amount',
        'provided_amount',
        'contract_amount',
        'interest_rate',
        'effective_rate',
        'num',
        'lump_rate',
        'closed_at',
        'mother',
        'left',
        'description',
        'payment_schedule',
        'payment_type',
        'effective_annual_rate',
        'effective_daily_rate',
        'kasko_amount',
        'effective_rate_kasko',
        'effective_rate_annual_kasko',
        'fee_annual_rate',
        'seller_id',
        'pledgee_id',
        'currency_id',
        'contract_kind',
        'loan_type',
        'interest_rate_type',
        'security_type',
        'loan_use_field',
        'loan_use_purpose',
        'contract_modified_amount',
        'provision_of_credit',
        'payment_day',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason_code',
        'cancel_reason',
    ];

    protected $casts = [
        'payment_schedule' => 'array',
        'estimated_amount'      => 'decimal:2',
        'provided_amount'       => 'decimal:2',
        'contract_amount'       => 'decimal:2',
        'contract_modified_amount' => 'decimal:2',
        'provision_of_credit'   => 'boolean',
        'interest_rate'         => 'decimal:4',
        'effective_rate'        => 'decimal:4',
        'effective_annual_rate' => 'decimal:10',
        'effective_daily_rate'  => 'decimal:10',
        'penalty'               => 'decimal:2',
        'discount'              => 'decimal:2',
        'lump_rate'             => 'decimal:4',
        'mother'                => 'decimal:2',
        'left'                  => 'decimal:2',
        'collected'             => 'decimal:2',
        'effective_rate_kasko'  => 'decimal:10',
        'kasko_amount'          => 'decimal:2',
        'fee_annual_rate'       => 'decimal:4',
        'cancelled_at'          => 'datetime',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'contract_id');
    }
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'contract_id');
    }
    public function prepayments(): HasMany
    {
        return $this->hasMany(Prepayment::class, 'contract_id');
    }
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'seller_id');
    }
    public function pledgee(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'pledgee_id');
    }
    public function history(): HasMany
    {
        return $this->hasMany(History::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(Evaluator::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class,'fileable');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class);
    }

    public function items()
    {
        return $this->belongsToMany(Item::class, 'contract_item');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
    /**
     * Contracts whose money was actually paid out. provided_at is only set by
     * pay-amount and was added in 2026-02 without a backfill, so older/imported
     * contracts are recognised by having a deal or a journal entry instead.
     */
    public function scopeProvided($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('contracts.provided_at')
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('deals')
                    ->whereColumn('deals.contract_id', 'contracts.id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('document_journals')
                    ->whereColumn('documents_journal.contract_id', 'contracts.id'));
        });
    }

    /** Created by POST /contracts but the second step (pay-amount) never happened. */
    public function scopeAwaitingDisbursement($query)
    {
        return $query->where('contracts.status', self::STATUS_INITIAL)
            ->whereNull('contracts.provided_at')
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('deals')
                ->whereColumn('deals.contract_id', 'contracts.id'))
            ->whereNotExists(fn ($s) => $s->selectRaw('1')->from('document_journals')
                ->whereColumn('document_journals.contract_id', 'contracts.id'));
    }

    public function isProvided(): bool
    {
        return $this->provided_at !== null
            || $this->deals()->withTrashed()->exists()
            || DocumentJournal::withTrashed()->where('contract_id', $this->id)->exists();
    }

    public function scopeFilterStatus($query, $status, $overdueDate = null)
    {
        switch ($status) {
            case 'ակտիվ':
            case 'initial':
                return $query->where('status', 'initial')->provided();
            case 'awaiting_disbursement':
                return $query->awaitingDisbursement();
            case 'cancelled':
                return $query->where('status', self::STATUS_CANCELLED);
            case 'Փակված':
            case 'completed':
                return $query->where('status', 'completed');
            case 'Իրացված':
            case 'executed':
                return $query->where('status', 'executed');
            case 'Ժամկետնանց':
            case 'overdue':
                $cutoff = $overdueDate ?: today()->toDateString();
                return $query->where('status', 'initial')
                    ->provided()
                    ->whereRaw(
                        '(SELECT COALESCE(SUM(p.amount - COALESCE(pe.paid, 0)), 0)
                          FROM payments p
                          LEFT JOIN (SELECT payment_id, SUM(amount) as paid FROM payment_entries GROUP BY payment_id) pe
                            ON pe.payment_id = p.id
                          WHERE p.contract_id = contracts.id AND p.deleted_at IS NULL AND DATE(p.date) < ? AND p.status = ?) >= 1000',
                        [$cutoff, 'initial']
                    );
            case 'todays':
                return $query->where('status', 'initial')->provided()->whereHas('payments', function ($q) {
                    $q->whereDate('date', today())
                        ->where('status','initial');
                });
            default:
                return $query;
        }
    }
    public function getIsOverdueAttribute()
    {
        // The schedule of an undisbursed or cancelled contract is not a debt.
        if ($this->status !== self::STATUS_INITIAL || !$this->isProvided()) {
            return false;
        }

        $overdueAmount = $this->payments()
            ->whereDate('date', '<', Carbon::today())
            ->where('status', 'initial')
            ->get()
            ->sum(function ($payment) {
                $alreadyPaid = $payment->entries()->sum('amount');
                return max(0, (float) $payment->amount - $alreadyPaid);
            });

        return $overdueAmount >= 1000;
    }
    public function scopeFilterByRange($query, $field, $from, $to)
    {
        if ($from) {
            $query->where($field, '>=', $from);
        }
        if ($to) {
            $query->where($field, '<=', $to);
        }
        return $query;
    }

    public function scopeFilterByDate($query, $field, $from, $to)
    {
        if ($from) {
            $query->whereDate($field, '>=', $from);
        }
        if ($to) {
            $query->whereDate($field, '<=', $to);
        }
        return $query;
    }
    public function scopeFilterByClient($query, $filters)
    {
        if (!empty($filters['name'])) {
            $nameTerms = preg_split('/\s+/', trim($filters['name']));
            $query->whereHas('client', function ($q) use ($nameTerms) {
                foreach ($nameTerms as $term) {
                    $q->where(function ($sub) use ($term) {
                        $sub->where('name', 'LIKE', '%' . $term . '%')
                            ->orWhere('surname', 'LIKE', '%' . $term . '%');
                    });
                }
            });
        }

        if (!empty($filters['surname'])) {
            $query->whereHas('client', function ($q) use ($filters) {
                $q->where('surname', 'LIKE', '%' . $filters['surname'] . '%');
            });
        }
        if (!empty($filters['patronymic'])) {
            $query->whereHas('client', function ($q) use ($filters) {
                $q->where('middle_name', 'LIKE', '%' . $filters['patronymic'] . '%');
            });
        }
        if (!empty($filters['passport'])) {
            $query->whereHas('client', function ($q) use ($filters) {
                $q->where('passport_series', 'LIKE', '%' . $filters['passport'] . '%');
            });
        }
        if (!empty($filters['phone'])) {
            $query->where('phone', 'LIKE', '%' . $filters['phone'] . '%');
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query->whereBetween('date', [$filters['start_date'], $filters['end_date']]);
        } elseif (!empty($filters['start_date'])) {
            $query->where('date', '>=', $filters['start_date']);
        } elseif (!empty($filters['end_date'])) {
            $query->where('date', '<=', $filters['end_date']);
        }

        return $query;
    }
    public function scopeFilterByContractItem($query, $type = null, $subspecies = null, $model = null)
    {
        if (!empty($type)) {
            $query->whereHas('category', function ($q) use ($type) {
                $q->where('title', $type);
            });
        }

        if (!empty($subspecies)) {
            $query->whereHas('items', function ($q) use ($subspecies) {
                $q->where('subcategory', 'LIKE', '%' . $subspecies . '%');
            });
        }

        if (!empty($model)) {
            $query->whereHas('items', function ($q) use ($model) {
                $q->where('model', 'LIKE', '%' . $model . '%');
            });
        }

        return $query;
    }

    public function scopeFilterByDelayDays($query, $delayDays)
    {
        if ($delayDays) {
            return $query->whereHas('payments', function ($q) use ($delayDays) {
                $q->where('status', 'initial')
                    ->whereRaw("
                DATEDIFF(
                    CURDATE(),
                    STR_TO_DATE(`date`, '%Y-%m-%d')
                ) >= ?", [$delayDays]);
            });
        }
        return $query;
    }
    public function scopeDailySummaryForMonth($query, $year, $month)
    {
        return $query->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->selectRaw('DATE(date) as day, COUNT(*) as count, SUM(estimated_amount) as total_estimated, SUM(provided_amount) as total_provided')
            ->groupBy('day')
            ->orderBy('day');
    }

    public function getNotPayedAmountAttribute()
    {
        if ($this->status === self::STATUS_CANCELLED || !$this->isProvided()) {
            return 0;
        }

        $notPayed = $this->payments()
            ->where('status', 'initial')
            ->selectRaw('SUM(amount + mother) as total')
            ->value('total');
        $penalty = $this->countPenalty($this->id)['penalty_amount'];
        return $notPayed+$penalty;
    }
    /** Same rule as scopeAwaitingDisbursement, for a single loaded contract. */
    public function getIsAwaitingDisbursementAttribute(): bool
    {
        return $this->status === self::STATUS_INITIAL && !$this->isProvided();
    }

    /** Age in days, so the list can show how long a contract has been waiting for disbursement. */
    public function getDaysSinceCreatedAttribute(): ?int
    {
        return $this->created_at ? (int) $this->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null;
    }

    public function guarantors(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'contract_guarantors');
    }

}
