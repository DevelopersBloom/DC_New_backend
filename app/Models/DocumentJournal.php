<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class DocumentJournal extends Model
{
    use HasFactory;

    protected $table = 'documents_journal';

    const REMINDER_ORDER_TYPE = 'Հիշարար օրդեր';
    const LOAN_NDM_TYPE      = 'Ներգրավված Դրամական Միջոցներ';
    const LOAN_ATTRACTION    = 'Վարկի ներգրավում';
    const EFFECTIVE_RATE     = 'Արդյունավետ տոկոսի հաշվարկում';
    const INTEREST_RATE      = 'Տոկոսի հաշվարկում';

    protected $fillable = [
        'date',
        'operation_number',
        'operation_name',

        'document_number',
        'document_type',

        'amount_amd',
        'currency_id',
        'amount_currency',

        'partner_id',
        'credit_partner_id',
        'debit_account_id',
        'credit_account_id',
        'ndm_repayment_id',

        'cash',
        'pawnshop_id',

        'comment',
        'user_id',

        'journalable_type',
        'journalable_id',
    ];

    protected $casts = [
        'date'                       => 'date:Y-m-d',
        'cash'                       => 'boolean',
        'amount_amd'                 => 'decimal:2',
        'amount_currency'            => 'decimal:2',
    ];
//    protected static function boot()
//    {
//        parent::boot();
//
//        static::deleting(function (DocumentJournal $journal) {
//            $journal->transactions()->each(function ($trx) {
//                $trx->forceDelete();
//            });
//        });
//    }
    protected static function boot()
    {
        parent::boot();

        static::deleting(function (DocumentJournal $journal) {
            if ($journal->journalable_type === \App\Models\LoanNdm::class) {
                /** @var \App\Models\LoanNdm|null $loan */
                $loan = $journal->journalable;
                if (!$loan) {
                    $loan = \App\Models\LoanNdm::find($journal->journalable_id);
                }

                if ($loan) {
                    $otherJournalIds = self::query()
                        ->where('journalable_type', \App\Models\LoanNdm::class)
                        ->where('journalable_id', $loan->id)
                        ->where('id', '!=', $journal->id)
                        ->pluck('id');

                    $latestOpDate = null;
                    if ($otherJournalIds->isNotEmpty()) {
                        $latestOpDate = \App\Models\Transaction::query()
                            ->where('transactionable_type', self::class)
                            ->whereIn('transactionable_id', $otherJournalIds)
                            ->whereIn('document_type', [self::EFFECTIVE_RATE, self::INTEREST_RATE])
                            ->max('date');
                    }

                    $loan->calc_date = $latestOpDate ? Carbon::parse($latestOpDate)->toDateString() : null;
                    $loan->saveQuietly();
                }
            }

            $journal->transactions()->each(function ($trx) {
                $trx->forceDelete();
            });
        });
    }

    public function journalable(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'partner_id');
    }

    public function creditPartner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'credit_partner_id');
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function pawnshop(): BelongsTo
    {
        return $this->belongsTo(Pawnshop::class, 'pawnshop_id');
    }
    public function ndmRepayment(): BelongsTo
    {
        return $this->belongsTo(NdmRepaymentDetail::class, 'ndm_repayment_id');
    }

}
