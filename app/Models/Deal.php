<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory,SoftDeletes;
    const HISTORY =  'history';
    const IN_DEAL = 'in';
    const OUT_DEAL = 'out';
    const COST_OUT_DEAL = 'cost_out';
    const APPA_DEAL = 'appa';
    const EXPENSE_DEAL = 'expense';
    const TAKEN_PURPOSE = 'Իրացված';

    protected $fillable = [
        'type',
        'amount',
        'penalty',
        'discount',
        'interest_amount',
        'order_id',
        'pawnshop_id',
        'contract_id',
        'client_id',
        'cashbox',
        'bank_cashbox',
        'worth',
        'funds',
        'cash',
        'given',
        'insurance',
        'date',
        'delay_days',
        'purpose',
        'receiver',
        'source',
        'created_by',
        'updated_by',
        'filter_type',
        'payment_id',
        'history_id',
        'category_id'
    ];
    protected $casts = [
        'cash' => 'boolean'
    ];
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($deal) {
//            if ($deal->isForceDeleting()) {
//                if ($deal->history) {
//                    $deal->history->forceDelete();
//                }
//
//                if ($deal->order) {
//                    $deal->order->forceDelete();
//                }
//
//                if ($deal->payment) {
//                    $deal->payment->forceDelete();
//                }
//            } else {
                if ($deal->history) {
                    $deal->history->delete();
                }

                if ($deal->order) {
                    $deal->order->delete();
                }
//            }
        });
    }
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
    public function history(): BelongsTo
    {
        return $this->belongsTo(History::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
