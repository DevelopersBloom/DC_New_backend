<?php
//namespace App\Jobs;
//
//
//use App\Models\Contract;
//use App\Models\DocumentJournal;
//use App\Models\Order;
//use App\Models\Transaction;
//use App\Models\ChartOfAccount;
//use App\Services\EffectiveRateService;
//use Illuminate\Queue\SerializesModels;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Log;
//use Illuminate\Bus\Queueable;
//use Illuminate\Contracts\Queue\ShouldQueue;
//use Illuminate\Foundation\Bus\Dispatchable;
//use Illuminate\Queue\InteractsWithQueue;
//use App\Models\Client;
//use Carbon\Carbon;
//use PhpParser\Comment\Doc;
//
//class ProcessContractDailyRate implements ShouldQueue
//{
//    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
//
//    public function __construct(){}
//
//    private function calculateCurrentAmortizedBalance(Contract $contract): float
//    {
//        $initialProvided = (float) DocumentJournal::where('journalable_type', Contract::class)
//            ->where('journalable_id', $contract->id)
//            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->value('amount_amd') ?? (float) $contract->mother;
//
//        $lumpOrder = Order::where('contract_id', $contract->id)
//            ->where('filter', Order::REFUND_LUMP_FILTER)
//            ->first();
//
//        $fees = $lumpOrder ? $lumpOrder->amount : ($initialProvided * ($contract->lump_rate / 100));
//
//        $netAmount = $initialProvided - $fees;
//
//        $journal = DocumentJournal::where('journalable_type', Contract::class)
//            ->where('journalable_id', $contract->id)
//            ->first();
//
//        if (!$journal) return $netAmount;
//
//        $effectiveAccrualsSum = DocumentJournal::where('journalable_id', $journal->id)
//            ->where('journalable_type', DocumentJournal::class)
//            ->where('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT)
//            ->sum('amount_amd');
//
//        $nominalAccrualsSum = DocumentJournal::where('journalable_id', $journal->id)
//            ->where('journalable_type', DocumentJournal::class)
//            ->where('document_type', DocumentJournal::INTEREST_REPAYMENT)
//            ->sum('amount_amd');
//
//        $motherPaymentsSum = DocumentJournal::where('journalable_id', $journal->id)
//            ->where('journalable_type', DocumentJournal::class)
//            ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
//            ->sum('amount_amd');
//
//        $amortizedBalance = $netAmount + $effectiveAccrualsSum - $nominalAccrualsSum - $motherPaymentsSum;
//        return $amortizedBalance;
//    }
//    private function getDailyEffectiveRate(float $xirr): float
//    {
//        if ($xirr <= 0) return 0.0;
//        return pow((1 + $xirr), (1 / 365)) - 1;
//    }
//
//    public function handle(EffectiveRateService $effectiveRateService)
//    {
//
//        $activeContracts = Contract::where('status', 'initial')->get();
//
//        if ($activeContracts->isEmpty()) {
//            Log::info('No active contracts found to process.');
//            return;
//        }
//
//        $creditPartnerId = Client::where('company_name', 'Diamond Credit')->first()->id ?? 1;
//        $acc16200 = ChartOfAccount::idByCode('16200') ?? 1;
//        $acc60120 = ChartOfAccount::idByCode('60120') ?? 1;
//        $acc16201NI = ChartOfAccount::idByCode('16201NI') ?? 1;
//        $acc16200EF = ChartOfAccount::idByCode('16200EF') ?? 1;
//        $acc60120EF = ChartOfAccount::idByCode('60120EF') ?? 1;
//
//        $documentTypeEffective = DocumentJournal::EFFECTIVE_RATE_AMOUNT;
//        $documentTypeInterest = DocumentJournal::INTEREST_RATE_AMOUNT;
//
//        $date = Carbon::now()->format('Y-m-d');
//        $systemUserId = auth()->check() ? auth()->id() : 1;
//
//        /** @var Contract $contract */
//        foreach ($activeContracts as $contract) {
//            $contractId = $contract->id;
//            $debetPartnerId = $contract->client_id;
//
//            DB::beginTransaction();
//            try {
//
//                $openingAmount = $this->calculateCurrentAmortizedBalance($contract);
//                $dailyEffectiveRate =$contract->effective_daily_rate ?? 0;
//                Log::info("openingAmount: {$openingAmount}, dailyEffectiveRate:{$dailyEffectiveRate}");
//                $days = 1;
//                if ($openingAmount > 0 && $dailyEffectiveRate > 0) {
//                    $effectiveAmount = $openingAmount * $dailyEffectiveRate / 100;
//
////                    $effectiveAmount = $openingAmount * (pow((1 + $dailyEffectiveRate/100), $days) - 1);
////                    $calculatedEffectiveAmount = intval(ceil($effectiveAmount / 10) * 10);
//                    Log::info("effectiveAmount: {$effectiveAmount}");
//
//                    if ($effectiveAmount > 0) {
//                        $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
//                        $journal = DocumentJournal::where('journalable_type', Contract::class)
//                            ->where('journalable_id', $contract->id)
//                            ->first();
//
//                        $journalDocEffective = DocumentJournal::create([
//                            'date' => $date,
//                            'document_number' => $nextDocNum,
//                            'document_type' => $documentTypeEffective,
//                            'amount_amd' => $effectiveAmount,
//                            'partner_id' => $debetPartnerId,
//                            'credit_partner_id' => $creditPartnerId,
//                            'comment' => 'Daily effective interest accrual for contract #' . $contractId,
//                            'debit_account_id' => $acc16200,
//                            'credit_account_id' => $acc60120,
//                            'user_id' => $systemUserId,
//                            'journalable_type' => DocumentJournal::class,
//                            'journalable_id' => $journal->id,
//                        ]);
//
//                        Transaction::create([
//                            'date' => $date,
//                            'document_number' => $nextDocNum,
//                            'document_type' => $documentTypeEffective,
//                            'debit_account_id' => $acc16200,
//                            'debit_partner_id' => $debetPartnerId,
//                            'debit_currency_id' => 1,
//                            'credit_account_id' => $acc60120,
//                            'credit_currency_id' => 1,
//                            'credit_partner_id' => $creditPartnerId,
//                            'amount_amd' => $effectiveAmount,
//                            'comment' => 'Daily effective interest accrual for contract #' . $contractId,
//                            'user_id' => $systemUserId,
//                            'is_system' => true,
//                            'disbursement_date' => $date,
//                            'transactionable_type' => DocumentJournal::class,
//                            'transactionable_id' => $journalDocEffective->id,
//                        ]);
//                        $nextDocNum++;
//                        Log::info("Daily Effective Accrued: {$effectiveAmount} AMD for Contract #{$contractId}");
//                    }
//                }
//
//                $amount = $contract->provided_amount;
//                $interestRate = $contract->interest_rate;
//                $calculatedInterest = $interestRate / 100 * $amount;
//
//                $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
//                $journal = DocumentJournal::where('journalable_type', Contract::class)
//                    ->where('journalable_id', $contract->id)
//                    ->first();
//
//                if ($calculatedInterest > 0) {
//                    $journalDocInterest = DocumentJournal::create([
//                        'date' => $date,
//                        'document_number' => $nextDocNum,
//                        'document_type' => $documentTypeInterest,
//                        'amount_amd' => $calculatedInterest,
//                        'partner_id' => $debetPartnerId,
//                        'credit_partner_id' => $debetPartnerId,
//                        'comment' => 'Daily interest calculation for contract #' . $contractId,
//                        'debit_account_id' => $acc16201NI,
//                        'credit_account_id' => $acc16200,
//                        'user_id' => $systemUserId,
//                        'journalable_type' => DocumentJournal::class,
//                        'journalable_id' => $journal->id,
//                    ]);
//
//                    Transaction::create([
//                        'date' => $date,
//                        'document_number' => $nextDocNum,
//                        'document_type' => $documentTypeInterest,
//                        'debit_account_id' => $acc16201NI,
//                        'debit_partner_id' => $debetPartnerId,
//                        'debit_currency_id' => 1,
//                        'credit_account_id' => $acc16200,
//                        'credit_currency_id' => 1,
//                        'credit_partner_id' => $debetPartnerId,
//                        'amount_amd' => $calculatedInterest,
//                        'comment' => 'Daily interest calculation for contract #' . $contractId,
//                        'user_id' => $systemUserId,
//                        'is_system' => true,
//                        'disbursement_date' => $date,
//                        'transactionable_type' => DocumentJournal::class,
//                        'transactionable_id' => $journalDocInterest->id,
//                    ]);
//                    $nextDocNum++;
//                    Log::info("Daily Interest Accrued: {$calculatedInterest} AMD for Contract #{$contractId}");
//                }
//
//                DB::commit();
//            } catch (Exception $e) {
//                DB::rollBack();
//                Log::error('ProcessContractDailyRate failed for contract ' . $contractId . ': ' . $e->getMessage());
//            }
//        }
//
//        Log::info('Finished processing all active contracts.');
//    }
//}


