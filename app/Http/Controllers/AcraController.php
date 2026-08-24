<?php

namespace App\Http\Controllers;

use App\Exports\Acra\AcraExport;
use App\Models\Client;
use App\Models\Contract;
use App\Models\DocumentJournal;
use App\Models\Payment;
use App\Models\Prepayment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $calcDay = Carbon::parse($request->to_date)->subDay()->format('Y-m-d');
        // Match the "overdue" contract scope (Contract::scopeStatus): only count a
        // contract as overdue once its unpaid initial payments exceed 1000 AMD.
        // "Unpaid" is net of payment_entries: a status='initial' row can already be
        // mostly settled by a partial payment (which never flips status to
        // completed), so the raw `amount` column alone overstates what's still due.
        // Rows with no entries (legacy/imported payments) net to their full amount,
        // same as before this change. Entries are scoped to date < $to so a payment
        // recorded after the report's cutoff doesn't retroactively clear a contract
        // out of a report window that's supposed to stop before it.
        // Net against principal_payment/interest_payment (not the aggregate `amount`
        // column): `amount` is a schedule-time snapshot that can drift out of sync
        // with the split fields after recalculation, while principal_payment/
        // interest_payment are the figures kept authoritative everywhere else
        // (fillCredit's own K/L overdue calc, ContractDetailResource, etc). Using
        // `amount` here let a contract in past its 1000 AMD threshold pull the
        // whole client into the report while K/L displayed 0, because the two
        // checks disagreed about what "still owed" means.
        $contractsWithInitialPayments = Payment::where('status', 'initial')
            ->where('date', '<', $calcDay)
            ->withSum(['entries as paid_principal' => fn ($q) => $q->where('date', '<', $to)], 'principal_amount')
            ->withSum(['entries as paid_interest' => fn ($q) => $q->where('date', '<', $to)], 'interest_amount')
            ->get(['id', 'contract_id', 'principal_payment', 'interest_payment'])
            ->groupBy('contract_id')
            ->filter(function ($payments) {
                $remaining = $payments->sum(function ($p) {
                    $principalDue = max(0, (float) $p->principal_payment - (float) ($p->paid_principal ?? 0));
                    $interestDue  = max(0, (float) $p->interest_payment - (float) ($p->paid_interest ?? 0));
                    return $principalDue + $interestDue;
                });
                return $remaining > AcraExport::MIN_OVERDUE_AMD;
            })
            ->keys()
            ->toArray();
        // Manual exclusion: client 4 (Գրիշա Հունեյան) must not appear in the ACRA export.
        $excludedClientIds = [4];

        // A contract whose only activity this period was an on-time interest payment
        // doesn't belong in the report on that basis alone (see the journal-type list
        // below); but one currently carrying overdue interest still needs to be pulled
        // in even without a matching journal action this period. Reuses AcraExport's
        // column-L calc so this inclusion check and what fillCredit prints stay in sync.
        $contractsWithOverdueInterest = Contract::whereNotNull('provided_at')
            ->whereNotIn('client_id', $excludedClientIds)
            ->get(['id', 'client_id', 'payment_type', 'deadline', 'provided_amount'])
            ->filter(function ($contract) use ($from, $to) {
                [, $overdueInterest] = AcraExport::overdueAmounts($contract, $from, $to);
                return $overdueInterest > 0;
            })
            ->pluck('id')
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
                // Principal repayments only — an on-time interest payment alone
                // shouldn't pull a contract into the report (see
                // $contractsWithOverdueInterest above for the overdue-interest case).
                DocumentJournal::PAY_MOTHER_AMOUNT,
                DocumentJournal::PAY_MOTHER_AMOUNT_CASH,
                // Full repayments write the posting-rule key as document_type,
                // so without this a fully repaid loan disappears from the export.
                DocumentJournal::PAY_MOTHER_AMOUNT_TRANSFER,
                // A re-provide (additional disbursement on an already-existing
                // contract) posts another PROVIDE_CONTRACT_AMOUNT journal but
                // never changes contract.date, so without this a contract
                // re-provided inside the window - its own row untouched - was
                // invisible to every other trigger here.
                DocumentJournal::PROVIDE_CONTRACT_AMOUNT,
            ])
            ->where('date', '>=', $from)
            ->where('date', '<', $to)
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

        // A prepayment counts from the date it was received (created_at) but only
        // once it's actually been applied (paid_at set) - same convention column E
        // already uses (see AcraExport::fillCredit's $lastPrepaymentReceived). A
        // contract whose only activity this period is a still-unpaid prepayment
        // receipt doesn't belong in the report yet; it's pulled in once
        // MarkDuePrepaymentsPaid applies it, whether that happens inside this
        // window or later.
        $contractsWithPrepayments = Prepayment::where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->whereNotNull('paid_at')
            ->pluck('contract_id')
            ->unique()
            ->toArray();

        $contracts = Contract::with(['client.classification', 'guarantors', 'items'])
            ->whereNotNull('provided_at')
            ->whereNotIn('client_id', $excludedClientIds)
            ->where(function($query) use ($from, $to, $contractsWithInitialPayments, $contractsWithJournalActions, $contractsWithPrepayments, $contractsWithOverdueInterest) {
                // Upper bound is exclusive: the classification snapshot (see
                // AcraExport::fillCredit) is taken as of $to - 1 day, so a
                // contract dated exactly on $to has no classification history
                // yet and shouldn't be pulled into this report.
                $query->where('date', '>=', $from)
                    ->where('date', '<', $to)
                    ->orWhereIn('id', $contractsWithInitialPayments)
                    ->orWhereIn('id', $contractsWithJournalActions)
                    ->orWhereIn('id', $contractsWithPrepayments)
                    ->orWhereIn('id', $contractsWithOverdueInterest);
            })->get();

        $updatedClientIds = Client::whereBetween('updated_at', [$from, $to])->pluck('id')->toArray();
        $contractClientIds = $contracts->pluck('client_id')->toArray();
        $contractIds = $contracts->pluck('id')->toArray();
        $allClientIds = array_unique(array_merge($contractClientIds, $updatedClientIds));
        $allClients = Client::whereIn('id', $allClientIds)->whereNotIn('id', $excludedClientIds)->get();
        $acraExport = new AcraExport($contracts, $allClients, $from, $to);
        $fileData = $acraExport->export();

        return response()->download($fileData['path'], $fileData['name'])->deleteFileAfterSend(true);
    }
}
