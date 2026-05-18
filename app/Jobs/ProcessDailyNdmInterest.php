<?php

namespace App\Jobs;

use App\Models\LoanNdm;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Models\Client;
use App\Models\DocumentJournal;
use App\Services\ActivityService;
use App\Services\LoanNdmInterestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use App\Traits\NotifiesOnFailure;

class ProcessDailyNdmInterest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use NotifiesOnFailure;
    public $tries = 3;

    public function handle()
    {
        $today = Carbon::today()->toDateString();
        Log::info("NdmInterestJob started for date: {$today}");

        $ruleEffective = PostingRule::where('business_event_filter', 'effective_interest_calculation')->first();
        $ruleInterest  = PostingRule::where('business_event_filter', 'interest_calculation')->first();

        if (!$ruleEffective || !$ruleInterest) {
            Log::error('Posting rules missing for interest/effective interest calculation.');
            return;
        }

        $effectiveDebit  = $ruleEffective->debit_account_id;
        $effectiveCredit = $ruleEffective->credit_account_id;

        $interestDebit  = $ruleInterest->debit_account_id;
        $interestCredit = $ruleInterest->credit_account_id;

        LoanNdm::chunkById(200, function ($loans) use ($today, $effectiveDebit, $effectiveCredit, $interestDebit, $interestCredit) {
            foreach ($loans as $loan) {

                Log::info("Processing LoanNdm #{$loan->id}");

                try {
                    $from = $loan->calc_date ?? $loan->created_at->toDateString();
                    Log::info("Loan #{$loan->id} FROM date: {$from}, TO: {$today}");

                    $service = app()->make(LoanNdmInterestService::class);
                    $result  = $service->calculate($loan, $from, $today);

                    Log::info("Loan #{$loan->id} calculation result: ", $result);

                    $interestAmount  = (float) ($result['interest_amount'] ?? 0);
                    $effectiveAmount = (float) ($result['effective_interest_amount'] ?? 0);

                    if ($interestAmount == 0 && $effectiveAmount == 0) {
                        Log::info("Loan #{$loan->id} skipped â€” both amounts zero");
                        $loan->calc_date = $today;
                        $loan->saveQuietly();
                        continue;
                    }

                    DB::beginTransaction();

                    $loan->calc_date = $today;
                    $loan->save();

                    Log::info("Loan #{$loan->id} calc_date updated to {$today}");

                    // Find journal
                    $journal = DocumentJournal::where([
                        'journalable_type' => LoanNdm::class,
                        'journalable_id'   => $loan->id
                    ])->first();

                    if (!$journal) {
                        Log::error("Journal not found for LoanNdm #{$loan->id}");
                        DB::rollBack();
                        continue;
                    }

                    Log::info("Found journal ID {$journal->id} for LoanNdm #{$loan->id}");

                    $clientId   = $loan->client_id;
                    $currencyId = $journal->currency_id ?? $loan->currency_id;

                    $nextDocNum = Transaction::getNextDocumentNumber();

                    Log::info("Next document number: {$nextDocNum}");

                    $mkTx = function (array $attrs) use (&$nextDocNum, $currencyId, $journal, $today) {
                        Log::info("Creating transaction: ", $attrs);

                        $tx = Transaction::create($attrs + [
                                'date'                 => $today,
                                'document_number'      => $nextDocNum++,
                                'debit_currency_id'    => $currencyId,
                                'credit_currency_id'   => $currencyId,
                                'amount_currency'      => $attrs['amount_amd'] ?? 0,
                                'amount_currency_id'   => $currencyId,
                                'comment'              => $attrs['comment'] ?? null,
                                'user_id'              => $journal->user_id,
                                'is_system'            => true,
                                'transactionable_type' => DocumentJournal::class,
                                'transactionable_id'   => $journal->id,
                            ]);

                        Log::info("Created TX ID {$tx->id}");
                        return $tx;
                    };

                    if ($effectiveAmount > 0) {
                        Log::info("Posting effective interest {$effectiveAmount} for loan #{$loan->id}");

                        $mkTx([
                            'document_type'     => DocumentJournal::EFFECTIVE_RATE ?? 'effective_rate',
                            'debit_account_id'  => $effectiveDebit,
                            'credit_account_id' => $effectiveCredit,
                            'amount_amd'        => $effectiveAmount,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Auto: effective interest for LoanNdm #{$loan->id} for {$today}",
                        ]);
                    }

                    if ($interestAmount > 0) {
                        Log::info("Posting interest {$interestAmount} for loan #{$loan->id}");

                        $mkTx([
                            'document_type'     => DocumentJournal::INTEREST_RATE ?? 'interest_rate',
                            'debit_account_id'  => $interestDebit,
                            'credit_account_id' => $interestCredit,
                            'amount_amd'        => $interestAmount,
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Auto: interest for LoanNdm #{$loan->id} for {$today}",
                        ]);
                    }

                    Log::info("Loan #{$loan->id} interest posted OK");

                    DB::commit();

                } catch (\Throwable $e) {
                    DB::rollBack();

                    Log::error("ERROR processing LoanNdm #{$loan->id}: ".$e->getMessage(), [
                        'loan_id' => $loan->id,
                        'trace'   => $e->getTraceAsString(),
                    ]);
                }
            }
        });

        Log::info("NdmInterestJob finished for date: {$today}");
    }
}


