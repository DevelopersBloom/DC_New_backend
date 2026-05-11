<?php

namespace App\Traits;

use App\Models\DocumentJournal;
use App\Models\PostingRule;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

trait CorrectReserveTrait
{
    use CalculatesAccountBalancesTrait;
    private function correctClientReserveBalance(
        int    $clientId,
        int    $acc16605PC,
        int    $acc16605PS,
        array  $targetAccountIds,
        float  $reservePercent,
        string $classificationName,
        int    $diamondId,
        ?int   $journalId,
        string $now,
    ): void {
        $accountsSum = $this->getClientAccountsBalance($clientId, $targetAccountIds, $now);
        $targetAmount = round($accountsSum * ($reservePercent / 100), 2);

        $balance16605PC = $this->getClientReserveBalance($clientId, $acc16605PC, $now);
        $balance16605PS = $this->getClientReserveBalance($clientId, $acc16605PS, $now);

        Log::info("correctClientReserveBalance client#{$clientId}", [
            'accountsSum'     => $accountsSum,
            'targetAmount'    => $targetAmount,
            'balance16605PC'  => $balance16605PC,
            'balance16605PS'  => $balance16605PS,
            'classification'  => $classificationName,
        ]);

        if ($classificationName === 'standard') {
            // ── STANDARD ────────────────────────────────────────────────────
            // 16605PC → targetAmount

            $diffPC = round((-$targetAmount) - $balance16605PC, 2);
            if (abs($diffPC) >= 0.01) {
                $rule = $diffPC > 0 ? PostingRule::where('business_event_filter', 'provide_general_amount_change')->first()
                                    : PostingRule::where('business_event_filter', 'reserve_general_amount')->first();

//                16605PC, 63015
//                73015, 16605PC,
                $debit = $rule->debit_account_id;
                $credit = $rule->credit_account_id;

                $debitPartnerId = $diffPC > 0 ? $clientId : null;
                $creditPartnerId = $diffPC > 0 ? null : $clientId;

                $this->postCorrectionEntry(
                    clientId:       $clientId,
                    debitPartnerId: $debitPartnerId,
                    creditPartnerId:$creditPartnerId,
                    debitAccountId: $debit,
                    creditAccountId:$credit,
                    amount:         abs($diffPC),
                    documentType:   DocumentJournal::RESERVE_GENERAL_AMOUNT,
                    comment:        "Reserve correction (standard) for client #{$clientId}",
                    journalId:      $journalId,
                    now:            $now,
                );
            }

            // 16605PS → 0
            if (abs($balance16605PS) >= 0.01) {
                $rule = PostingRule::where('business_event_filter', 'provide_special_amount_change')->first();
                [$debit, $credit] = $balance16605PS > 0
                    ?[$rule->credit_account_id, $rule->debit_account_id]
                    : [$rule->debit_account_id, $rule->credit_account_id];
                $debitClientId = $balance16605PS > 0 ? null : $clientId;
                $creditClientId = $balance16605PS > 0 ? $clientId : null;
                $this->postCorrectionEntry(
                    clientId:       $clientId,
                    debitPartnerId: $debitClientId,
                    creditPartnerId:$creditClientId,
                    debitAccountId: $debit,
                    creditAccountId:$credit,
                    amount:         abs($balance16605PS),
                    documentType:   DocumentJournal::RESERVE_SPECIAL_AMOUNT,
                    comment:        "Zero 16605PS (standard correction) for client #{$clientId}",
                    journalId:      $journalId,
                    now:            $now,
                );
            }
        } else {
            // ── NON-STANDARD ─────────────────────────────────────────────────
            // 16605PC → 0
            if (abs($balance16605PC) >= 0.01) {

                $rule = PostingRule::where('business_event_filter', 'provide_general_amount_change')->first();

                //$acc16605PC, 63015
                [$debit, $credit] = $balance16605PC > 0
                    ?[$rule->credit_account_id, $rule->debit_account_id]
                    : [$rule->debit_account_id, $rule->credit_account_id];
                $debitClientId = $balance16605PC > 0 ? null : $clientId;
                $creditClientId = $balance16605PC > 0 ? $clientId : null;

                $this->postCorrectionEntry(
                    clientId:       $clientId,
                    debitPartnerId: $debitClientId,
                    creditPartnerId:$creditClientId,
                    debitAccountId: $debit,
                    creditAccountId:$credit,
                    amount:         abs($balance16605PC),
                    documentType:   DocumentJournal::RESERVE_GENERAL_AMOUNT,
                    comment:        "Zero 16605PC (non-standard correction) for client #{$clientId}",
                    journalId:      $journalId,
                    now:            $now,
                );
            }

            // 16605PS → targetAmount
            $diffPS = round((-$targetAmount) - $balance16605PS, 2);
            Log::info("diffPS: {$diffPS}, targetAmount: {$targetAmount}, docNum: {$nextDocNum},balance16605PS : {$balance16605PS}");

            //100,
            //-100
            if (abs($diffPS) >= 0.01) {

                $rule = $diffPS > 0 ? PostingRule::where('business_event_filter', 'provide_special_amount_change')->first()
                    : PostingRule::where('business_event_filter', 'reserve_special_amount')->first();
//-100,-90
//                , 16605PS,63015
//                73015, $acc16605PS,

                $debit = $rule->credit_account_id;
                $credit = $rule->debit_account_id;
//                $rule = PostingRule::where('business_event_filter', 'reserve_special_amount')->first();
//
//                [$debit, $credit] = $diffPS > 0
//                    ? [$rule->credit_account_id, $rule->debit_account_id]
//                    : [$rule->debit_account_id, $rule->credit_account_id];

                $this->postCorrectionEntry(
                    clientId:       $clientId,
                    debitPartnerId: $diffPS > 0 ? $clientId : null,
                    creditPartnerId:$diffPS > 0 ? null : $clientId,
                    debitAccountId: $credit,
                    creditAccountId: $debit,
                    amount:         abs($diffPS),
                    documentType:   DocumentJournal::RESERVE_SPECIAL_AMOUNT,
                    comment:        "Reserve correction (non-standard) for client #{$clientId}",
                    journalId:      $journalId,
                    now:            $now,
                );
            }
        }
    }


