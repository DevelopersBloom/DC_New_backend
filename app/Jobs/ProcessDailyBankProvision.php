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
        $startOfDay = Carbon::yesterday()->endOfDay();
        $endOfDay   = Carbon::today()->endOfDay();

        Log::info("Bank Provision started for {$endOfDay->toDateString()}");

        $bankAccountIds = ChartOfAccount::where('code', 'like', '10210%')->pluck('id');

        if ($bankAccountIds->isEmpty()) {
            Log::error("Bank accounts 10210* not found.");
            return;
        }

        $balanceStart = $this->calculateBalanceUntil($bankAccountIds, $startOfDay);
        $balanceEnd   = $this->calculateBalanceUntil($bankAccountIds, $endOfDay);

        $netChange = $balanceEnd - $balanceStart;

        Log::info("Start: {$balanceStart}, End: {$balanceEnd}, Change: {$netChange}");

        if ($netChange == 0) {
            Log::info("No change, skipping.");
            return;
        }

        DB::beginTransaction();

        try {
            $date = $endOfDay->toDateString();

            if ($netChange > 0) {
                $amount = round($netChange * $this->provisionPercent, 2);

                $this->createOrUpdateEntry(
                    $date,
                    $amount,
                    'Պահուստավորում',
                    '730041',
                    '15300PC'
                );
            } else {
                $amount = round(abs($netChange) * $this->provisionPercent, 2);

                $this->createOrUpdateEntry(
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

    private function createOrUpdateEntry(
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

        $existing = Transaction::whereDate('date', $date)
            ->whereIn('document_type', ['Ապապահուստավորում','Պահուստավորում'])
            ->lockForUpdate()
            ->first();

        if ($existing) {

            $existing->update([
                'amount_amd'        => $amount,
                'debit_account_id'  => $debitAcc->id,
                'credit_account_id' => $creditAcc->id,
                'document_type'     => $label,

            ]);

            DocumentJournal::where('id', $existing->transactionable_id)
                ->update([
                    'amount_amd'        => $amount,
                    'debit_account_id'  => $debitAcc->id,
                    'credit_account_id' => $creditAcc->id,
                    'comment'           => $label . ' (Թարմացված % ' . ($this->provisionPercent * 100) . ')',
                    'document_type'     => $label,

                ]);

            Log::info("{$label} updated: {$amount}");
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