namespace App\Jobs;

use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\ChartOfAccount;
use App\Models\PostingRule;
use App\Models\Client;
use App\Services\EffectiveRateService;
use App\Traits\ContractTrait;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Carbon\Carbon;

class ProcessContractDailyRate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use ContractTrait;

    public function __construct()
    {
    }

    private function calculateCurrentAmortizedBalance(Contract $contract): float
    {
        $initialProvided = (float)$contract->mother;

        $fees = $initialProvided * ($contract->lump_rate / 100);
        $netAmount = $initialProvided - $fees;

        $journal = DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->first();

        if (!$journal) return $netAmount;

        $effectiveAccrualsSum = DocumentJournal::where('journalable_id', $journal->id)
            ->where('journalable_type', DocumentJournal::class)
            ->where('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT)
            ->sum('amount_amd');

        $nominalAccrualsSum = DocumentJournal::where('journalable_id', $journal->id)
            ->where('journalable_type', DocumentJournal::class)
            ->where('document_type', DocumentJournal::INTEREST_REPAYMENT)
            ->sum('amount_amd');

        $motherPaymentsSum = DocumentJournal::where('journalable_id', $journal->id)
            ->where('journalable_type', DocumentJournal::class)
            ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
            ->sum('amount_amd');

        return $netAmount + $effectiveAccrualsSum - $nominalAccrualsSum - $motherPaymentsSum;
    }

    public function handle(EffectiveRateService $effectiveRateService)
    {
        $activeContracts = Contract::where('status', 'initial')->with('client.classification')->get();

        if ($activeContracts->isEmpty()) {
            Log::info('No active contracts found to process.');
            return;
        }

        $diamondId = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;
        $date = Carbon::now()->format('Y-m-d');
        $systemUserId = 1;

        foreach ($activeContracts as $contract) {
            DB::beginTransaction();
            try {
                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $contract->id)
                    ->first();

                if (!$journal) {
                    DB::rollBack();
                    continue;
                }

                $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;

                $openingAmount = $this->calculateCurrentAmortizedBalance($contract);
                $dailyEffectiveRate = $contract->effective_daily_rate ?? 0;
                $effectiveAmount = ($openingAmount > 0 && $dailyEffectiveRate > 0) ? ($openingAmount * $dailyEffectiveRate / 100) : 0;

                if ($effectiveAmount > 0) {
                    $ruleEffective = PostingRule::where('business_event_filter', 'effective_rate_amount')->first();
                    if ($ruleEffective) {
                        $journalDocEffective = DocumentJournal::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::EFFECTIVE_RATE_AMOUNT,
                            'amount_amd' => $effectiveAmount,
                            'debit_partner_id' => $contract->client_id,
                            'credit_partner_id' => $diamondId,
                            'comment' => 'Daily effective interest accrual for contract #' . $contract->id,
                            'debit_account_id' => $ruleEffective->debit_account_id,
                            'credit_account_id' => $ruleEffective->credit_account_id,
                            'user_id' => $systemUserId,
                            'journalable_type' => DocumentJournal::class,
                            'journalable_id' => $journal->id,
                        ]);

                        Transaction::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::EFFECTIVE_RATE_AMOUNT,
                            'debit_account_id' => $ruleEffective->debit_account_id,
                            'debit_partner_id' => $contract->client_id,
                            'credit_account_id' => $ruleEffective->credit_account_id,
                            'credit_partner_id' => $diamondId,
                            'amount_amd' => $effectiveAmount,
                            'comment' => 'Daily effective interest accrual for contract #' . $contract->id,
                            'user_id' => $systemUserId,
                            'is_system' => true,
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id' => $journalDocEffective->id,
                        ]);
                        $nextDocNum++;

                        $classification = $contract->client->classification;
                        if ($classification && $classification->reserve_percent > 0) {
                            $reserveAmount = $effectiveAmount * $classification->reserve_percent / 100;
                            $isStandard = $classification->name == 'standard';

                            $eventFilter = $isStandard ? 'reserve_general_amount' : 'reserve_special_amount';
                            $docTypeReserve = $isStandard ? DocumentJournal::RESERVE_GENERAL_AMOUNT : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                            $ruleReserve = PostingRule::where('business_event_filter', $eventFilter)->first();

                            if ($ruleReserve && $reserveAmount > 0) {
                                $journalDocReserve = DocumentJournal::create([
                                    'date' => $date,
                                    'document_number' => $nextDocNum,
                                    'document_type' => $docTypeReserve,
                                    'amount_amd' => $reserveAmount,
                                    'debit_partner_id' => $diamondId,
                                    'credit_partner_id' => $contract->client_id,
                                    'comment' => "Daily reserve for contract #{$contract->id}",
                                    'debit_account_id' => $ruleReserve->debit_account_id,
                                    'credit_account_id' => $ruleReserve->credit_account_id,
                                    'user_id' => $systemUserId,
                                    'journalable_type' => DocumentJournal::class,
                                    'journalable_id' => $journal->id,
                                ]);

                                Transaction::create([
                                    'date' => $date,
                                    'document_number' => $nextDocNum,
                                    'document_type' => $docTypeReserve,
                                    'debit_account_id' => $ruleReserve->debit_account_id,
                                    'debit_partner_id' => $diamondId,
                                    'credit_account_id' => $ruleReserve->credit_account_id,
                                    'credit_partner_id' => $contract->client_id,
                                    'amount_amd' => $reserveAmount,
                                    'comment' => "Daily reserve for contract #{$contract->id}",
                                    'user_id' => $systemUserId,
                                    'is_system' => true,
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $journalDocReserve->id,
                                ]);
                                $nextDocNum++;
                            }
                        }
                    }
                }

                $calculatedInterest = ($contract->provided_amount * $contract->interest_rate / 100);
                if ($calculatedInterest > 0) {
                    $ruleInterest = PostingRule::where('business_event_filter', 'interest_rate_amount')->first();
                    if ($ruleInterest) {
                        $journalDocInterest = DocumentJournal::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::INTEREST_RATE_AMOUNT,
                            'amount_amd' => $calculatedInterest,
                            'debit_partner_id' => $contract->client_id,
                            'credit_partner_id' => $contract->client_id,
                            'comment' => 'Daily interest calculation for contract #' . $contract->id,
                            'debit_account_id' => $ruleInterest->debit_account_id,
                            'credit_account_id' => $ruleInterest->credit_account_id,
                            'user_id' => $systemUserId,
                            'journalable_type' => DocumentJournal::class,
                            'journalable_id' => $journal->id,
                        ]);

                        Transaction::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => DocumentJournal::INTEREST_RATE_AMOUNT,
                            'debit_account_id' => $ruleInterest->debit_account_id,
                            'debit_partner_id' => $contract->client_id,
                            'credit_account_id' => $ruleInterest->credit_account_id,
                            'credit_partner_id' => $contract->client_id,
                            'amount_amd' => $calculatedInterest,
                            'comment' => 'Daily interest calculation for contract #' . $contract->id,
                            'user_id' => $systemUserId,
                            'is_system' => true,
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id' => $journalDocInterest->id,
                        ]);
                    }
                }

