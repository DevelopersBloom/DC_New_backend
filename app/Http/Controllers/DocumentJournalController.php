<?php

namespace App\Http\Controllers;

use App\Exports\DocumentsJournalExport;
use App\Models\DocumentJournal;
use App\Models\LoanNdm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel;

class DocumentJournalController
{
    public function index(Request $request): JsonResponse
    {
        $from   = $request->query('from_date');
        $to     = $request->query('to_date');

        $query = DocumentJournal::with([
            'currency:id,code',
            'partner:id,type,name,surname,company_name,social_card_number,tax_number',
            'user:id,name,surname',
        ])
            ->when($from && $to, fn($q) => $q->whereBetween('date', [$from, $to]))
            ->when($from && !$to, fn($q) => $q->where('date', '>=', $from))
            ->when(!$from && $to, fn($q) => $q->where('date', '<=', $to))
            ->orderByDesc('id');

        $page = $query->paginate(20);

        $page->getCollection()->transform(function (DocumentJournal $j) {
            $partner = $j->partner;

            $partnerCode = $partner
                ? ($partner->type === 'individual'
                    ? ($partner->social_card_number ?? null)
                    : ($partner->tax_number ?? null))
                : null;

            $partnerName = $partner
                ? ($partner->type === 'legal'
                    ? ($partner->company_name ?? '')
                    : trim(($partner->name ?? '') . ' ' . ($partner->surname ?? '')))
                : null;

            return [
                'id' => $j->id,
                'date'                => optional($j->date)->format('Y-m-d'),
                'document_number'     => $j->document_number,
                'document_type'       => $j->document_type,
                'amount_currency'     => $j->currency?->code,
                'amount_currency_id'  => $j->currency_id,
                'amount_amd'          => $j->amount_amd,
                'debit_partner_code'  => $partnerCode,
                'debit_partner_name'  => $partnerName,
                'comment'             => $j->comment,
                'user_id'             => $j->user_id,
                'user'                => $j->user,
                'disbursement_date'   => optional($j->created_at)->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json($page);
    }
    public function export(Request $request)
    {
        $from = $request->query('from_date');
        $to   = $request->query('to_date');
        $type = $request->query('document_type'); // optional

        return Excel::download(
            new DocumentsJournalExport($from, $to, $type),
            'ՓաստաթղթերիՄատյան.xlsx'
        );
    }

    public function update(Request $request, DocumentJournal $journal): JsonResponse
    {
        $data = $request->validate([
            'date'             => ['sometimes','date'],
            'document_number'  => ['sometimes','string','nullable','max:255'],
            'document_type'    => ['sometimes','string','max:255'],
            'currency_id'      => ['sometimes','nullable','integer','exists:currencies,id'],
            'amount_amd'       => ['sometimes','numeric'],
            'amount_currency'  => ['sometimes','nullable','numeric'],
            'partner_id'       => ['sometimes','nullable','integer','exists:clients,id'],
            'comment'          => ['sometimes','nullable','string'],
             'user_id'         => ['sometimes','nullable','integer','exists:users,id'],
        ]);

        DB::beginTransaction();
        try {
            $journal->fill($data);
            $journal->save();

            if ($journal->relationLoaded('journalable') === false) {
                $journal->load('journalable');
            }
            $source = $journal->journalable;

            if ($source) {
                switch (true) {
                    case $source instanceof LoanNdm:
                        $map = [
                                'date'            => 'contract_date',
                                'document_number' => 'contract_number',
                                'currency_id'     => 'currency_id',
                                'amount_amd'      => 'amount',
                                'partner_id'      => 'client_id',
                                'comment'         => 'comment',
                        ];
                        foreach ($map as $jKey => $mKey) {
                            if (array_key_exists($jKey, $data)) {
                                $source->{$mKey} = $data[$jKey];
                            }
                        }
                        if (array_key_exists('amount_currency', $data) && $source->isFillable('amount_currency')) {
                            $source->amount_currency = $data['amount_currency'];
                        }
                        break;
                        default:
                            foreach ($data as $key => $val) {
                                if ($source->isFillable($key)) {
                                    $source->{$key} = $val;
                                }
                            }
                            break;
                    }

                    $source->save();

            }

            DB::commit();

            return response()->json([
                'message' => 'Document journal updated successfully',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Update failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function destroy(DocumentJournal $journal): JsonResponse
    {
        try {
            DB::beginTransaction();

            $journal->load('journalable');

            $source = $journal->journalable;

            if ($source) {
                $source->delete();
            }

            $journal->delete();

            DB::commit();

            return response()->json([
                'message' => 'Document journal deleted successfully'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Delete failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

}
