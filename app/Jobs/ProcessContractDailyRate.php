<?php
//
//namespace App\Jobs;
//
//use Illuminate\Bus\Queueable;
//use Illuminate\Contracts\Queue\ShouldQueue;
//use Illuminate\Foundation\Bus\Dispatchable;
//use Illuminate\Queue\InteractsWithQueue;
//use Illuminate\Queue\SerializesModels;
//
//use Exception;
//use App\Models\Contract;
//use App\Models\DocumentJournal;
//use App\Models\Transaction;
//use App\Models\ChartOfAccount;
//use App\Services\EffectiveRateService;
//use Illuminate\Support\Facades\DB;
//use Illuminate\Support\Facades\Log;
//
//use App\Models\Client;
//use Carbon\Carbon;
//
//class ProcessContractDailyRate implements ShouldQueue
//{
//    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
//
//    public function __construct(){}
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
//        $documentTypeEffective = DocumentJournal::EFFECTIVE_RATE_AMOUNT;
//        $documentTypeInterest = DocumentJournal::INTEREST_RATE_AMOUNT;
//        $date = Carbon::now()->format('Y-m-d');
//        $systemUserId = auth()->check() ? auth()->id() : 1;
//
//        /** @var Contract $contract */
//        foreach ($activeContracts as $contract) {
//            $contractId = $contract->id;
//
//            DB::beginTransaction();
//            try {
//                $amount = $contract->provided_amount;
//                $interestRate = $contract->interest_rate;
//                $calculatedInterest = intval(ceil($interestRate * $amount * 0.01 / 10) * 10);
//                $debetPartnerId = $contract->client_id;
//                $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;
//                $journal = DocumentJournal::where('journalable_type', Contract::class)
//                    ->where('journalable_id', $contract->id)
//                    ->first();
//                if ($calculatedInterest > 0) {
//                    $journalDocInterest = DocumentJournal::create([
//                        'date'                 => $date,
//                        'document_number'      => $nextDocNum,
//                        'document_type'        => $documentTypeInterest,
//                        'amount_amd'           => $calculatedInterest,
//                        'partner_id'           => $debetPartnerId,
//                        'credit_partner_id'    => $creditPartnerId,
//                        'comment'              => 'Daily interest calculation for contract #' . $contractId,
//                        'debit_account_id'     => $acc16201NI,
//                        'credit_account_id'    => $acc16200,
//                        'user_id'              => $systemUserId,
//                        'journalable_type'     => DocumentJournal::class,
//                        'journalable_id'       => $journal->id,
//                    ]);
//
//                    Transaction::create([
//                        'date'                 => $date,
//                        'document_number'      => $nextDocNum,
//                        'document_type'        => $documentTypeInterest,
//                        'debit_account_id'     => $acc16201NI,
//                        'debit_partner_id'     => $debetPartnerId,
//                        'debit_currency_id'    => 1,
//                        'credit_account_id'    => $acc16200,
//                        'credit_currency_id'   => 1,
//                        'credit_partner_id'    => $creditPartnerId,
//                        'amount_amd'           => $calculatedInterest,
//                        'comment'              => 'Daily interest calculation for contract #' . $contractId,
//                        'user_id'              => $systemUserId,
//                        'is_system'            => true,
//                        'disbursement_date'    => $date,
//                        'transactionable_type' => DocumentJournal::class,
//                        'transactionable_id'   => $journalDocInterest->id,
//                    ]);
//                    $nextDocNum++;
//                    Log::info("Daily Interest Accrued: {$calculatedInterest} AMD for Contract #{$contractId}");
//                }
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
use App\Services\EffectiveRateService;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use App\Models\Client;
use Carbon\Carbon;
use PhpParser\Comment\Doc;

class ProcessContractDailyRate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

