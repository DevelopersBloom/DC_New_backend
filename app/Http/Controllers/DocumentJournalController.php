<?php

namespace App\Http\Controllers;

use App\Exports\DocumentsJournalExport;
use App\Models\DocumentJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'user'                => $j->user ? trim(($j->user->name ?? '') . ' ' . ($j->user->surname ?? '')) : null,
                'created_at'          => optional($j->created_at)->format('Y-m-d H:i:s'),
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

}
