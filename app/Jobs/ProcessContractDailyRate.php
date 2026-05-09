<?php

namespace App\Jobs;

use App\Models\Contract;
use App\Models\DocumentJournal;
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
        Log::info("Contract processed");
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

                if (!$journal || $contract->client->classification->name == 'loss') {
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
