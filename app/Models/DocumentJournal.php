<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    protected static function booted(): void
    {
        static::deleting(function (DocumentJournal $journal) {
            if ($journal->document_type !== self::LOAN_ATTRACTION) {
                return;
            }

            DB::transaction(function () use ($journal) {
                $ndmId   = $journal->journalable_id;
                $ndmType = $journal->journalable_type;

                $nextAttraction = self::query()
                    ->where('journalable_id',  $ndmId)
                    ->where('journalable_type', $ndmType)
                    ->where('document_type', self::LOAN_ATTRACTION)
                    ->where('date', '>', $journal->date)
                    ->orderBy('date')->orderBy('id')
                    ->first();

                Transaction::query()
                    ->where('transactionable_id',  $ndmId)
                    ->where('transactionable_type', $ndmType)
                    ->where('date', '>=', $journal->date)
                    ->forceDelete();

                $calcTypes = [
                    'Արդյունավետ տոկոսի հաշվարկում',
                    'Տոկոսի հաշվարկում',
                ];

                $lastCalcDate =Transaction::query()
                    ->where('transactionable_id', $journal->journalable_id )
                    ->where('transactionable_type', $ndmType) // օրինակ՝ 'App\Models                                                                                        \LoanNdm'
                    ->whereIn('document_type',  $calcTypes)
                    ->max('date'); // վերադարձնում է string/Carbon՝ ըստ քո cast-երի

                $ndmId = DocumentJournal::where('id',$journal->journalable_id)->value('journalable_id');                                                                                       e('journalable_id');

                if (!is_null($lastCalcDate)) {
                    LoanNdm::query()
                        ->where('id', $ndmId)
                        ->update(['calc_date' => $lastCalcDate]);
                }
            });
        });
    }

//    protected static function booted(): void
//    {
//        static::deleting(function (DocumentJournal $journal) {
//            if ($journal->document_type !== self::LOAN_ATTRACTION) {
//                return;
//            }
//
//            DB::transaction(function () use ($journal) {
//                $ndmId   = $journal->journalable_id;
//                $ndmType = $journal->journalable_type;
//
//                // գտնել հաջորդ ներգրավումը (կարող է չլինել)
//                $nextAttraction = self::query()
//                    ->where('journalable_id',  $ndmId)
//                    ->where('journalable_type', $ndmType)
//                    ->where('document_type', self::LOAN_ATTRACTION)
//                    ->where('date', '>', $journal->date)
//                    ->orderBy('date')->orderBy('id')
//                    ->first();
//
//                // ջնջել ՄԻԱՅՆ այս ներգրավումից սկսվող տրանզակցիաները,
//                // իսկ վերին սահմանը կիրառել միայն եթե կա հաջորդ ներգրավում
//                Transaction::query()
//                    ->where('transactionable_id',  $ndmId)
//                    ->where('transactionable_type', $ndmType)
//                    ->where('date', '>=', $journal->date)
//                    ->forceDelete();
//
//                // deleting event-ի մեջ ՉԵՆՔ կանչում $journal->delete()
//            });
//        });
//    }
//    protected static function boot()
//    {
//        parent::boot();
//
//        static::deleting(function (DocumentJournal $journal) {
//            $journal->transactions()->each(function ($trx) {
//                $trx->forceDelete();
//            });
//            if ($journal->journalable_type === \App\Models\LoanNdm::class) {
//                /** @var \App\Models\LoanNdm|null $loan */
//                $loan = $journal->journalable;
//                if ($loan) {
//                    $loan->calc_date = $loan->disbursement_date;
//                    $loan->save();
//                }
//            }
//        });
//    }

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
