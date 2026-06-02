<?php

namespace App\Http\Controllers;

use App\Events\Discuss;
use App\Models\Discussion;
use App\Models\DocumentJournal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InnerController extends Controller
{
    /**
     * GET /api/inner/tx-vs-journal-diff?to=2026-04-30
     */
    public function txVsJournalDiff(Request $request)
    {
        $to      = $request->query('to', now()->toDateString());
        $djClass = DocumentJournal::class;

        // 1. Breakdown ըստ transactionable_type
        $byType = DB::table('transactions')
            ->whereNull('deleted_at')
            ->whereDate('date', '<=', $to)
            ->selectRaw('COALESCE(transactionable_type, "(null)") as transactionable_type, COUNT(*) as cnt, SUM(amount_amd) as total')
            ->groupBy('transactionable_type')
            ->orderByRaw('SUM(amount_amd) DESC')
            ->get();

        // 2. Journal soft-deleted, transaction active
        $journalDeletedRows = DB::table('transactions as t')
            ->join('documents_journal as dj', 'dj.id', '=', 't.transactionable_id')
            ->whereNull('t.deleted_at')
            ->whereNotNull('dj.deleted_at')
            ->where('t.transactionable_type', $djClass)
            ->whereDate('t.date', '<=', $to)
            ->select([
                't.id as transaction_id', 't.date', 't.document_type', 't.document_number',
                't.amount_amd as tx_amount', 't.comment as tx_comment',
                'dj.id as journal_id', 'dj.amount_amd as journal_amount',
                'dj.comment as journal_comment', 'dj.deleted_at as journal_deleted_at',
            ])
            ->orderBy('t.date')->get();

        $journalDeleted = [
            'cnt'   => $journalDeletedRows->count(),
            'total' => $journalDeletedRows->sum('tx_amount'),
            'rows'  => $journalDeletedRows,
        ];

        // 3. Orphan — transactionable_type = DocumentJournal, բայց journal row չկա
        $orphanRows = DB::table('transactions as t')
            ->leftJoin('documents_journal as dj', function ($join) use ($djClass) {
                $join->on('dj.id', '=', 't.transactionable_id')
                     ->where('t.transactionable_type', '=', $djClass);
            })
            ->whereNull('t.deleted_at')
            ->where('t.transactionable_type', $djClass)
            ->whereDate('t.date', '<=', $to)
            ->whereNull('dj.id')
            ->select([
                't.id as transaction_id', 't.date', 't.document_type', 't.document_number',
                't.amount_amd as tx_amount', 't.comment as tx_comment',
                't.transactionable_id as missing_journal_id',
            ])
            ->orderBy('t.date')->get();

        $orphan = [
            'cnt'   => $orphanRows->count(),
            'total' => $orphanRows->sum('tx_amount'),
            'rows'  => $orphanRows,
        ];

        // 4. NULL transactionable_type transactions (NULL != X -> NULL, ուստի առանձին query)
        $nullTypeRows = DB::table('transactions')
            ->whereNull('deleted_at')
            ->whereNull('transactionable_type')
            ->whereDate('date', '<=', $to)
            ->select([
                'id as transaction_id', 'date', 'document_type', 'document_number',
                'amount_amd as tx_amount', 'comment as tx_comment',
                'transactionable_id',
            ])
            ->orderBy('date')->get();

        $nullType = [
            'cnt'   => $nullTypeRows->count(),
            'total' => $nullTypeRows->sum('tx_amount'),
            'rows'  => $nullTypeRows,
        ];

        // 5. Journals WITHOUT any active transaction
        $journalNoTxRows = DB::table('documents_journal as dj')
            ->leftJoin('transactions as t', function ($join) use ($djClass) {
                $join->on('t.transactionable_id', '=', 'dj.id')
                     ->where('t.transactionable_type', '=', $djClass)
                     ->whereNull('t.deleted_at');
            })
            ->whereNull('dj.deleted_at')
            ->whereDate('dj.date', '<=', $to)
            ->whereNull('t.id')
            ->select([
                'dj.id as journal_id', 'dj.date', 'dj.document_type', 'dj.document_number',
                'dj.amount_amd as journal_amount', 'dj.comment as journal_comment',
            ])
            ->orderBy('dj.date')->get();

        $journalNoTx = [
            'cnt'   => $journalNoTxRows->count(),
            'total' => $journalNoTxRows->sum('journal_amount'),
            'rows'  => $journalNoTxRows,
        ];

        // Totals
        $txTotal = DB::table('transactions')->whereNull('deleted_at')->whereDate('date', '<=', $to)->sum('amount_amd');
        $djTotal = DB::table('documents_journal')->whereNull('deleted_at')->whereDate('date', '<=', $to)->sum('amount_amd');

        return response()->json([
            'to'                          => $to,
            'transactions_total'          => $txTotal,
            'documents_journal_total'     => $djTotal,
            'difference'                  => round($txTotal - $djTotal, 2),
            'breakdown_by_type'           => $byType,
            'journal_soft_deleted'        => $journalDeleted,
            'orphan_transactions'         => $orphan,
            'null_type_transactions'      => $nullType,       // ← նոր: null transactionable_type
            'journals_without_transaction'=> $journalNoTx,   // ← նոր: journal-ներ առանց transaction-ի
        ]);
    }

    public function addComment(Request $request){
        Discussion::create([
            'user_id' => auth()->user()->id,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'text' => $request->text
        ]);
        event(new Discuss(auth()->user()->id, auth()->user()->pawnshop_id));
        return response()->json([
            'success' => 'success',
            'all' => $request->all(),
            'user' => auth()->user()
        ]);
    }
    public function getComments(Request $request){
        $discussions = Discussion::where('pawnshop_id',auth()->user()->pawnshop_id)->orderBy('id','desc')->with('user')->get();
        foreach ($discussions as $discussion){
            $discussion->date = Carbon::parse($discussion->created_at)->setTimezone('Asia/Yerevan')->format('m-d H:i:s');
        }
        return response()->json([
            'success' => 'success',
            'discussions' => $discussions,
        ]);
    }
}