//                $isExpiredContract = !empty($contract->deadline)
//                    && Carbon::parse($contract->deadline, 'Asia/Yerevan')->startOfDay()
//                        ->lt(Carbon::parse($date, 'Asia/Yerevan')->startOfDay());

//                if ($isExpiredContract && (float) ($contract->penalty ?? 0) > 0) {
                    $penaltyYesterday = (float) ($this->countPenalty(
                        $contract->id,
                        Carbon::parse($date, 'Asia/Yerevan')->subDay()->toDateString()
                    )['penalty_amount'] ?? 0);

                    $penaltyToday = (float) ($this->countPenalty($contract->id, $date)['penalty_amount'] ?? 0);
                    $dailyPenaltyAmount = max(0, $penaltyToday - $penaltyYesterday);

                    if ($dailyPenaltyAmount > 0) {
                        $rulePenalty = PostingRule::where('business_event_filter', 'penalty_rate_amount')->first();
                        if ($rulePenalty) {
                            $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
                            $journalDocPenalty = DocumentJournal::create([
                                'date' => $date,
                                'document_number' => $nextDocNum,
                                'document_type' => DocumentJournal::PENALTY_RATE_AMOUNT,
                                'amount_amd' => $dailyPenaltyAmount,
                                'debit_partner_id' => $contract->client_id,
                                'credit_partner_id' => $diamondId,
                                'comment' => 'Daily penalty accrual for contract #' . $contract->id,
                                'debit_account_id' => $rulePenalty->debit_account_id,
                                'credit_account_id' => $rulePenalty->credit_account_id,
                                'user_id' => $systemUserId,
                                'journalable_type' => DocumentJournal::class,
                                'journalable_id' => $journal->id,
                            ]);

                            Transaction::create([
                                'date' => $date,
                                'document_number' => $nextDocNum,
                                'document_type' => DocumentJournal::PENALTY_RATE_AMOUNT,
                                'debit_account_id' => $rulePenalty->debit_account_id,
                                'debit_partner_id' => $contract->client_id,
                                'credit_account_id' => $rulePenalty->credit_account_id,
                                'credit_partner_id' => $diamondId,
                                'amount_amd' => $dailyPenaltyAmount,
                                'comment' => 'Daily penalty accrual for contract #' . $contract->id,
                                'user_id' => $systemUserId,
                                'is_system' => true,
                                'transactionable_type' => DocumentJournal::class,
                                'transactionable_id' => $journalDocPenalty->id,
                            ]);
                        }
                    }
//                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('ProcessContractDailyRate failed for contract ' . $contract->id . ': ' . $e->getMessage());
            }
        }

        Log::info('Finished processing all active contracts.');
    }
}
