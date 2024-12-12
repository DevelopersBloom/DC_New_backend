<?php

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
class Contract extends Model
{
    use HasFactory;
    use SoftDeletes;

    const CONTRACT_OPENING = 'Պայմանագրի բացում';
    const LUMP_PAYMENT = 'Միանվագ վճար';
    const MOTHER_AMOUNT_PAYMENT = 'ՄԳ տրամադրում';
    const REGULAR_PAYMENT = 'Հերթական վճարում';
    const FULL_PAYMENT = 'Ամբողջական վճարում';
    const PARTIAL_PAYMENT ='Մասնակի վճարում';

    protected $fillable=[
        'collected',
        'rate',
        'penalty',
        'penalty_amount',
        'one_time_payment',
        'executed',
        'deadline',
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
        'ADB_ID',
        'passport_given',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'contract_id');
    }
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'contract_id');
    }


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
