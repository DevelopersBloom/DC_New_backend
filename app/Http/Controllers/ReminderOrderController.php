<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReminderOrderRequest;
use App\Http\Requests\UpdateReminderOrderRequest;
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
//    public function store(StoreReminderOrderRequest $request): JsonResponse
//    {
//        $validated = $request->validated();
//
//        $lastNum = ReminderOrder::max('num') ?? 0;
//        $nextNum = $lastNum + 1;
//
//        $reminderOrder = ReminderOrder::create([
//            'order_date'        => $validated['order_date'] ?? null,
//            'amount'            => $validated['amount'],
//            'currency_id'       => $validated['currency_id'] ?? 1,
//            'comment'           => $validated['comment'] ?? null,
//            'debit_account_id'  => $validated['debit_account_id'],
//            'debit_partner_id'  => $validated['debit_partner_id'],
//            'credit_account_id' => $validated['credit_account_id'],
//            'credit_partner_id' => $validated['credit_partner_id'],
//            'is_draft'          => $validated['is_draft'] ?? false,
//            'num'               => $nextNum,
//        ]);
//
//        $reminderOrder->load(['debitPartner','creditPartner']);
//
//        $displayName = function ($p) {
//            if (!$p) return null;
//            if (!empty($p->company_name)) return $p->company_name;
//            $name    = $p->name ?? '';
//            $surname = $p->surname ?? '';
//            $full    = trim($name.' '.$surname);
//            return $full !== '' ? $full : null;
//        };
//
//        $debitPartner   = $reminderOrder->debitPartner;
//        $creditPartner  = $reminderOrder->creditPartner;
//
//        $debitPartnerName   = $displayName($debitPartner);
//        $creditPartnerName  = $displayName($creditPartner);
//
//        $debitPartnerCode  = $debitPartner
//            ? ($debitPartner->type === 'individual' ? ($debitPartner->social_card_number ?? null) : ($debitPartner->tax_number ?? null))
//            : null;
//
//        $creditPartnerCode = $creditPartner
//            ? ($creditPartner->type === 'individual' ? ($creditPartner->social_card_number ?? null) : ($creditPartner->tax_number ?? null))
//            : null;
//
//        $documentJournal = DocumentJournal::create([
//            'date'                => $reminderOrder->order_date,
//            'document_number'     => $reminderOrder->num,
//            'document_type'       => DocumentJournal::REMINDER_ORDER_TYPE,
//            'debit_account_id'    => $reminderOrder->debit_account_id,
//            'debit_partner_id'    => $reminderOrder->debit_partner_id,
//            'debit_partner_code'  => $debitPartnerCode,
//            'debit_partner_name'  => $debitPartnerName,
//            'credit_account_id'   => $reminderOrder->credit_account_id,
//            'credit_partner_id'   => $reminderOrder->credit_partner_id,
//            'credit_partner_code' => $creditPartnerCode,
//            'credit_partner_name' => $creditPartnerName,
//            'amount_amd'          => round((float)$reminderOrder->amount),
//            'amount_currency'     => 0,
//            'amount_currency_id'  => null,
//            'comment'             => $reminderOrder->comment,
//            'user_id'             => auth()->id(),
//            'journalable_type'    => ReminderOrder::class,
//            'journalable_id'      => $reminderOrder->id,
//        ]);
//
//        $documentJournal->transactions()->create([
//            'date'                => $reminderOrder->order_date,
//            'document_number'     => $reminderOrder->num,
//            'document_type'       => Transaction::REMINDER_ORDER_TYPE,
//            'debit_account_id'    => $reminderOrder->debit_account_id,
//            'debit_partner_id'    => $reminderOrder->debit_partner_id,
//            'debit_partner_code'  => $debitPartnerCode,
//            'debit_partner_name'  => $debitPartnerName,
//            'debit_currency_id'   => $reminderOrder->currency_id,
//            'credit_account_id'   => $reminderOrder->credit_account_id,
//            'credit_partner_id'   => $reminderOrder->credit_partner_id,
//            'credit_partner_code' => $creditPartnerCode,
//            'credit_partner_name' => $creditPartnerName,
//            'credit_currency_id'  => $reminderOrder->currency_id,
//            'amount_amd'          => round((float)$reminderOrder->amount),
//            'amount_currency'     => 0,
//            'amount_currency_id'  => null,
//            'comment'             => $reminderOrder->comment,
//            'user_id'             => auth()->id(),
//            'is_system'           => false,
//            'transactionable_type'=> DocumentJournal::class,
//            'transactionable_id'  => $documentJournal->id,
//        ]);
//
//        return response()->json([
//            'message' => 'Հիշարար օրդերը և transaction-ը DocumentJournal–ի հետ հաջողությամբ ստեղծվեցին։',
//        ], 201);
//    }
    public function store(StoreReminderOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $lastNum = ReminderOrder::orderByDesc('id')->value('num');

        if ($lastNum) {
            $lastNumber = (int) preg_replace('/\D/', '', $lastNum);
        } else {
            $lastNumber = 0;
        }

        $nextNumber = $lastNumber + 1;

        $formattedNum = 'MO-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        $reminderOrder = ReminderOrder::create([
            'order_date'        => $validated['order_date'] ?? null,
            'amount'            => $validated['amount'],
            'currency_id'       => $validated['currency_id'] ?? 1,
            'comment'           => $validated['comment'] ?? null,
            'debit_account_id'  => $validated['debit_account_id'],
            'debit_partner_id'  => $validated['debit_partner_id'],
            'credit_account_id' => $validated['credit_account_id'],
            'credit_partner_id' => $validated['credit_partner_id'],
            'is_draft'          => $validated['is_draft'] ?? false,
            'num'               => $formattedNum,
        ]);

        $reminderOrder->load(['debitPartner','creditPartner']);

        $displayName = function ($p) {
            if (!$p) return null;
            if (!empty($p->company_name)) return $p->company_name;
            $name    = $p->name ?? '';
            $surname = $p->surname ?? '';
            $full    = trim($name.' '.$surname);
            return $full !== '' ? $full : null;
        };

        $debitPartner   = $reminderOrder->debitPartner;
        $creditPartner  = $reminderOrder->creditPartner;

        $debitPartnerName   = $displayName($debitPartner);
        $creditPartnerName  = $displayName($creditPartner);

        $debitPartnerCode  = $debitPartner
            ? ($debitPartner->type === 'individual' ? ($debitPartner->social_card_number ?? null) : ($debitPartner->tax_number ?? null))
            : null;

        $creditPartnerCode = $creditPartner
            ? ($creditPartner->type === 'individual' ? ($creditPartner->social_card_number ?? null) : ($creditPartner->tax_number ?? null))
            : null;

        $documentJournal = DocumentJournal::create([
            'date'                => $reminderOrder->order_date,
            'document_number'     => $reminderOrder->num,
            'document_type'       => DocumentJournal::REMINDER_ORDER_TYPE,
            'debit_account_id'    => $reminderOrder->debit_account_id,
            'debit_partner_id'    => $reminderOrder->debit_partner_id,
            'debit_partner_code'  => $debitPartnerCode,
            'debit_partner_name'  => $debitPartnerName,
            'credit_account_id'   => $reminderOrder->credit_account_id,
            'credit_partner_id'   => $reminderOrder->credit_partner_id,
            'credit_partner_code' => $creditPartnerCode,
            'credit_partner_name' => $creditPartnerName,
            'amount_amd'          => $reminderOrder->amount,
            'amount_currency'     => 0,
            'amount_currency_id'  => null,
            'comment'             => $reminderOrder->comment,
            'user_id'             => auth()->id(),
            'journalable_type'    => ReminderOrder::class,
            'journalable_id'      => $reminderOrder->id,
        ]);

        $documentJournal->transactions()->create([
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
            'amount_amd'          => $reminderOrder->amount,
            'amount_currency'     => 0,
            'amount_currency_id'  => null,
            'comment'             => $reminderOrder->comment,
            'user_id'             => auth()->id(),
            'is_system'           => false,
            'transactionable_type'=> DocumentJournal::class,
            'transactionable_id'  => $documentJournal->id,
        ]);

        return response()->json([
            'message' => 'Հիշարար օրդերը և transaction-ը DocumentJournal–ի հետ հաջողությամբ ստեղծվեցին։',
        ], 201);
    }


