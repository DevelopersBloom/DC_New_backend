<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReminderOrderRequest;
use App\Http\Requests\UpdateReminderOrderRequest;
use App\Models\ChartOfAccount;
use App\Models\DocumentJournal;
use App\Models\ReminderOrder;
use App\Models\Transaction;
use App\Traits\CalculationTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($validated) {
            // Lock the newest row so concurrent requests can't hand out the same number.
            $lastNum = ReminderOrder::withTrashed()->lockForUpdate()->orderByDesc('id')->value('num');
            $lastNumber = $lastNum ? (int) preg_replace('/\D/', '', $lastNum) : 0;

            $reminderOrder = ReminderOrder::create([
                'order_date'        => $validated['order_date'] ?? null,
                'amount'            => $validated['amount'],
                'currency_id'       => $validated['currency_id'] ?? 1,
                'comment'           => $validated['comment'] ?? null,
                'debit_account_id'  => $validated['debit_account_id'] ?? null,
                'debit_partner_id'  => $validated['debit_partner_id'] ?? null,
                'credit_account_id' => $validated['credit_account_id'] ?? null,
                'credit_partner_id' => $validated['credit_partner_id'] ?? null,
                'is_draft'          => $validated['is_draft'] ?? false,
                'num'               => 'MO-' . str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT),
            ]);

            $documentType = $reminderOrder->resolveDocumentType();

            $documentJournal = DocumentJournal::create([
                'date'                => $reminderOrder->order_date,
                'document_number'     => $reminderOrder->num,
                'document_type'       => $documentType,
                'debit_account_id'    => $reminderOrder->debit_account_id,
                'debit_partner_id'    => $reminderOrder->debit_partner_id,
                'credit_account_id'   => $reminderOrder->credit_account_id,
                'credit_partner_id'   => $reminderOrder->credit_partner_id,
                'amount_amd'          => $reminderOrder->amount,
                'amount_currency'     => 0,
                'comment'             => $reminderOrder->comment,
                'user_id'             => auth()->id(),
                'journalable_type'    => ReminderOrder::class,
                'journalable_id'      => $reminderOrder->id,
            ]);

            $documentJournal->transactions()->create([
                'date'                => $reminderOrder->order_date,
                'document_number'     => $reminderOrder->num,
                'document_type'       => $documentType,
                'debit_account_id'    => $reminderOrder->debit_account_id,
                'debit_partner_id'    => $reminderOrder->debit_partner_id,
                'debit_currency_id'   => $reminderOrder->currency_id,
                'credit_account_id'   => $reminderOrder->credit_account_id,
                'credit_partner_id'   => $reminderOrder->credit_partner_id,
                'credit_currency_id'  => $reminderOrder->currency_id,
                'amount_amd'          => $reminderOrder->amount,
                'amount_currency'     => 0,
                'amount_currency_id'  => null,
                'comment'             => $reminderOrder->comment,
                'user_id'             => auth()->id(),
                'is_system'           => false,
                'transactionable_type'=> DocumentJournal::class,
                'transactionable_id'  => $documentJournal->id,
            ]);
        });

        return response()->json([
            'message' => 'Հիշարար օրդերը և transaction-ը DocumentJournal–ի հետ հաջողությամբ ստեղծվեցին։',
        ], 201);
    }

    public function update(UpdateReminderOrderRequest $request, ReminderOrder $reminderOrder): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($reminderOrder, $validated) {
            $reminderOrder->update($validated);

            $documentJournal = DocumentJournal::where('journalable_type', ReminderOrder::class)
                ->where('journalable_id', $reminderOrder->id)
                ->first();

            if ($documentJournal) {
                $documentType = $reminderOrder->resolveDocumentType();

                $documentJournal->update([
                    'date'              => $reminderOrder->order_date,
                    'document_number'   => $reminderOrder->num,
                    'document_type'     => $documentType,
                    'debit_account_id'  => $reminderOrder->debit_account_id,
                    'debit_partner_id'  => $reminderOrder->debit_partner_id,
                    'credit_account_id' => $reminderOrder->credit_account_id,
                    'credit_partner_id' => $reminderOrder->credit_partner_id,
                    'amount_amd'        => $reminderOrder->amount,
                    'comment'           => $reminderOrder->comment,
                ]);

                $documentJournal->transactions()->update([
                    'date'               => $reminderOrder->order_date,
                    'document_number'    => $reminderOrder->num,
                    'document_type'      => $documentType,
                    'debit_account_id'   => $reminderOrder->debit_account_id,
                    'debit_partner_id'   => $reminderOrder->debit_partner_id,
                    'debit_currency_id'  => $reminderOrder->currency_id,
                    'credit_account_id'  => $reminderOrder->credit_account_id,
                    'credit_partner_id'  => $reminderOrder->credit_partner_id,
                    'credit_currency_id' => $reminderOrder->currency_id,
                    'amount_amd'         => $reminderOrder->amount,
                    'comment'            => $reminderOrder->comment,
                ]);
            }

            return response()->json([
                'message' => 'Reminders and related documents updated',
            ]);
        });
    }

    /**
     * Delete only if is_draft = true.
     */
    public function destroy(ReminderOrder $reminderOrder): JsonResponse
    {
        if (!$reminderOrder->is_draft) {
            return response()->json([
                'message' => 'Not allowed: only draft orders can be deleted'
            ], 409);
        }

        $reminderOrder->delete();

        return response()->json([
            'message' => 'Reminder order deleted',
        ]);
    }
}
