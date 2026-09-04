<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deal extends Model
{
    use HasFactory,SoftDeletes;
    const HISTORY =  'history';
    const IN_DEAL = 'in';
    const OUT_DEAL = 'out';
    const COST_OUT_DEAL = 'cost_out';
    const NDM_DEAL = 'ndm';
    const APPA_DEAL = 'appa';
    const EXPENSE_DEAL = 'expense';
    const TAKEN_PURPOSE = 'Իրացված';

    protected $fillable = [
        'type',
        'amount',
        'penalty',
        'discount',
        'interest_amount',
        'prepayment_principal',
        'prepayment_interest',
        'principal_amount',
        'order_id',
        'pawnshop_id',
        'contract_id',
        'client_id',
        'payer_client_id',
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
        'is_recount',
        'payment_id',
        'history_id',
        'category_id'
    ];
    protected $casts = [
        'cash' => 'boolean',
        'is_recount' => 'boolean',
        'amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'penalty' => 'decimal:2',
        'cashbox' => 'decimal:2',
        'bank_cashbox' => 'decimal:2',
        'worth' => 'decimal:2',
        'given' => 'decimal:2',
        'insurance' => 'decimal:2',
        'funds' => 'decimal:2',
        'prepayment_principal' => 'decimal:2',
        'prepayment_interest' => 'decimal:2',
        'principal_amount' => 'decimal:2',
    ];
    // Guards against Deal::deleting <-> DocumentJournal::deleting mutual recursion:
    // deleting a deal cascades to its documents, but deleting a Contract-journalable
    // document calls back into $document->deal->delete() for the same deal id.
    protected static array $deletingIds = [];

    protected static function boot()
    {
        parent::boot();
        // Admin PUT /admin/update-deals uses DealsTableOnlyUpdateService (query builder),
        // not Eloquent save(), so this hook does not run for manager deal edits.
        static::updating(function ($deal) {
            if ($deal->isDirty('date')) {
                $newDate = $deal->date;

                if ($deal->order) {
                    $deal->order->update(['date' => $newDate]);
                }

                if ($deal->history) {
                    $deal->history->update(['date' => $newDate]);
                }
            }
        });
        static::deleting(function ($deal) {
            if (in_array($deal->id, self::$deletingIds, true)) {
                return;
            }

            self::$deletingIds[] = $deal->id;
            try {
                if ($deal->history) {
                    $deal->history->delete();
                }

                if ($deal->order) {
                    $deal->order->delete();
                }

                $deal->documents()->get()->each(fn ($document) => $document->delete());
            } finally {
                self::$deletingIds = array_diff(self::$deletingIds, [$deal->id]);
            }
        });
    }

    /**
     * Runs $callback with $dealId marked as "currently being deleted", so any
     * DocumentJournal::deleting()-triggered $document->deal->delete() call for
     * this same deal (see DocumentJournal.php's journalable_type!==self check)
     * is a no-op for the duration — used by callers that delete a deal's
     * DocumentJournal rows directly and don't want that recursive callback
     * re-querying/re-deleting the same rows out from under the in-progress loop.
     */
    public static function withDeletionGuard(int $dealId, callable $callback)
    {
        $alreadyGuarded = in_array($dealId, self::$deletingIds, true);
        if (!$alreadyGuarded) {
            self::$deletingIds[] = $dealId;
        }

        try {
            return $callback();
        } finally {
            if (!$alreadyGuarded) {
                self::$deletingIds = array_diff(self::$deletingIds, [$dealId]);
            }
        }
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentJournal::class, 'deal_id');
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
    public function payerClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'payer_client_id');
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
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function actions()
    {
        return $this->hasMany(DealAction::class, 'deal_id', 'id');
    }
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

}
