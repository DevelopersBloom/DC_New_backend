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

class ProcessDailyNdmInterest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tries = 3;

    public function handle()
    {
        $today = Carbon::today()->toDateString();

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
                try {
                    $from = $loan->calc_date ?? $loan->created_at->toDateString();

                    $service = app()->make(LoanNdmInterestService::class);

                    $result = $service->calculate($loan, $from, $today);

                    $interestAmount = (float) ($result['interest_amount'] ?? 0);
                    $effectiveAmount = (float) ($result['effective_interest_amount'] ?? 0);

                    if ($interestAmount == 0 && $effectiveAmount == 0) {
                        $loan->calc_date = $today;
                        $loan->saveQuietly();
                        continue;
                    }

                    DB::beginTransaction();

                    $loan->calc_date = $today;
                    $loan->save();

                    $journal = DocumentJournal::where([
                        'journalable_type' => LoanNdm::class,
                        'journalable_id'   => $loan->id
                    ])->firstOrFail();


                    $lombardId  = Client::where('company_name', 'Diamond Credit')->value('id');
                    $clientId   = $loan->client_id;
                    $currencyId = $journal->currency_id ?? $loan->currency_id;

                    // simple doc number generation (race possible in highly concurrent envs)
                    $nextDocNum = (int) (Transaction::max('document_number') ?? 0) + 1;

                    $mkTx = function (array $attrs) use (&$nextDocNum, $currencyId, $journal, $today) {
                        return Transaction::create($attrs + [
                                'date'                 => $today,
                                'document_number'      => $nextDocNum++,
                                'debit_currency_id'    => $currencyId,
                                'credit_currency_id'   => $currencyId,
                                'amount_currency'      => $attrs['amount_amd'] ?? 0,
                                'amount_currency_id'   => $currencyId,
                                'comment'              => $attrs['comment'] ?? null,
                                'user_id'              => Auth::id() ?? $journal->user_id,
                                'is_system'            => true,
                                'transactionable_type' => DocumentJournal::class,
                                'transactionable_id'   => $journal->id,
                            ]);
                    };

                    $created = [];

                    if ($effectiveAmount > 0) {
                        $created['effective'] = $mkTx([
                            'document_type'     => DocumentJournal::EFFECTIVE_RATE ?? 'effective_rate',
                            'debit_account_id'  => $effectiveDebit,
                            'credit_account_id' => $effectiveCredit,
                            'amount_amd'        => $effectiveAmount,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Auto: effective interest for LoanNdm #{$loan->id} for {$today}",
                        ]);
                    }

                    if ($interestAmount > 0) {
                        $created['interest'] = $mkTx([
                            'document_type'     => DocumentJournal::INTEREST_RATE ?? 'interest_rate',
                            'debit_account_id'  => $interestDebit,
                            'credit_account_id' => $interestCredit,
                            'amount_amd'        => $interestAmount,
                            'debit_partner_id'  => $clientId,
                            'credit_partner_id' => $clientId,
                            'comment'           => "Auto: interest for LoanNdm #{$loan->id} for {$today}",
                        ]);
                    }

                    if (app()->bound('activity')) {
                        app('activity')->log(
                            action: 'loan_interest_posted',
                            description: "Auto posted interest for Loan NDM #{$loan->id}. Effective: {$effectiveAmount}, Interest: {$interestAmount}",
                            model: LoanNdm::class,
                            modelId: $loan->id
                        );
                    } else {
                        Log::info("Auto posted interest for LoanNdm {$loan->id}. Eff: {$effectiveAmount}, Int: {$interestAmount}");
                    }

                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    Log::error("Failed to process interest for LoanNdm #{$loan->id}: " . $e->getMessage(), [
                        'loan_id' => $loan->id,
                        'trace'   => $e->getTraceAsString(),
                    ]);
                }
            }
        });
    }
}
