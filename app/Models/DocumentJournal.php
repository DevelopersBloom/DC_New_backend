<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    const PAY_MOTHER_AMOUNT_CASH = 'pay_mother_amount_cash';
    const PROVIDED_AMOUNT_CHANGE = 'Վերականգնումներ ընդհանուր պահուստին իրականացված հատկացումների մասով';
    const PROVIDE_SPECIAL_AMOUNT_CHANGE = 'Վերականգնումներ հատուկր պահուստին իրականացված հատկացումների մասով';
    const RESERVE_SPECIAL_AMOUNT = 'Հատկացումներ հատուկ պահուստին';
    const RESERVE_GENERAL_AMOUNT = 'Հատկացումներ ընդհանուր պահուստին';

    const EFFECTIVE_RATE_AMOUNT = 'Տոկոսային եկամուտ արդյունավետ';
    const INTEREST_RATE_AMOUNT = 'Տոկոսային եկամուտ անվանական';
    const PENALTY_RATE_AMOUNT = 'Տուգանքի հաշվեգրում';
    const CLASSIFICATION = 'Պահուստների միջև վերադասակարգում';
    const LOSS_RESERVE_AMOUNT = 'Վարկերի դուրս գրում-անվանական արժեքով';
    const LOSS_RESERVE_EFFECTIVE = 'Վարկերի դուրս գրում-արդյունավետ արժեքով';
    const LOSS_RESERVE = 'Վարկի դուրս գրում';
    protected $fillable = [
        'date',
        'operation_number',
        'operation_name',

        'document_number',
        'document_type',

        'amount_amd',
        'currency_id',
        'amount_currency',

        'debit_partner_id',
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
        'deal_id'
    ];

    protected $casts = [
        'date'                       => 'date:Y-m-d',
        'cash'                       => 'boolean',
        'amount_amd'                 => 'decimal:2',
        'amount_currency'            => 'decimal:2',
    ];
    protected static function booted(): void
    {
        parent::booted();
//        static::created(function (DocumentJournal $journal) {
//            $journal->loadMissing(['debitAccount', 'creditAccount']);
//
//            $debitCode = $journal->debitAccount->code ?? '';
//            $creditCode = $journal->creditAccount->code ?? '';
//
//            $isDebitBank = str_starts_with($debitCode, '102101');
//            $isCreditBank = str_starts_with($creditCode, '102101');
//            if ($isDebitBank || $isCreditBank) {
//                $bankAccountIds = \App\Models\ChartOfAccount::where('code', 'like', '10210%')->pluck('id');
//
//                $debitSum = Transaction::whereIn('debit_account_id', $bankAccountIds)->sum('amount_amd');
//                $creditSum = Transaction::whereIn('credit_account_id', $bankAccountIds)->sum('amount_amd');
//
//                $currentBalance = $debitSum - $creditSum;
//
////                $provisionAmount = $currentBalance * 0.01;
//                $provisionAmount = $journal->amount_amd * 0.01;
//                $provisionAmount = max($provisionAmount, 0);
//                    if ($isDebitBank) {
//                        self::createProvisionEntry($journal, $provisionAmount, 'Պահուստավորում', '730041', '15300PC');
//                    } else {
//                        self::createProvisionEntry($journal, $provisionAmount, 'Ապապահուստավորում', '15300PC', '63102');
//                    }
//            }
//        });
        static::deleting(function (DocumentJournal $journal) {
            DB::transaction(function () use ($journal) {
                // Avoid cascading deletion of all documents in a deal when deleting
                // a single child journal row (e.g. one payment line).
                if (
                    $journal->deal_id &&
                    $journal->deal &&
                    $journal->journalable_type !== self::class
                ) {
                    $journal->deal->delete();
                }
                self::syncContractProvidedAmountOnMotherPaymentDelete($journal);
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
                    $childIds = $journal->journals()->pluck('id')->toArray();

                    $histories = ClassificationHistory::where('actionable_type', DocumentJournal::class)
                        ->where(function ($q) use ($journal, $childIds) {
                            $q->where('actionable_id', $journal->id)
                                ->orWhereIn('actionable_id', $childIds);
                        })
                        ->get();

                    foreach ($histories as $history) {
                        $client = $history->client;
                        if ($client) {
                            $oldClassificationId = $history->meta['old_classification_id'] ?? null;
                            $client->classification_id = $oldClassificationId;
                            $client->save();
                        }
                        $history->delete();
                    }

                    $journal->transactions()->delete();
                    $journal->classificationHistories()->delete();
//                    $journal->journals()->delete();
                    $journal->journals()->get()->each(function (DocumentJournal $child) {
                        $child->transactions()->delete();
                        $child->delete();
                    });
                    $related = $journal->journalable;
//                    if ($related instanceof Contract) {
//                        $related->delete();
//                    }
                }
            });
        });

        static::restoring(function (DocumentJournal $journal) {
            DB::transaction(function () use ($journal) {
                if ($journal->deal_id) {
                    $journal->deal()->withTrashed()->restore();
                }
                self::syncContractProvidedAmountOnMotherPaymentRestore($journal);
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
                        $ndmType = $journal->journalable_type ?: LoanNdm::class;
                        if (class_exists($ndmType)) {
                            $ndmType::withTrashed()->whereKey($ndmId)->restore();
                        } else {
                            LoanNdm::withTrashed()->whereKey($ndmId)->restore();
                        }

                        $journal->journals()
                            ->onlyTrashed()
                            ->get()
                            ->each(function (DocumentJournal $child) {
                                $child->restore();

                                $child->transactions()->onlyTrashed()->restore();
                            });

                        $journal->transactions()->onlyTrashed()->restore();

                        Transaction::onlyTrashed()
                            ->where('transactionable_id',  $ndmId)
                            ->where('transactionable_type', $ndmType)
                            ->restore();

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
                            LoanNdm::query()
                                ->whereKey($ndmId)
                                ->update(['calc_date' => $calcDate]);
                        }

                }


            });
        });
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }
    public function journalable(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }
    public function classificationHistories(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(ClassificationHistory::class, 'actionable');
    }
    public function journals() {
        return $this->morphMany(self::class, 'journalable');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debitPartner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'debit_partner_id');
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
    public function parentDoc()
    {
        return $this->morphTo(__FUNCTION__, 'journalable_type', 'journalable_id');
    }
    public function children()
    {
        return $this->hasMany(DocumentJournal::class, 'parent_id');
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

    protected static function createProvisionEntry(DocumentJournal $parent, $amount, $label, $debitCode, $creditCode)
    {
        $debitAcc = ChartOfAccount::where('code', $debitCode)->first();
        $creditAcc = ChartOfAccount::where('code', $creditCode)->first();
        $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;
        if (!$debitAcc || !$creditAcc) return;

        $childJournal = DocumentJournal::create([
            'date'               => $parent->date,
            'document_type'      => $label,
            'document_number'    => $nextDocNum,
            'amount_amd'         => $amount,
            'debit_account_id'   => $debitAcc->id,
            'credit_account_id'  => $creditAcc->id,
            'user_id'            => $parent->user_id,
            'journalable_type'   => DocumentJournal::class,
            'journalable_id'     => $parent->id,
            'comment'            => $label . ' (Անկանխիկի 1%)',
        ]);

        Transaction::create([
            'date'                 => $parent->date,
            'document_type'        => $label,
            'document_number'    => $nextDocNum,

            'debit_account_id'     => $debitAcc->id,
            'credit_account_id'    => $creditAcc->id,
            'amount_amd'           => $amount,
            'user_id'              => $parent->user_id,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id'   => $childJournal->id,
            'is_system'            => true,
        ]);
    }

    protected static function syncContractProvidedAmountOnMotherPaymentDelete(DocumentJournal $journal): void
    {
        if ($journal->document_type !== self::PAY_MOTHER_AMOUNT) {
            return;
        }

        $contract = self::resolveContractFromPaymentJournal($journal);
        if (!$contract) {
            return;
        }

        $amount = (float) ($journal->amount_amd ?? 0);
        if ($amount <= 0) {
            return;
        }

        $contract->provided_amount = (float) ($contract->provided_amount ?? 0) + $amount;
        $contract->left = (float) ($contract->left ?? 0) + $amount;
        $contract->save();
    }

    protected static function syncContractProvidedAmountOnMotherPaymentRestore(DocumentJournal $journal): void
    {
        if ($journal->document_type !== self::PAY_MOTHER_AMOUNT) {
            return;
        }

        $contract = self::resolveContractFromPaymentJournal($journal);
        if (!$contract) {
            return;
        }

        $amount = (float) ($journal->amount_amd ?? 0);
        if ($amount <= 0) {
            return;
        }

        $contract->provided_amount = max(0, (float) ($contract->provided_amount ?? 0) - $amount);
        $contract->left = max(0, (float) ($contract->left ?? 0) - $amount);
        $contract->save();
    }

    protected static function resolveContractFromPaymentJournal(DocumentJournal $journal): ?Contract
    {
        if ($journal->journalable_type !== self::class || !$journal->journalable_id) {
            return null;
        }

        $parentJournal = self::withTrashed()->find($journal->journalable_id);
        if (!$parentJournal) {
            return null;
        }

        $contractTypes = [Contract::class, 'Contract'];
        if (!in_array($parentJournal->journalable_type, $contractTypes, true)) {
            return null;
        }

        return Contract::withTrashed()->find($parentJournal->journalable_id);
    }
}