    private function postCorrectionEntry(
        int    $clientId,
        ?int    $debitPartnerId,
        ?int    $creditPartnerId,
        int    $debitAccountId,
        int   $creditAccountId,
        float  $amount,
        string $documentType,
        string $comment,
        ?int   $journalId,
        string $now,
    ): void {
        if ($amount < 0.01 || !$journalId) return;

        $nextDocNum = Transaction::getNextDocumentNumber();

        $doc = DocumentJournal::create([
            'date'              => $now,
            'document_number'   => $nextDocNum,
            'document_type'     => $documentType,
            'amount_amd'        => $amount,
            'debit_partner_id'  => $debitPartnerId,
            'credit_partner_id' => $creditPartnerId,
            'debit_account_id'  => $debitAccountId,
            'credit_account_id' => $creditAccountId,
            'comment'           => $comment,
            'user_id'           => auth()->id() ?? 1,
            'journalable_type'  => DocumentJournal::class,
            'journalable_id'    => $journalId,
        ]);

        Transaction::create([
            'date'                 => $now,
            'document_number'      => $nextDocNum,
            'document_type'        => $documentType,
            'debit_account_id'     => $debitAccountId,
            'debit_partner_id'     => $debitPartnerId,
            'debit_currency_id'    => 1,
            'credit_account_id'    => $creditAccountId,
            'credit_currency_id'   => 1,
            'credit_partner_id'    => $creditPartnerId,
            'amount_amd'           => $amount,
            'comment'              => $comment,
            'user_id'              => auth()->id() ?? 1,
            'is_system'            => true,
            'disbursement_date'    => $now,
            'transactionable_type' => DocumentJournal::class,
            'transactionable_id'   => $doc->id,
        ]);

        Log::info("Correction posted: {$comment}, amount: {$amount}, docNum: {$nextDocNum}");
    }
}
