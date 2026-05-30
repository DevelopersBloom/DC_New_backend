<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DocumentJournal;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class DealScopedDeleteService
{
    public function delete(Deal $deal, array $scopes): void
    {
        DB::transaction(function () use ($deal, $scopes) {
            $deal = Deal::findOrFail($deal->id);

            if (!empty($scopes['accounting']) || !empty($scopes['documents_journal']) || !empty($scopes['transactions'])) {
                $this->deleteAccountingForDeal($deal);
            }

            if (!empty($scopes['deal'])) {
                if (!$deal->trashed()) {
                    $deal->delete();
                }
            }
        });
    }

    private function deleteAccountingForDeal(Deal $deal): void
    {
        $journalIds = DocumentJournal::where('deal_id', $deal->id)->pluck('id');

        if ($journalIds->isEmpty()) {
            return;
        }

        Transaction::query()
            ->where('transactionable_type', DocumentJournal::class)
            ->whereIn('transactionable_id', $journalIds)
            ->delete();

        DocumentJournal::whereIn('id', $journalIds)->delete();
    }
}