//    private function calculateCurrentAmortizedBalance(Contract $contract): float
//    {
//        $balance = $contract->mother;
//
//        $journal = DocumentJournal::where('journalable_type', Contract::class)
//            ->where('journalable_id', $contract->id)
//            ->first();
//
//        $journalId = $journal->id;
//
//        $lumpAmount = Order::where('contract_id', $contract->id)
//            ->where('filter', Order::REFUND_LUMP_FILTER)
//            ->select('amount')
//            ->first();
//        $fees = $lumpAmount?->amount ?? $contract->mother * ($contract->lump_rate / 100);
//
//
//        $initialProvided = (float) DocumentJournal::where('journalable_type', Contract::class)
//            ->where('journalable_id', $contract->id)
//            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
//            ->value('amount_amd') ?? (float) $contract->mother;
//        $netAmount = $initialProvided - $fees;
//
//        $motherPaymentsSum = DocumentJournal::where('journalable_type', DocumentJournal::class)
//            ->where('journalable_id', $journalId)
//            ->where('document_type', DocumentJournal::PAY_MOTHER_AMOUNT)
//            ->sum('amount_amd');
//
//        $interestPaymentsSum =  DocumentJournal::where('journalable_type', DocumentJournal::class)
//            ->where('journalable_id', $journalId)
//            ->where('document_type', DocumentJournal::PAY_INTEREST_AMOUNT)
//            ->sum('amount_amd');
//
//        $effectiveAccrualsSum = DocumentJournal::where('journalable_type', DocumentJournal::class)
//            ->where('journalable_id', $journalId)
//            ->where('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT)
//            ->sum('amount_amd');
//
//        $amortizedBalance = $netAmount + $effectiveAccrualsSum - $motherPaymentsSum - $interestPaymentsSum;
//        Log::info("initialProvided: {$initialProvided}, motherPaymentsSum:{$motherPaymentsSum}");
//        Log::info("interestPaymentsSum: {$interestPaymentsSum}, effectiveAccrualsSum:{$effectiveAccrualsSum}");
//
//        return $amortizedBalance;
//    }
    private function calculateCurrentAmortizedBalance(Contract $contract): float
    {
        $initialProvided = (float) DocumentJournal::where('journalable_type', Contract::class)
            ->where('journalable_id', $contract->id)
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->value('amount_amd') ?? (float) $contract->mother;

        $lumpOrder = Order::where('contract_id', $contract->id)
            ->where('filter', Order::REFUND_LUMP_FILTER)
            ->first();

        $fees = $lumpOrder ? $lumpOrder->amount : ($initialProvided * ($contract->lump_rate / 100));

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

        $amortizedBalance = $netAmount + $effectiveAccrualsSum - $nominalAccrualsSum - $motherPaymentsSum;
        return $amortizedBalance;
    }
    private function getDailyEffectiveRate(float $xirr): float
    {
        if ($xirr <= 0) return 0.0;
        return pow((1 + $xirr), (1 / 365)) - 1;
    }

    public function handle(EffectiveRateService $effectiveRateService)
    {

        $activeContracts = Contract::where('status', 'initial')->get();

        if ($activeContracts->isEmpty()) {
            Log::info('No active contracts found to process.');
            return;
        }

        $creditPartnerId = Client::where('company_name', 'Diamond Credit')->first()->id ?? 1;
        $acc16200 = ChartOfAccount::idByCode('16200') ?? 1;
        $acc60120 = ChartOfAccount::idByCode('60120') ?? 1;
        $acc16201NI = ChartOfAccount::idByCode('16201NI') ?? 1;
        $acc16200EF = ChartOfAccount::idByCode('16200EF') ?? 1;
        $acc60120EF = ChartOfAccount::idByCode('60120EF') ?? 1;

        $documentTypeEffective = DocumentJournal::EFFECTIVE_RATE_AMOUNT;
        $documentTypeInterest = DocumentJournal::INTEREST_RATE_AMOUNT;

        $date = Carbon::now()->format('Y-m-d');
        $systemUserId = auth()->check() ? auth()->id() : 1;

        /** @var Contract $contract */
        foreach ($activeContracts as $contract) {
            $contractId = $contract->id;
            $debetPartnerId = $contract->client_id;

            DB::beginTransaction();
            try {

                $openingAmount = $this->calculateCurrentAmortizedBalance($contract);
                $dailyEffectiveRate =$contract->effective_daily_rate ?? 0;
                Log::info("openingAmount: {$openingAmount}, dailyEffectiveRate:{$dailyEffectiveRate}");
                $days = 1;
                if ($openingAmount > 0 && $dailyEffectiveRate > 0) {
                    $effectiveAmount = $openingAmount * $dailyEffectiveRate / 100;

//                    $effectiveAmount = $openingAmount * (pow((1 + $dailyEffectiveRate/100), $days) - 1);
//                    $calculatedEffectiveAmount = intval(ceil($effectiveAmount / 10) * 10);
                    Log::info("effectiveAmount: {$effectiveAmount}");

                    if ($effectiveAmount > 0) {
                        $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
                        $journal = DocumentJournal::where('journalable_type', Contract::class)
                            ->where('journalable_id', $contract->id)
                            ->first();

                        $journalDocEffective = DocumentJournal::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => $documentTypeEffective,
                            'amount_amd' => $effectiveAmount,
                            'partner_id' => $debetPartnerId,
                            'credit_partner_id' => $creditPartnerId,
                            'comment' => 'Daily effective interest accrual for contract #' . $contractId,
                            'debit_account_id' => $acc16200,
                            'credit_account_id' => $acc60120,
                            'user_id' => $systemUserId,
                            'journalable_type' => DocumentJournal::class,
                            'journalable_id' => $journal->id,
                        ]);

                        Transaction::create([
                            'date' => $date,
                            'document_number' => $nextDocNum,
                            'document_type' => $documentTypeEffective,
                            'debit_account_id' => $acc16200,
                            'debit_partner_id' => $debetPartnerId,
                            'debit_currency_id' => 1,
                            'credit_account_id' => $acc60120,
                            'credit_currency_id' => 1,
                            'credit_partner_id' => $creditPartnerId,
                            'amount_amd' => $effectiveAmount,
                            'comment' => 'Daily effective interest accrual for contract #' . $contractId,
                            'user_id' => $systemUserId,
                            'is_system' => true,
                            'disbursement_date' => $date,
                            'transactionable_type' => DocumentJournal::class,
                            'transactionable_id' => $journalDocEffective->id,
                        ]);
                        $nextDocNum++;
                        Log::info("Daily Effective Accrued: {$effectiveAmount} AMD for Contract #{$contractId}");
                    }
                }

                $amount = $contract->provided_amount;
                $interestRate = $contract->interest_rate;
                $calculatedInterest = $interestRate / 100 * $amount;

                $nextDocNum = (int)(Transaction::max('document_number') ?? 0) + 1;
                $journal = DocumentJournal::where('journalable_type', Contract::class)
                    ->where('journalable_id', $contract->id)
                    ->first();

                if ($calculatedInterest > 0) {
                    $journalDocInterest = DocumentJournal::create([
                        'date' => $date,
                        'document_number' => $nextDocNum,
                        'document_type' => $documentTypeInterest,
                        'amount_amd' => $calculatedInterest,
                        'partner_id' => $debetPartnerId,
                        'credit_partner_id' => $debetPartnerId,
                        'comment' => 'Daily interest calculation for contract #' . $contractId,
                        'debit_account_id' => $acc16201NI,
                        'credit_account_id' => $acc16200,
                        'user_id' => $systemUserId,
                        'journalable_type' => DocumentJournal::class,
                        'journalable_id' => $journal->id,
                    ]);

                    Transaction::create([
                        'date' => $date,
                        'document_number' => $nextDocNum,
                        'document_type' => $documentTypeInterest,
                        'debit_account_id' => $acc16201NI,
                        'debit_partner_id' => $debetPartnerId,
                        'debit_currency_id' => 1,
                        'credit_account_id' => $acc16200,
                        'credit_currency_id' => 1,
                        'credit_partner_id' => $debetPartnerId,
                        'amount_amd' => $calculatedInterest,
                        'comment' => 'Daily interest calculation for contract #' . $contractId,
                        'user_id' => $systemUserId,
                        'is_system' => true,
                        'disbursement_date' => $date,
                        'transactionable_type' => DocumentJournal::class,
                        'transactionable_id' => $journalDocInterest->id,
                    ]);
                    $nextDocNum++;
                    Log::info("Daily Interest Accrued: {$calculatedInterest} AMD for Contract #{$contractId}");
                }

                DB::commit();
            } catch (Exception $e) {
                DB::rollBack();
                Log::error('ProcessContractDailyRate failed for contract ' . $contractId . ': ' . $e->getMessage());
            }
        }

        Log::info('Finished processing all active contracts.');
    }
}
