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
        'currency_id',
        'contract_kind',
        'loan_type',
        'interest_rate_type',
        'security_type'
    ];

    protected $casts = [
        'payment_schedule' => 'array',
        'estimated_amount'      => 'decimal:2',
        'provided_amount'       => 'decimal:2',
        'contract_amount'       => 'decimal:2',
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

    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'contract_id');
    }
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'contract_id');
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
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'seller_id');
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
    public function scopeFilterStatus($query, $status)
    {
        switch ($status) {
            case 'ակտիվ':
            case 'initial':
                return $query->where('status', 'initial');
            case 'Փակված':
            case 'completed':
                return $query->where('status', 'completed');
            case 'Իրացված':
            case 'executed':
                return $query->where('status', 'executed');
            case 'Ժամկետնանց':
            case 'overdue':
                return $query->whereHas('payments', function ($q) {
                    $q->whereDate('date', '<', today())
                    ->where('status', 'initial');
                });
            case 'todays':
                return $query->whereHas('payments', function ($q) {
                    $q->whereDate('date', today())
                        ->where('status','initial');
                });
            default:
                return $query;
        }
    }
    public function getIsOverdueAttribute()
    {
        return $this->payments()
            ->whereDate('date', '<', Carbon::today())
            ->where('status', 'initial')
            ->exists();
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
            $query->whereHas('client', function ($q) use ($filters) {
                $q->where('name', 'LIKE', '%' . $filters['name'] . '%');
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
        $notPayed = $this->payments()
            ->where('status', 'initial')
            ->selectRaw('SUM(amount + mother) as total')
            ->value('total');
        $penalty = $this->countPenalty($this->id)['penalty_amount'];
        return $notPayed+$penalty;
    }
    public function guarantors(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'contract_guarantors');
    }

}