//    public function update(UpdateReminderOrderRequest $request, ReminderOrder $reminderOrder): JsonResponse
//    {
//        if (!$reminderOrder->is_draft) {
//            return response()->json([
//                'message' => 'Թույլատրելի չէ․ միայն սևագիր օրդերը կարող են թարմացվել։'
//            ], 409);
//        }
//
//        $data = $request->validated();
//        if (array_key_exists('is_draft', $data) && $reminderOrder->is_draft === true) {
//            $data['is_draft'] = (bool)$data['is_draft'];
//        } else {
//            unset($data['is_draft']);
//        }
//
//        $reminderOrder->fill($data)->save();
//
//        return response()->json([
//            'message' => 'Հիշարար օրդերը թարմացվեց',
//            'data'    => $reminderOrder->load(['currency','debitAccount','creditAccount']),
//        ]);
//    }

    public function update(UpdateReminderOrderRequest $request, ReminderOrder $reminderOrder): JsonResponse
    {
//        if (!$reminderOrder->is_draft) {
//            return response()->json([
//                'message' => 'Not allowed: only draft orders can be updated'
//            ], 409);
//        }

        $validated = $request->validated();

        return DB::transaction(function () use ($reminderOrder, $validated) {
            $reminderOrder->update($validated);

            $reminderOrder->load(['debitPartner', 'creditPartner']);

            $displayName = function ($p) {
                if (!$p) return null;
                if (!empty($p->company_name)) return $p->company_name;
                $full = trim(($p->name ?? '') . ' ' . ($p->surname ?? ''));
                return $full !== '' ? $full : null;
            };

            $debitCode = $reminderOrder->debitPartner
                ? ($reminderOrder->debitPartner->type === 'individual' ? $reminderOrder->debitPartner->social_card_number : $reminderOrder->debitPartner->tax_number)
                : null;

            $creditCode = $reminderOrder->creditPartner
                ? ($reminderOrder->creditPartner->type === 'individual' ? $reminderOrder->creditPartner->social_card_number : $reminderOrder->creditPartner->tax_number)
                : null;

            $documentJournal = DocumentJournal::where('journalable_type', ReminderOrder::class)
                ->where('journalable_id', $reminderOrder->id)
                ->first();

            if ($documentJournal) {
                $documentJournal->update([
                    'date'                => $reminderOrder->order_date,
                    'document_number'     => $reminderOrder->num,
                    'debit_account_id'    => $reminderOrder->debit_account_id,
                    'debit_partner_id'    => $reminderOrder->debit_partner_id,
                    'debit_partner_code'  => $debitCode,
                    'debit_partner_name'  => $displayName($reminderOrder->debitPartner),
                    'credit_account_id'   => $reminderOrder->credit_account_id,
                    'credit_partner_id'   => $reminderOrder->credit_partner_id,
                    'credit_partner_code' => $creditCode,
                    'credit_partner_name' => $displayName($reminderOrder->creditPartner),
                    'amount_amd'          => round((float)$reminderOrder->amount),
                    'comment'             => $reminderOrder->comment,
                ]);

                $documentJournal->transactions()->update([
                    'date'                => $reminderOrder->order_date,
                    'document_number'     => $reminderOrder->num,
                    'debit_account_id'    => $reminderOrder->debit_account_id,
                    'debit_partner_id'    => $reminderOrder->debit_partner_id,
                    'debit_partner_code'  => $debitCode,
                    'debit_partner_name'  => $displayName($reminderOrder->debitPartner),
                    'debit_currency_id'   => $reminderOrder->currency_id,
                    'credit_account_id'   => $reminderOrder->credit_account_id,
                    'credit_partner_id'   => $reminderOrder->credit_partner_id,
                    'credit_partner_code' => $creditCode,
                    'credit_partner_name' => $displayName($reminderOrder->creditPartner),
                    'credit_currency_id'  => $reminderOrder->currency_id,
                    'amount_amd'          => round((float)$reminderOrder->amount),
                    'comment'             => $reminderOrder->comment,
                ]);
            }

            return response()->json([
                'message' => 'Reminders and related documents updated',
            ]);
        });
    }    /**
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
