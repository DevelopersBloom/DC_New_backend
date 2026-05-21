<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TAccountReportController extends Controller
{
    /**
     * GET /api/admin/reports/t-account
     *
     * T-account (Տ հաշվային) for a single chart-of-accounts code.
     * Data source: transactions table only.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $startDate = $request->query('startDate');
        $endDate   = $request->query('endDate');
        $accountId = $request->query('accountId');
        $accountCode = $request->query('accountCode');

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

        if (!$accountId && !$accountCode) {
            return response()->json(['error' => 'accountId or accountCode is required'], 400);
        }

        $accountQuery = ChartOfAccount::query()->whereNull('deleted_at');
        if ($accountId) {
            $accountQuery->where('id', $accountId);
        } else {
            $accountQuery->where('code', $accountCode);
        }

        $account = $accountQuery->first(['id', 'code', 'name']);
        if (!$account) {
            return response()->json(['error' => 'Հաշիվը չի գտնվել'], 404);
        }

        $accountId = $account->id;

        $openingDebitRaw  = (float) Transaction::query()
            ->whereNull('deleted_at')
            ->where('debit_account_id', $accountId)
            ->whereDate('date', '<', $startDate)
            ->sum('amount_amd');

        $openingCreditRaw = (float) Transaction::query()
            ->whereNull('deleted_at')
            ->where('credit_account_id', $accountId)
            ->whereDate('date', '<', $startDate)
            ->sum('amount_amd');

        $opening = $this->splitNetBalance($openingDebitRaw - $openingCreditRaw);

        $transactions = Transaction::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($accountId) {
                $q->where('debit_account_id', $accountId)
                    ->orWhere('credit_account_id', $accountId);
            })
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->with([
                'debitAccount:id,code',
                'creditAccount:id,code',
            ])
            ->orderBy('date')
            ->orderBy('id')
            ->get([
                'id',
                'date',
                'document_number',
                'comment',
                'amount_amd',
                'debit_account_id',
                'credit_account_id',
            ]);

        $rows = [];
        $periodDebit  = 0.0;
        $periodCredit = 0.0;

        foreach ($transactions as $tx) {
            $isDebitSide  = (int) $tx->debit_account_id === (int) $accountId;
            $debitAmd      = $isDebitSide ? (float) $tx->amount_amd : null;
            $creditAmd     = $isDebitSide ? null : (float) $tx->amount_amd;

            if ($isDebitSide) {
                $periodDebit += (float) $tx->amount_amd;
            } else {
                $periodCredit += (float) $tx->amount_amd;
            }

            $rows[] = [
                'date'                 => $tx->date?->format('Y-m-d'),
                'debit_account_code'   => $tx->debitAccount?->code,
                'credit_account_code'  => $tx->creditAccount?->code,
                'debit_amd'            => $debitAmd,
                'credit_amd'           => $creditAmd,
                'document_number'      => $tx->document_number,
                'comment'              => $tx->comment,
            ];
        }

        $closingNet = ($opening['debit'] - $opening['credit']) + ($periodDebit - $periodCredit);
        $closing    = $this->splitNetBalance($closingNet);

        return response()->json([
            'account' => [
                'id'   => $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
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

        // Zero net balance: show 0 on debit side (matches Excel template).
        return ['debit' => 0.0, 'credit' => null];
    }
}
