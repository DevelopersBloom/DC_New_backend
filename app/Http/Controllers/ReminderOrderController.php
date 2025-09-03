<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReminderOrderRequest;
use App\Http\Requests\UpdateReminderOrderRequest;
use App\Models\ReminderOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderOrderController
{
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

        ReminderOrder::create([
            'order_date'       => $validated['order_date'] ?? null,
            'amount'           => $validated['amount'] ?? null,
            'currency_id'      => $validated['currency_id'] ?? null,
            'comment'          => $validated['comment'] ?? null,
            'debit_account_id' => $validated['debit_account_id'] ?? null,
            'credit_account_id'=> $validated['credit_account_id'] ?? null,
            'is_draft'         => $validated['is_draft'] ?? false,
            'num'              => $nextNum,
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
