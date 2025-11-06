<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentJournal extends Model
{
    use HasFactory,SoftDeletes;

    protected $table = 'documents_journal';

    const REMINDER_ORDER_TYPE = 'Հիշարար օրդեր';
    const LOAN_NDM_TYPE      = 'Ներգրավված Դրամական Միջոցներ';
    const LOAN_ATTRACTION    = 'Վարկի ներգրավում';
    const EFFECTIVE_RATE     = 'Արդյունավետ տոկոսի հաշվարկում';
    const INTEREST_RATE      = 'Տոկոսի հաշվարկում';

    const INTEREST_REPAYMENT = 'Տոկոսի մարում';
    const LOAN_REPAYMENT = 'Վարկի մարում';
    const TAX_REPAYMENT = 'Հարկի գանձում տոկոսի մարումից';
    const PAY_INTEREST_AMOUNT = 'Տոկոսի մարում';
    const PROVIDE_CONTRACT_AMOUNT = 'Վարկի Տրամադրում-անվանական գումար';
    const PAY_MOTHER_AMOUNT = 'Վարկերի մարում անվանական արժեքով';
    const PROVIDED_AMOUNT_CHANGE = 'Վերականգնումներ ընդհանուր պահուստին իրականացված հատկացումների մասով';
    const RESERVE_SPECIAL_AMOUNT = 'Հատկացումներ հատուկ պահուստին';
    const RESERVE_GENERAL_AMOUNT = 'Հատկացումներ ընդհանուր պահուստին';

    const EFFECTIVE_RATE_AMOUNT = 'Տոկոսային եկամուտ արդյունավետ';
    const INTEREST_RATE_AMOUNT = 'Տոկոսային եկամուտ անվանական';
    const CLASSIFICATION = 'Պահուստների միջև վերադասակարգում';
    const LOSS_RESERVE_AMOUNT = 'Վարկերի դուրս գրում-անվանական արժեքով';

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
            DB::transaction(function () use ($journal) {

                if ($journal->document_type == self::LOAN_ATTRACTION) {

                    $ndmId   = $journal->journalable_id;
                    $ndmType = $journal->journalable_type;

                    $nextAttraction = self::query()
                        ->where('journalable_id',  $ndmId)
                        ->where('journalable_type', $ndmType)
                        ->where('document_type', self::LOAN_ATTRACTION)
                        ->where('date', '>', $journal->date)
                        ->orderBy('date')->orderBy('id')
                        ->first();

                    $txQ = Transaction::query()
                        ->where('transactionable_id',  $ndmId)
                        ->where('transactionable_type', $ndmType)
                        ->whereDate('date', '>=', $journal->date);

                    if ($nextAttraction) {
                        $txQ->whereDate('date', '<', $nextAttraction->date);
                    }

                    $txQ->delete(); // soft delete

                    $calcTypes = ['Արդյունավետ տոկոսի հաշվարկում', 'Տոկոսի հաշվարկում'];

                    $lastCalcDate = Transaction::query()
                        ->where('transactionable_id',  $ndmId)
                        ->where('transactionable_type', $ndmType)
                        ->whereIn('document_type', $calcTypes)
                        ->max('date');

                    $contractDate = LoanNdm::query()
                        ->whereKey($ndmId)
                        ->value('contract_date');

                    $calcDate = $lastCalcDate ?? $contractDate;

                    if ($calcDate) {
                        LoanNdm::query()->whereKey($ndmId)->update(['calc_date' => $calcDate]);
                    }

                }
//                elseif (in_array($journal->document_type, [
//                    self::INTEREST_REPAYMENT,
//                    self::LOAN_REPAYMENT,
//                    self::TAX_REPAYMENT,
//                ], true)) {
//                    $journal->transactions()->delete();
//                    $journal->journals()->delete();
//                }
                else {
                    $journal->transactions()->delete();
                    $journal->journals()->delete();
                    $journal->contracts()->delete();
                }
            });
        });

        static::restoring(function (DocumentJournal $journal) {
            DB::transaction(function () use ($journal) {

                if ($journal->document_type == self::LOAN_ATTRACTION) {

                    $ndmId   = $journal->journalable_id;
                    $ndmType = $journal->journalable_type;

                    $nextAttraction = self::withTrashed()
                        ->where('journalable_id',  $ndmId)
                        ->where('journalable_type', $ndmType)
                        ->where('document_type', self::LOAN_ATTRACTION)
                        ->where('date', '>', $journal->date)
                        ->orderBy('date')->orderBy('id')
                        ->first();

                    $txQ = Transaction::onlyTrashed()
                        ->where('transactionable_id',  $ndmId)
                        ->where('transactionable_type', $ndmType)
                        ->whereDate('date', '>=', $journal->date);

                    if ($nextAttraction) {
                        $txQ->whereDate('date', '<', $nextAttraction->date);
                    }

                    $txQ->restore();

                    $calcTypes = ['Արդյունավետ տոկոսի հաշվարկում', 'Տոկոսի հաշվարկում'];

                    $lastCalcDate = Transaction::query()
                        ->where('transactionable_id',  $ndmId)
                        ->where('transactionable_type', $ndmType)
                        ->whereIn('document_type', $calcTypes)
                        ->max('date');

                    $contractDate = LoanNdm::query()
                        ->whereKey($ndmId)
                        ->value('contract_date');

                    $calcDate = $lastCalcDate ?? $contractDate;

                    if ($calcDate) {
                        LoanNdm::query()->whereKey($ndmId)->update(['calc_date' => $calcDate]);
                    }

                }
                elseif (in_array($journal->document_type, [
                    self::INTEREST_REPAYMENT,
                    self::LOAN_REPAYMENT,
                    self::TAX_REPAYMENT,
                ], true)) {

                    $journal->transactions()->onlyTrashed()->restore();
                    $journal->journals()->onlyTrashed()->restore();
                } else {

                        $ndmId   = $journal->journalable_id;
                        $ndmType = $journal->journalable_type ?: \App\Models\LoanNdm::class;
                        if (class_exists($ndmType)) {
                            $ndmType::withTrashed()->whereKey($ndmId)->restore();
                        } else {
                            \App\Models\LoanNdm::withTrashed()->whereKey($ndmId)->restore();
                        }

                        $journal->journals()
                            ->onlyTrashed()
                            ->get()
                            ->each(function (DocumentJournal $child) {
                                $child->restore();

                                $child->transactions()->onlyTrashed()->restore();
                            });

                        $journal->transactions()->onlyTrashed()->restore();

                        \App\Models\Transaction::onlyTrashed()
                            ->where('transactionable_id',  $ndmId)
                            ->where('transactionable_type', $ndmType)
                            ->restore();

                        $calcTypes = ['Արդյունավետ տոկոսի հաշվարկում', 'Տոկոսի հաշվարկում'];

                        $lastCalcDate = \App\Models\Transaction::query()
                            ->where('transactionable_id',  $ndmId)
                            ->where('transactionable_type', $ndmType)
                            ->whereIn('document_type', $calcTypes)
                            ->max('date');

                        $contractDate = \App\Models\LoanNdm::query()
                            ->whereKey($ndmId)
                            ->value('contract_date');

                        $calcDate = $lastCalcDate ?? $contractDate;

                        if ($calcDate) {
                            \App\Models\LoanNdm::query()
                                ->whereKey($ndmId)
                                ->update(['calc_date' => $calcDate]);
                        }

                }


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
    public function journals() {
        return $this->morphMany(self::class, 'journalable');
    }
    public function contracts() {
        return $this->morphMany(Contract::class, 'contractable');
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
    public function scopeBetweenDates($query, $from = null, $to = null)
    {
        if ($from && $to) {
            return $query->whereBetween('date', [$from, $to]);
        }
        if ($from) return $query->where('date', '>=', $from);
        if ($to)   return $query->where('date', '<=', $to);
        return $query;
    }
    public function remainingCapacity(?string $toDate = null): float
    {
        $totalAttraction = Transaction::where('transactionable_id', $this->id)
            ->where('transactionable_type', DocumentJournal::class)
            ->where('document_type',Transaction::LOAN_ATTRACTION)
            ->sum('amount_amd');

        $totalRepayment = Transaction::where('transactionable_id', $this->id)
            ->where('transactionable_type',DocumentJournal::class)
            ->where('document_type',Transaction::LOAN_REPAYMENT)
            ->sum('amount_amd');

        $loanAmount = LoanNdm::where('id',$this->journalable_id)->select('amount');

        $loanAmount = (float) (LoanNdm::query()
            ->whereKey($this->journalable_id)
            ->value('amount') ?? 0);

        $remainingBalance = (float)$loanAmount - (float)$totalAttraction + (float)$totalRepayment;

        return max($remainingBalance,0);
    }


}
