<?php

namespace App\Http\Controllers;

use App\Exports\Acra\AcraExport;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Payment;
use Illuminate\Http\Request;
use PhpParser\Comment\Doc;

class AcraController
{
    public function downloadAcraReport(Request $request)
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date',
        ]);

        $from = $request->from_date;
        $to = $request->to_date;

        // Match the "overdue" contract scope (Contract::scopeStatus): only count a
        // contract as overdue once its unpaid initial payments exceed 1000 AMD.
        $contractsWithInitialPayments = Payment::where('status', 'initial')
            ->where('date', '<', $from)
            ->groupBy('contract_id')
            ->havingRaw('SUM(amount) > ?', [AcraExport::MIN_OVERDUE_AMD])
            ->pluck('contract_id')
            ->toArray();
        $mainContractJournals = DocumentJournal::where('journalable_type', Contract::class)
            ->where('document_type', DocumentJournal::PROVIDE_CONTRACT_AMOUNT)
            ->select('id', 'journalable_id')
            ->get();

        $journalToContractMap = $mainContractJournals->pluck('journalable_id', 'id');
        $mainJournalIds = $mainContractJournals->pluck('id')->toArray();
        $mainContractsIds = $mainContractJournals->pluck('journalable_id')->toArray();

        $contractsWithJournalActions = DocumentJournal::where(function ($query) use ($mainJournalIds, $mainContractsIds) {

            $query->where(function ($q) use ($mainJournalIds) {
                $q->where('journalable_type', DocumentJournal::class)
                    ->whereIn('journalable_id', $mainJournalIds);
            })
                ->orWhere(function ($q) use ($mainContractsIds) {
                    $q->where('journalable_type', Contract::class)
                        ->whereIn('journalable_id', $mainContractsIds);
                });

        })
            ->whereIn('document_type', [
                DocumentJournal::PAY_MOTHER_AMOUNT,
                DocumentJournal::INTEREST_REPAYMENT,
                DocumentJournal::PAY_MOTHER_AMOUNT_CASH,
                // Full repayments write the posting-rule key as document_type,
                // so without these a fully repaid loan disappears from the export.
                DocumentJournal::PAY_MOTHER_AMOUNT_TRANSFER,
                DocumentJournal::PAY_INTEREST_AMOUNT_TRANSFER,
                DocumentJournal::PAY_INTEREST_AMOUNT_CASH,
            ])
            ->where('date' >= $from)
            ->where('date' < $to)
//            ->whereBetween('date', [$from, $to])
            ->get()
            ->map(function ($journal) use ($journalToContractMap) {
                if ($journal->journalable_type === DocumentJournal::class) {
                    return $journalToContractMap[$journal->journalable_id] ?? null;
                }

                if ($journal->journalable_type === Contract::class) {
                    return $journal->journalable_id;
                }
                return null;
            })
            ->filter()
            ->unique()
            ->toArray();

        $contracts = Contract::with(['client.classification', 'guarantors', 'items'])
            ->whereNotNull('provided_at')
            ->where(function($query) use ($from, $to, $contractsWithInitialPayments, $contractsWithJournalActions) {
                // Upper bound is exclusive: the classification snapshot (see
                // AcraExport::fillCredit) is taken as of $to - 1 day, so a
                // contract dated exactly on $to has no classification history
                // yet and shouldn't be pulled into this report.
                $query->where('date', '>=', $from)
                    ->where('date', '<', $to)
                    ->orWhereIn('id', $contractsWithInitialPayments)
                    ->orWhereIn('id', $contractsWithJournalActions);
            })->get();

        $updatedClientIds = Client::whereBetween('updated_at', [$from, $to])->pluck('id')->toArray();
        $contractClientIds = $contracts->pluck('client_id')->toArray();
        $allClientIds = array_unique(array_merge($contractClientIds, $updatedClientIds));
        $allClients = Client::whereIn('id', $allClientIds)->get();
        dd($contractClientIds);

        $acraExport = new AcraExport($contracts, $allClients, $from, $to);
        $fileData = $acraExport->export();

        return response()->download($fileData['path'], $fileData['name'])->deleteFileAfterSend(true);
    }
}
