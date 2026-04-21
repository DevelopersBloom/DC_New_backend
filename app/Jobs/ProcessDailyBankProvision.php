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

    private float $provisionPercent;

    public function __construct(float $provisionPercent = 0.01)
    {
        $this->provisionPercent = $provisionPercent;
    }

    public function handle(): void
    {
        $endOfDay   = Carbon::today()->endOfDay();
        $toDay      = Carbon::today()->format('Y-m-d');
        Log::info("Bank Provision started for {$endOfDay->toDateString()}");

        $bankAccountIds = ChartOfAccount::where('code', 'like', '10210%')->pluck('id');
        if ($bankAccountIds->isEmpty()) {
            Log::error("Bank accounts 10210* not found.");
            return;
        }

        $balanceEnd = $this->calculateBalanceUntil($bankAccountIds, $endOfDay);

        $acc15300PC = ChartOfAccount::idByCode('15300PC');
        if (!$acc15300PC) {
            Log::error("Bank account 15300PC not found.");
            return;
        }

        // Calculate current provision balance
        $balance15300PC = Transaction::where('credit_account_id', $acc15300PC)
                ->where('date', '<=', $toDay)
                ->sum('amount_amd') -
            Transaction::where('debit_account_id', $acc15300PC)
                ->where('date', '<=', $toDay)
                ->sum('amount_amd')
        ;
dd($balance15300PC);
        Log::info("Account: {$acc15300PC}, Balance: {$balance15300PC}, Today: {$toDay}");
        $targetProvision = $balanceEnd * $this->provisionPercent;

        $diff = $targetProvision - $balance15300PC;

        Log::info("Target: {$targetProvision}, Current: {$balance15300PC}, Diff: {$diff}");

        // Use a small epsilon for float comparison to avoid precision issues
        if (abs($diff) < 0.01) {
            Log::info("Provision is already at 1%. Skipping.");
            return;
        }

        DB::beginTransaction();
        try {
            $date = $endOfDay->toDateString();

            if ($diff > 0) {
                $this->createEntry($date, abs($diff), 'Պահուստավորում', '730041', '15300PC');
            } else {
                $this->createEntry($date, abs($diff), 'Ապապահուստավորում', '15300PC', '63102');
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("BankProvision failed: " . $e->getMessage());
            throw $e;
        }
    }
    private function calculateBalanceUntil($accountIds, Carbon $date): float
    {
        $end = $date->toDateTimeString();

        $debit = Transaction::whereIn('debit_account_id', $accountIds)
            ->where('date', '<=', $end)
            ->sum('amount_amd');

        $credit = Transaction::whereIn('credit_account_id', $accountIds)
            ->where('date', '<=', $end)
            ->sum('amount_amd');

        return (float) ($debit - $credit);
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
            'comment'           => $label . ' (Օրվա ' . ($this->provisionPercent * 100) . '%)',
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
