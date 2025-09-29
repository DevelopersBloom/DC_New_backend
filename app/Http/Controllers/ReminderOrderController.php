<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReminderOrderRequest;
use App\Http\Requests\UpdateReminderOrderRequest;
use App\Models\ReminderOrder;
use App\Models\Transaction;
use App\Traits\CalculationTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderOrderController
{
    use CalculationTrait;
    public function index(Request $request)
    {
        $query = ReminderOrder::with([
            'currency:id,code,name',
            'debitAccount:id,code,name',
            'creditAccount:id,code,name',
        ]);

        if ($request->has('is_draft')) {
            $query->where('is_draft', $request->boolean('is_draft'));
        }

        $orders = $query->orderByDesc('order_date')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $orders,
        ]);
    }

    public function store(StoreReminderOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $lastNum = ReminderOrder::max('num') ?? 0;
        $nextNum = $lastNum + 1;

        $reminderOrder = ReminderOrder::create([
            'order_date'        => $validated['order_date'] ?? null,
            'amount'            => $validated['amount'] ?? null,
            'currency_id'       => $validated['currency_id'] ?? 1,
            'comment'           => $validated['comment'] ?? null,
            'debit_account_id'  => $validated['debit_account_id'] ?? null,
            'debit_partner_id'  => $validated['debit_partner_id'] ?? null,
            'credit_account_id' => $validated['credit_account_id'] ?? null,
            'credit_partner_id' => $validated['credit_partner_id'] ?? null,
            'is_draft'          => $validated['is_draft'] ?? false,
            'num'               => $nextNum,
        ]);

        $reminderOrder->load(['debitPartner','creditPartner']);

        $displayName = function ($p) {
            if (!$p) return null;
            if (!empty($p->company_name)) {
                return $p->company_name;
            }
            $name    = $p->name    ?? '';
            $surname = $p->surname ?? '';
            $full    = trim($name.' '.$surname);
            return $full !== '' ? $full : null;
        };

        $debit   = $reminderOrder->debitPartner;
        $credit  = $reminderOrder->creditPartner;

        $debitPartnerName   = $displayName($debit);
        $creditPartnerName  = $displayName($credit);

        $debitPartnerCode  = $debit
            ? ($debit->type === 'individual' ? ($debit->social_card_number ?? null) : ($debit->tax_number ?? null))
            : null;

        $creditPartnerCode = $credit
            ? ($credit->type === 'individual' ? ($credit->social_card_number ?? null) : ($credit->tax_number ?? null))
            : null;

        $reminderOrder->transactions()->create([
            'date'                => $reminderOrder->order_date,
            'document_number'     => $reminderOrder->num,
            'document_type'       => Transaction::REMINDER_ORDER_TYPE,

            'debit_account_id'    => $reminderOrder->debit_account_id,
            'debit_partner_id'    => $reminderOrder->debit_partner_id,
            'debit_partner_code'  => $debitPartnerCode,
            'debit_partner_name'  => $debitPartnerName,
            'debit_currency_id'   => $reminderOrder->currency_id,

            'credit_account_id'   => $reminderOrder->credit_account_id,
            'credit_partner_id'   => $reminderOrder->credit_partner_id,
            'credit_partner_code' => $creditPartnerCode,
            'credit_partner_name' => $creditPartnerName,
            'credit_currency_id'  => $reminderOrder->currency_id,

            'amount_amd'          => round((float)$reminderOrder->amount),
            'amount_currency'     => 0,
            'amount_currency_id'  => null,

            'comment'             => $reminderOrder->comment,
            'user_id'             => auth()->id(),
            'is_system'           => false,
        ]);

        return response()->json([
            'message' => 'Հիշարար օրդերը հաջողությամբ ստեղծվեց։',
        ], 201);
    }
    public function update(UpdateReminderOrderRequest $request, ReminderOrder $reminderOrder): JsonResponse
    {
        if (!$reminderOrder->is_draft) {
            return response()->json([
                'message' => 'Թույլատրելի չէ․ միայն սևագիր օրդերը կարող են թարմացվել։'
            ], 409);
        }

        $data = $request->validated();
        if (array_key_exists('is_draft', $data) && $reminderOrder->is_draft === true) {
            $data['is_draft'] = (bool)$data['is_draft'];
        } else {
            unset($data['is_draft']);
        }

        $reminderOrder->fill($data)->save();

        return response()->json([
            'message' => 'Հիշարար օրդերը թարմացվեց',
            'data'    => $reminderOrder->load(['currency','debitAccount','creditAccount']),
        ]);
    }

    /**
     * Delete only if is_draft = true.
     */
    public function destroy(ReminderOrder $reminderOrder): JsonResponse
    {
        if (!$reminderOrder->is_draft) {
            return response()->json([
                'message' => 'Թույլատրելի չէ․ միայն սևագիր օրդերը կարող են ջնջվել։'
            ], 409);
        }

        $reminderOrder->delete();

        return response()->json([
            'message' => 'Հիշարար օրդերը ջնջվեց',
        ]);
    }
}
