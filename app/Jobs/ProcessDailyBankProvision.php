<?php

namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessDailyBankProvision implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function handle(): void
    {
        $yesterday = Carbon::yesterday()->endOfDay();
        $endOfToday   = Carbon::today()->endOfDay();

        Log::info($yesterday->toDateString(), (array)$endOfToday->toDateString());
        Log::info("ProcessDailyBankProvision started for {$endOfToday->toDateString()}");

        $bankAccountIds = ChartOfAccount::where('code', 'like', '10210%')->pluck('id');

        if ($bankAccountIds->isEmpty()) {
            Log::error("Bank accounts 10210* not found.");
            return;
        }

        $balanceStart = $this->calculateBalanceUntil($bankAccountIds, $yesterday);
        $balanceEnd   = $this->calculateBalanceUntil($bankAccountIds, $endOfToday);


        $netChange = $balanceEnd - $balanceStart;

        Log::info("Start: {$balanceStart}, End: {$balanceEnd}, Change: {$netChange}");

        if ($netChange == 0) {
            Log::info("No change, skipping.");
            return;
        }

        DB::beginTransaction();
        try {
            $date = $endOfToday->toDateString();

            $alreadyExists = Transaction::whereDate('date', $date)
                ->whereIn('document_type', ['Պահուստավորում', 'Ապապահուստավորում'])
                ->first();

            if ($alreadyExists) {
                Log::warning("Provision already exists for {$date}");
                Log::info($alreadyExists->id);
                DB::rollBack();
                return;
            }

            if ($netChange > 0) {
                $amount = round($netChange * 0.01, 2);

                $this->createEntry(
                    $date,
                    $amount,
                    'Պահուստավորում',
                    '730041',
                    '15300PC'
                );
            } else {
                $amount = round(abs($netChange) * 0.01, 2);

                $this->createEntry(
                    $date,
                    $amount,
                    'Ապապահուստավորում',
                    '15300PC',
                    '63102'
                );
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("ProcessDailyBankProvision failed: " . $e->getMessage());
            throw $e;
        }
    }

    private function calculateBalanceUntil($accountIds, Carbon $date): float
    {
        $endOfDate = $date->toDateTimeString();

        $debitSum = Transaction::whereIn('debit_account_id', $accountIds)
            ->where('date', '<=', $endOfDate)
            ->sum('amount_amd');

        $creditSum = Transaction::whereIn('credit_account_id', $accountIds)
            ->where('date', '<=', $endOfDate)
            ->sum('amount_amd');

        return (float) ($debitSum - $creditSum);
    }

    private function createEntry(
        string $date,
        float $amount,
        string $label,
        string $debitCode,
        string $creditCode
    ): void {
        if ($amount <= 0) return;

        $debitAcc  = ChartOfAccount::where('code', $debitCode)->first();
        $creditAcc = ChartOfAccount::where('code', $creditCode)->first();

        if (!$debitAcc || !$creditAcc) {
            Log::error("Accounts not found: {$debitCode} / {$creditCode}");
            return;
        }

        $nextDocNum = DB::transaction(function () {
            return (int)(Transaction::lockForUpdate()->max('document_number') ?? 0) + 1;
        });

        $journal = DocumentJournal::create([
            'date'              => $date,
            'document_type'     => $label,
            'document_number'   => $nextDocNum,
            'amount_amd'        => $amount,
            'debit_account_id'  => $debitAcc->id,
            'credit_account_id' => $creditAcc->id,
            'user_id'           => 1,
            'comment'           => $label . ' (Օրվա փոփոխության 1%)',
            'journalable_type'  => DocumentJournal::class,
            'journalable_id'    => 0,
        ]);

        Transaction::create([
            'date'                 => $date,
            'document_type'        => $label,
            'document_number'      => $nextDocNum,
            'debit_account_id'     => $debitAcc->id,
            'credit_account_id'    => $creditAcc->id,
            'amount_amd'           => $amount,
            'user_id'              => 1,
            'is_system'            => true,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id'   => $journal->id,
        ]);

        Log::info("{$label} created: {$amount}");
    }
}
