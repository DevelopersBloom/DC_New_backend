<?php


namespace App\Jobs;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\ClassificationHistory;
use App\Models\Contract;
use App\Models\ContractReserveHistory;
use App\Models\DocumentJournal;
use App\Models\Modification;
use App\Models\PostingRule;
use App\Models\Transaction;
use App\Services\ClientClassificationService;
use App\Traits\CorrectReserveTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\NotifiesOnFailure;

class UpdateClientClassificationsNew implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use CorrectReserveTrait;
    use NotifiesOnFailure;
    public int $timeout = 1800;

    public function __construct()
    {
    }

    public function handle(ClientClassificationService $service): void
    {
        Log::info('Client classification job started...');

        Client::whereHas('contracts', function (Builder $q) {
            $q->where('status', 'initial');
        })->with(['contracts' => function ($q) {
            $q->where('status', 'initial');
        }, 'classification'])
            ->chunkById(200, function ($clients) use ($service) {

                $acc73015 = ChartOfAccount::idByCode('73015');
                $acc16605PC = ChartOfAccount::idByCode('16605PC');
                $acc16605PS = ChartOfAccount::idByCode('16605PS');
                $acc16200NV = ChartOfAccount::idByCode('16200NV');
                $acc16200 = ChartOfAccount::idByCode('16200');
                $acc16201NI = ChartOfAccount::idByCode('16201NI');
                $acc63015 = ChartOfAccount::idByCode('63015');
                $acc86000 = ChartOfAccount::idByCode('86000');
                $acc86001 = ChartOfAccount::idByCode('86001');

                $diamondId = Client::where('company_name', 'Diamond Credit')->value('id') ?? 1;

                foreach ($clients as $client) {

                    try {
                        Log::info('Processing client', ['id' => $client->id]);

                        DB::beginTransaction();

                        $maxOverdue = $service->maxOverdueDaysForClient($client);
                        $classification = $service->classificationByOverdue($maxOverdue);

                        Log::info("calculated classification is {$classification->id} ");
                        Log::info("calculated maxOverdue is {$maxOverdue} ");

                        if (($client->classification_id === $classification->id) || ($client->classification?->order > $classification->order)) {
                            DB::commit();
                            continue;
                        }

                        // Save old values
                        $oldClassificationName = $client->classification?->name;
                        $oldClassificationId = $client->classification?->id;
                        $oldClassificationOrder = $client->classification?->order;
                        $oldReservePercent = $client->classification?->reserve_percent ?? 0;
                        Log::info("old reserve percent is {$oldReservePercent} ");

                        // Update client classification
                        $client->classification_id = $classification->id;
                        $client->save();
                        $client->load('classification');

                        $newClassificationName = $client->classification?->name;
                        $newReservePercent = $client->classification?->reserve_percent ?? 0;
                        $newRiskWeight = $client->classification?->risk_weight ?? 0;
                        $newClassificationOrder = $client->classification?->order;
                        $clientId = $client->id;

                        $firstContract = $client->contracts->first();
                        $firstJournal = $firstContract
                            ? DocumentJournal::where('journalable_type', Contract::class)
                                ->where('journalable_id', $firstContract->id)
                                ->first()
                            : null;

                        if ($firstJournal) {
                            $targetAccountIds = array_filter([
                                $acc16200NV,
                                $acc16200,
                                $acc16201NI,
                            ]);

                            $this->correctClientReserveBalance(
                                clientId:           $clientId,
                                acc16605PC:         $acc16605PC,
                                acc16605PS:         $acc16605PS,
                                targetAccountIds:   $targetAccountIds,
                                reservePercent:     $client->classification->reserve_percent ?? 0,
                                classificationName: $newClassificationName,
                                diamondId:          $diamondId,
                                journalId:          $firstJournal->id,
                                now:                now()->toDateString(),
                            );
                        }
                        // If new classification is not 'standard' the controller subtracts oldReservePercent:
                        if ($newClassificationName !== 'standard') {
                            $newReservePercent -= $oldReservePercent;
                        }
                        $oldRisk = $oldClassificationOrder !== null ? max(0, min(7, (int)$oldClassificationOrder)) : null;
                        $newRisk = $newClassificationOrder !== null ? max(0, min(7, (int)$newClassificationOrder)) : 0;

                        Modification::create([
                            'subject_type' => Client::class,
                            'subject_id' => $clientId,
                            'modification_type' => 'Modificator',
                            'field_code' => 'RISK',
                            'element_code' => 'Risk',
                            'old_value' => $oldRisk !== null ? (string)$oldRisk : null,
                            'new_value' => (string)$newRisk,
                            'effective_date' => now()->toDateString(),
                        ]);
                        // Loop contracts
                        foreach ($client->contracts as $contract) {

                            $journal = DocumentJournal::where('journalable_type', Contract::class)
                                ->where('journalable_id', $contract->id)
                                ->first();

                            // compute existing reserve amounts tied to that journal (if any)
                            $reserveAmount = 0;
                            $amount16605PC = 0;
                            $amount16605PS = 0;

                            if ($journal) {
                                // sum previous reserve special/general amounts (some code paths used different fields)
                                $reserveAmount = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where(function ($q) {
                                        $q->where('document_type', DocumentJournal::RESERVE_SPECIAL_AMOUNT)
                                            ->orWhere('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT)
                                            ->orWhere('document_type', DocumentJournal::EFFECTIVE_RATE_AMOUNT);
                                    })
                                    ->sum('amount_amd');

                                // sum old 16605PC credits (used later for reversing old 'standard' classification)
                                $amount16605PC = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('document_type', DocumentJournal::RESERVE_GENERAL_AMOUNT)
                                    ->where('credit_account_id', $acc16605PC)
                                    ->sum('amount_amd');

                                $amount16605PS = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16605PS)
                                    ->sum('amount_amd');
                            }

                            // controller logic: amount is (provided + reserveAmount) * newReservePercent/100
                            $amount = ($contract->provided_amount + $reserveAmount) * ($newReservePercent / 100);
                            $oldReserveAmount = ($contract->provided_amount + $reserveAmount) * ($oldReservePercent / 100);

                            // record reserve history (similar to controller)
                            ContractReserveHistory::create([
                                'client_id' => $client->id,
                                'classification_id' => $classification->id,
                                'contract_id' => $contract->id,
                                'risk_weight' => $newRiskWeight,
                                'reserve_percent' => $newReservePercent,
                                'reserve_amount' => $amount,
                                'total_reserve_amount' => $amount,
                                'provided_amount' => $contract->provided_amount,
                                'date' => now()->toDateString(),
                                'user_id' => auth()->check() ? auth()->id() : 1,
                                'meta' => [
                                    'old_reserve_percent' => $oldReservePercent,
                                    'old_reserve_amount' => $oldReserveAmount,
                                ],
                            ]);

                            Log::info("calculated reserve amount for contract {$contract->id}: {$amount}");

                            if ($amount <= 0 && $client->classification->name !== 'loss') {
                                // nothing to post (controller continued)
                                continue;
                            }

                            // accounts and document types depending on classification name
                            if ($client->classification->name === 'standard') {

                                $ruleReserve = PostingRule::where('business_event_filter', 'reserve_general_amount')
                                    ->first();

                                if (!$ruleReserve) {
                                    throw new \RuntimeException('Posting rule for reserve_general_amount not found');
                                }

                                $debitReserve = $ruleReserve->debit_account_id;
                                $creditReserve = $ruleReserve->credit_account_id;
                            } else {
                                $ruleReserve = PostingRule::where('business_event_filter', 'reserve_special_amount')
                                    ->first();

                                if (!$ruleReserve) {
                                    throw new \RuntimeException('Posting rule for reserve_special_amount not found');
                                }

                                $debitReserve = $ruleReserve->debit_account_id;
                                $creditReserve = $ruleReserve->credit_account_id;
                            }
                            if ($oldClassificationName === 'standard') {
                                $ruleClassification = PostingRule::where('business_event_filter', 'classification_general_to_special')->firstOrFail();
                            } else {
                                $ruleClassification = PostingRule::where('business_event_filter', 'classification_special_to_general')->firstOrFail();
                            }
                            $debitClassification = $ruleClassification->debit_account_id;
                            $creditClassification = $ruleClassification->credit_account_id;

                            $documentType = $client->classification->name === 'standard'
                                ? DocumentJournal::RESERVE_GENERAL_AMOUNT
                                : DocumentJournal::RESERVE_SPECIAL_AMOUNT;

                            // require a base journal to attach DocumentJournal -> if none, skip (controller did similar)
                            if (!$journal) {
                                continue;
                            }

                            // create reserve document journal
                            $docJournal = null;
                            if ($amount > 0) {
                                $nextDocNum = Transaction::getNextDocumentNumber();
                                $docJournal = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => $documentType,
                                    'amount_amd' => $amount,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Reserve for contract #{$contract->id} due to classification change",
                                    'debit_account_id' => $debitReserve,
                                    'credit_account_id' => $creditReserve,
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'journalable_type' => DocumentJournal::class,
                                    'journalable_id' => $journal->id,
                                ]);

                                Transaction::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => $documentType,
                                    'debit_account_id' => $debitReserve,
                                    'debit_currency_id' => 1,
                                    'credit_account_id' => $creditReserve,
                                    'credit_currency_id' => 1,
                                    'credit_partner_id' => $clientId,
                                    'amount_amd' => $amount,
                                    'comment' => "Reserve for contract #{$contract->id}",
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'is_system' => true,
                                    'disbursement_date' => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $docJournal->id,
                                ]);
                            }

                            ClassificationHistory::create([
                                'client_id' => $client->id,
                                'classification_id' => $classification->id,
                                'risk_weight' => $newRiskWeight,
                                'reserve_percent' => $client->classification?->reserve_percent ?? 0,
                                'comment' => 'Automatic client classification update based on overdue days',
                                'actionable_type' => DocumentJournal::class,
                                'actionable_id' => $docJournal?->id ?? $journal->id,
                                'user_id' => auth()->check() ? auth()->id() : 1,
                                'meta' => [
                                    'old_classification_id' => $oldClassificationId,
                                    'old_classification_name' => $oldClassificationName,
                                    'old_reserve_percent' => $oldReservePercent,
                                    'old_reserve_amount' => $oldReserveAmount,
                                ],
                                'date' => now(),
                            ]);

                            // If new classification is 'loss' — write off all outstanding balances
                            if ($client->classification->name === 'loss') {


                                // Net 16200 (effective interest receivable): debit minus credit
                                $debit16200 = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('debit_account_id', $acc16200)
                                    ->sum('amount_amd');
                                $credit16200 = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16200)
                                    ->sum('amount_amd');
                                $net16200 = $debit16200 - $credit16200;

                                // Net 16200NV (nominal principal): debit from contract minus credits already posted
                                $debit16200NV = DocumentJournal::where('journalable_type', Contract::class)
                                    ->where('journalable_id', $contract->id)
                                    ->where('debit_account_id', $acc16200NV)
                                    ->sum('amount_amd');
                                $credit16200NV = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16200NV)
                                    ->sum('amount_amd');
                                $net16200NV = $debit16200NV - $credit16200NV;

                                // Net 16201NI (accrued nominal interest): debit minus credit
                                $debit16201NI = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('debit_account_id', $acc16201NI)
                                    ->sum('amount_amd');
                                $credit16201NI = DocumentJournal::where('journalable_type', DocumentJournal::class)
                                    ->where('journalable_id', $journal->id)
                                    ->where('credit_account_id', $acc16201NI)
                                    ->sum('amount_amd');
                                $net16201NI = $debit16201NI - $credit16201NI;

                                // ── Step 1: Net balance transfer ──────────────────────
                                // Dr: Net Balance of (16200 + 16200NV + 16201NI) / Cr: 16605PS
                                $totalNet = round($net16200 + $net16200NV + $net16201NI, 2);
                                $currentPS = $this->getClientReserveBalance($clientId, $acc16605PS);
                                $diff = round($totalNet + $currentPS, 2);
                                if (abs($diff) >= 0.01) {
                                    $ruleStep1 = PostingRule::where('business_event_filter', 'loss_writeoff_net_transfer')->firstOrFail();
                                    $nextDocNum = Transaction::getNextDocumentNumber();
                                    $debitAcc  = $diff > 0 ? $ruleStep1->debit_account_id : $acc16605PS;
                                    $creditAcc = $diff > 0 ? $acc16605PS : $ruleStep1->debit_account_id;
                                    $step1Doc = DocumentJournal::create([
                                        'date'             => now()->toDateString(),
                                        'document_number'  => $nextDocNum,
                                        'document_type'    => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                                        'amount_amd'       => abs($diff),
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id'=> $clientId,
                                        'comment'          => "Loss write-off net balance transfer for contract #{$contract->id}",
                                        'debit_account_id' => $debitAcc,
                                        'credit_account_id'=> $creditAcc,
                                        'user_id'          => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id'   => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date'                 => now()->toDateString(),
                                        'document_number'      => $nextDocNum,
                                        'document_type'        => DocumentJournal::LOSS_WRITEOFF_NET_TRANSFER,
                                        'debit_account_id'     => $debitAcc,
                                        'debit_partner_id'     => $clientId,
                                        'debit_currency_id'    => 1,
                                        'credit_account_id'    => $creditAcc,
                                        'credit_currency_id'   => 1,
                                        'credit_partner_id'    => $clientId,
                                        'amount_amd'           => abs($diff),
                                        'comment'              => "Loss write-off net balance transfer for contract #{$contract->id}",
                                        'user_id'              => auth()->check() ? auth()->id() : 1,
                                        'is_system'            => true,
                                        'disbursement_date'    => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id'   => $step1Doc->id,
                                    ]);
                                }

                                // Entry 1: zero out 16200
                                if (abs($net16200) >= 0.01) {
                                    $nextDocNum = Transaction::getNextDocumentNumber();
                                    $dAcc16200 = $net16200 > 0 ? $acc16605PS : $acc16200;
                                    $cAcc16200 = $net16200 > 0 ? $acc16200   : $acc16605PS;
                                    $lossEff16200Doc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                                        'amount_amd' => abs($net16200),
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Write-off 16200 for contract #{$contract->id} - loss classification",
                                        'debit_account_id' => $dAcc16200,
                                        'credit_account_id' => $cAcc16200,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_EFFECTIVE,
                                        'debit_account_id' => $dAcc16200,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $cAcc16200,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => abs($net16200),
                                        'comment' => "Write-off 16200 for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $lossEff16200Doc->id,
                                    ]);
                                }

                                // Entry 2: zero out 16200NV
                                if (abs($net16200NV) >= 0.01) {
                                    $nextDocNum = Transaction::getNextDocumentNumber();
                                    $dAcc16200NV = $net16200NV > 0 ? $acc16605PS : $acc16200NV;
                                    $cAcc16200NV = $net16200NV > 0 ? $acc16200NV : $acc16605PS;
                                    $lossNVDoc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                        'amount_amd' => abs($net16200NV),
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Write-off 16200NV for contract #{$contract->id} - loss classification",
                                        'debit_account_id' => $dAcc16200NV,
                                        'credit_account_id' => $cAcc16200NV,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                        'debit_account_id' => $dAcc16200NV,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $cAcc16200NV,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => abs($net16200NV),
                                        'comment' => "Write-off 16200NV for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $lossNVDoc->id,
                                    ]);
                                }

                                // Entry 3: Dr 86000 — combined net of 16200 + 16200NV
                                $amount86000 = $net16200 + $net16200NV;
                                if (abs($amount86000) >= 0.01) {
                                    $rule86000 = PostingRule::where('business_event_filter', 'loss_writeoff_principal')->first();
                                    if (!$rule86000) {
                                        throw new \RuntimeException('Posting rule for loss_writeoff_principal not found');
                                    }
                                    $nextDocNum = Transaction::getNextDocumentNumber();
                                    $dAcc86000 = $amount86000 > 0 ? $rule86000->debit_account_id : $rule86000->credit_account_id;
                                    $cAcc86000 = $amount86000 > 0 ? $rule86000->credit_account_id : $rule86000->debit_account_id;
                                    $loss86000Doc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                        'amount_amd' => abs($amount86000),
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Loss write-off expense 86000 for contract #{$contract->id}",
                                        'debit_account_id' => $dAcc86000,
                                        'credit_account_id' => $cAcc86000,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE_AMOUNT,
                                        'debit_account_id' => $dAcc86000,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $cAcc86000,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => abs($amount86000),
                                        'comment' => "Loss write-off expense 86000 for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $loss86000Doc->id,
                                    ]);
                                }

                                // Entry 4: zero out 16201NI
                                if (abs($net16201NI) >= 0.01) {
                                    $nextDocNum = Transaction::getNextDocumentNumber();
                                    $dAcc16201NI = $net16201NI > 0 ? $acc16605PS : $acc16201NI;
                                    $cAcc16201NI = $net16201NI > 0 ? $acc16201NI : $acc16605PS;
                                    $lossNIDoc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'amount_amd' => abs($net16201NI),
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Write-off 16201NI for contract #{$contract->id} - loss classification",
                                        'debit_account_id' => $dAcc16201NI,
                                        'credit_account_id' => $cAcc16201NI,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'debit_account_id' => $dAcc16201NI,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $cAcc16201NI,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => abs($net16201NI),
                                        'comment' => "Write-off 16201NI for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $lossNIDoc->id,
                                    ]);
                                }

                                // Entry 5: Dr 86001 — same amount as 16201NI net
                                if (abs($net16201NI) >= 0.01) {
                                    $rule86001 = PostingRule::where('business_event_filter', 'loss_writeoff_interest')->first();
                                    if (!$rule86001) {
                                        throw new \RuntimeException('Posting rule for loss_writeoff_interest not found');
                                    }
                                    $nextDocNum = Transaction::getNextDocumentNumber();
                                    $dAcc86001 = $net16201NI > 0 ? $rule86001->debit_account_id : $rule86001->credit_account_id;
                                    $cAcc86001 = $net16201NI > 0 ? $rule86001->credit_account_id : $rule86001->debit_account_id;
                                    $loss86001Doc = DocumentJournal::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'amount_amd' => abs($net16201NI),
                                        'debit_partner_id' => $clientId,
                                        'credit_partner_id' => $clientId,
                                        'comment' => "Loss write-off expense 86001 for contract #{$contract->id}",
                                        'debit_account_id' => $dAcc86001,
                                        'credit_account_id' => $cAcc86001,
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'journalable_type' => DocumentJournal::class,
                                        'journalable_id' => $journal->id,
                                    ]);
                                    Transaction::create([
                                        'date' => now()->toDateString(),
                                        'document_number' => $nextDocNum,
                                        'document_type' => DocumentJournal::LOSS_RESERVE,
                                        'debit_account_id' => $dAcc86001,
                                        'debit_partner_id' => $clientId,
                                        'debit_currency_id' => 1,
                                        'credit_account_id' => $cAcc86001,
                                        'credit_currency_id' => 1,
                                        'credit_partner_id' => $clientId,
                                        'amount_amd' => abs($net16201NI),
                                        'comment' => "Loss write-off expense 86001 for contract #{$contract->id}",
                                        'user_id' => auth()->check() ? auth()->id() : 1,
                                        'is_system' => true,
                                        'disbursement_date' => now()->toDateString(),
                                        'transactionable_type' => DocumentJournal::class,
                                        'transactionable_id' => $loss86001Doc->id,
                                    ]);
                                }
                            } // end if loss

                            // If old reserve existed and old classification was 'standard' — create classification reversal doc
                            if (!empty($amount16605PC) && $oldClassificationName === 'standard') {
                                $classificationType = DocumentJournal::CLASSIFICATION;

                                $nextDocNum = Transaction::getNextDocumentNumber();
                                $classificationDoc = DocumentJournal::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => $classificationType,
                                    'amount_amd' => $amount16605PC,
                                    'debit_partner_id' => $clientId,
                                    'credit_partner_id' => $clientId,
                                    'comment' => "Old reserve for contract #{$contract->id} due to classification change",
                                    'debit_account_id' => $debitClassification,
                                    'credit_account_id' => $creditClassification,
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'journalable_type' => DocumentJournal::class,
                                    'journalable_id' => $journal->id,
                                ]);

                                Transaction::create([
                                    'date' => now()->toDateString(),
                                    'document_number' => $nextDocNum,
                                    'document_type' => $classificationType,
                                    'debit_account_id' => $debitClassification,
                                    'debit_partner_id' => $clientId,
                                    'debit_currency_id' => 1,
                                    'credit_account_id' => $creditClassification,
                                    'credit_currency_id' => 1,
                                    'credit_partner_id' => $clientId,
                                    'amount_amd' => $amount16605PC,
                                    'comment' => "Old reserve for contract #{$contract->id}",
                                    'user_id' => auth()->check() ? auth()->id() : 1,
                                    'is_system' => true,
                                    'disbursement_date' => now()->toDateString(),
                                    'transactionable_type' => DocumentJournal::class,
                                    'transactionable_id' => $classificationDoc->id,
                                ]);
                            }

                            Log::info("Finished processing contract {$contract->id} for client {$client->id}.");
                        } // end foreach contracts

                        DB::commit();
                    } catch (\Throwable $e) {
                        DB::rollBack();
                        Log::error("Failed to update client #{$client->id}: " . $e->getMessage());
                        $this->notifyAdmins($e);
                    }
                }
            });

        Log::info('Client classification job finished.');
    }
}
