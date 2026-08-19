<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\DocumentJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TPartnerReportController extends Controller
{
    /**
     * GET /api/admin/reports/t-partner
     *
     * T-account (Տ գործընկեր) for a single partner (client).
     * Data source: documents_journal table only.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $startDate = $request->query('startDate');
        $endDate   = $request->query('endDate');
        $partnerId = $request->query('partnerId');

        if ($startDate === null || $startDate === '') {
            return response()->json(['error' => 'startDate is required'], 400);
        }
        if ($endDate === null || $endDate === '') {
            return response()->json(['error' => 'endDate is required'], 400);
        }

        $startParsed = strtotime($startDate);
        $endParsed   = strtotime($endDate);
        if ($startParsed === false || date('Y-m-d', $startParsed) !== $startDate) {
            return response()->json(['error' => 'startDate must be a valid date'], 400);
        }
        if ($endParsed === false || date('Y-m-d', $endParsed) !== $endDate) {
            return response()->json(['error' => 'endDate must be a valid date'], 400);
        }

        if ($startDate > $endDate) {
            return response()->json(['error' => 'startDate must be before or equal to endDate'], 400);
        }

        if (!$partnerId) {
            return response()->json(['error' => 'partnerId is required'], 400);
        }

        $partner = Client::query()
            ->whereNull('deleted_at')
            ->find($partnerId, ['id', 'type', 'name', 'surname', 'company_name', 'internal_code', 'tax_number', 'social_card_number']);

        if (!$partner) {
            return response()->json(['error' => 'Գործընկերը չի գտնվել'], 404);
        }

        $partnerId = (int) $partner->id;

        $openingDebitRaw = (float) DocumentJournal::query()
            ->whereNull('deleted_at')
            ->where('debit_partner_id', $partnerId)
            ->whereDate('date', '<', $startDate)
            ->sum('amount_amd');

        $openingCreditRaw = (float) DocumentJournal::query()
            ->whereNull('deleted_at')
            ->where('credit_partner_id', $partnerId)
            ->whereDate('date', '<', $startDate)
            ->sum('amount_amd');

        $opening = $this->splitNetBalance($openingDebitRaw - $openingCreditRaw);

        $transactions = DocumentJournal::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($partnerId) {
                $q->where('debit_partner_id', $partnerId)
                    ->orWhere('credit_partner_id', $partnerId);
            })
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->with([
                'debitAccount:id,code',
                'creditAccount:id,code',
                'debitPartner:id,type,name,surname,company_name',
                'creditPartner:id,type,name,surname,company_name',
            ])
            ->orderBy('date')
            ->orderBy('id')
            ->get([
                'id',
                'date',
                'document_number',
                'document_type',
                'comment',
                'amount_amd',
                'debit_account_id',
                'credit_account_id',
                'debit_partner_id',
                'credit_partner_id',
                'debit_partner_name',
                'credit_partner_name',
            ]);

        $rows = [];
        $periodDebit  = 0.0;
        $periodCredit = 0.0;

        foreach ($transactions as $tx) {
            $isDebitSide = (int) $tx->debit_partner_id === $partnerId;
            $debitAmd     = $isDebitSide ? (float) $tx->amount_amd : null;
            $creditAmd    = $isDebitSide ? null : (float) $tx->amount_amd;

            if ($isDebitSide) {
                $periodDebit += (float) $tx->amount_amd;
            } else {
                $periodCredit += (float) $tx->amount_amd;
            }

            $counterpartyName = $isDebitSide
                ? ($this->partnerDisplayName($tx->creditPartner) ?? $tx->credit_partner_name)
                : ($this->partnerDisplayName($tx->debitPartner) ?? $tx->debit_partner_name);

            $rows[] = [
                'date'                    => $tx->date?->format('Y-m-d'),
                'debit_account_code'      => $tx->debitAccount?->code,
                'credit_account_code'     => $tx->creditAccount?->code,
                'debit_amd'               => $debitAmd,
                'credit_amd'              => $creditAmd,
                'paper_operation'         => $tx->document_type,
                'counterparty_partner_name' => $counterpartyName,
                'document_number'         => $tx->document_number,
                'comment'                 => $tx->comment,
            ];
        }

        $openingDebitVal  = (float) ($opening['debit'] ?? 0);
        $openingCreditVal = (float) ($opening['credit'] ?? 0);
        $closingNet       = ($openingDebitVal - $openingCreditVal) + ($periodDebit - $periodCredit);
        $closing          = $this->splitNetBalance($closingNet);

        return response()->json([
            'partner' => [
                'id'   => $partner->id,
                'code' => $this->partnerCode($partner),
                'name' => $this->partnerDisplayName($partner),
            ],
            'startDate' => $startDate,
            'endDate'   => $endDate,
            'openingBalance' => [
                'debit'  => $opening['debit'],
                'credit' => $opening['credit'],
            ],
            'rows' => $rows,
            'turnover' => [
                'debit'  => round($periodDebit, 2),
                'credit' => round($periodCredit, 2),
            ],
            'closingBalance' => [
                'debit'  => $closing['debit'],
                'credit' => $closing['credit'],
            ],
        ]);
    }

    private function partnerCode(Client $client): string
    {
        if (!empty($client->internal_code)) {
            return (string) $client->internal_code;
        }

        return str_pad((string) $client->id, 4, '0', STR_PAD_LEFT);
    }

    private function partnerDisplayName(?Client $client): ?string
    {
        if (!$client) {
            return null;
        }

        if ($client->type === 'legal') {
            return $client->company_name ?: null;
        }

        $name = trim(($client->name ?? '') . ' ' . ($client->surname ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * @return array{debit: float|null, credit: float|null}
     */
    private function splitNetBalance(float $net): array
    {
        if ($net > 0) {
            return ['debit' => round($net, 2), 'credit' => null];
        }
        if ($net < 0) {
            return ['debit' => null, 'credit' => round(abs($net), 2)];
        }

        return ['debit' => 0.0, 'credit' => null];
    }
}
