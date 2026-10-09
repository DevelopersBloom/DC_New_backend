<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Http\Requests\UpdateContractAmountHistoryRequest;
use App\Models\Transaction;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class
DealController extends Controller
{
    use OrderTrait,ContractTrait;
    public function index(Request $request)
    {
        $pawnshopId = auth()->user()->pawnshop_id;
        $dealType = $request->input('type', Deal::HISTORY);

        $deals = Deal::where('pawnshop_id', $pawnshopId)
            ->select(
                'id',
                DB::raw("DATE(date) as date"),
                'amount',
                'pawnshop_id',
                'cash',
                'order_id',
                'contract_id',
                'type',
                'interest_amount',
                'delay_days',
                'created_by'
            )
            ->with([
                'order:id,client_name,order,contract_id,purpose',
                'contract:id,num,discount,penalty_amount,mother',
                'createdBy:id,name,surname'
            ])
            ->when($request->dateFrom, fn($query) =>
            $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [Carbon::parse($request->dateFrom)->setTimezone('Asia/Yerevan')])
            )
            ->when($request->dateTo, fn($query) =>
            $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [Carbon::parse($request->dateTo)->setTimezone('Asia/Yerevan')])
            )
            ->when($dealType !== Deal::HISTORY, fn($query) =>
            $query->where('type', $dealType)
            )
            ->when($request->filled('client_name'), fn($query) =>
            $query->whereHas('order', fn($q) =>
                $q->where('client_name', 'like', '%' . $request->client_name . '%')
            )
            )
            ->when($request->filled('cash'), fn($query) =>
            $query->where('cash', $request->boolean('cash'))
            )
            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")
            ->orderByDesc('id')
            ->paginate(10);

        // Transform deals to include calculated cashbox
        $deals->getCollection()->transform(fn($deal) => $this->attachCashboxData($deal, $pawnshopId));

        return response()->json(['deals' => $deals]);
    }

    private function attachCashboxData($deal, $pawnshopId)
    {
        $data = $this->calculateDailyCashbox($deal->id, $deal->date, $pawnshopId);
        $deal->cashbox = $data['cashbox'];
        $deal->bank_cashbox = $data['bank_cashbox'];
        $deal->total = $deal->cashbox + $deal->bank_cashbox;
        return $deal;
    }

    private function calculateDailyCashbox($id, $date, $pawnshopId)
    {
        $inCash = $this->sumAmount($date, $id, [Deal::IN_DEAL], true, $pawnshopId);
        $inBank = $this->sumAmount($date, $id, [Deal::IN_DEAL], false, $pawnshopId);
        $outCash = $this->sumAmount($date, $id, [Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL], true, $pawnshopId);
        $outBank = $this->sumAmount($date, $id, [Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL], false, $pawnshopId);

        return [
            'cashbox' => ($inCash ?? 0) - ($outCash ?? 0),
            'bank_cashbox' => ($inBank ?? 0) - ($outBank ?? 0),
        ];
    }

    private function sumAmount($date, $id, array $types, $isCash, $pawnshopId)
    {
        return Deal::whereDate('date', '<=', $date)
            ->where('id', '<=', $id)
            ->whereIn('type', $types)
            ->where('cash', $isCash)
            ->where('pawnshop_id', $pawnshopId)
            ->sum('amount');
    }

    public function updateContractAmountHistory(
        UpdateContractAmountHistoryRequest $request,
        int $id
    ): JsonResponse {
        $pawnshopId = auth()->user()->pawnshop_id;

        $history = ContractAmountHistory::query()
            ->where('pawnshop_id', $pawnshopId)
            ->whereIn('amount_type', ['estimated_amount', 'provided_amount'])
            ->findOrFail($id);

        $validated = $request->validated();
        $contractId = $validated['contract_id'] ?? null;

        if (!empty($validated['contract_num'])) {
            $contract = Contract::query()
                ->where('num', $validated['contract_num'])
                ->first();

            if (!$contract) {
                return response()->json([
                    'message' => 'Contract not found for the given number.',
                ], 422);
            }

            $contractId = $contract->id;
        }

        $history->update([
            'amount' => $validated['amount'],
            'type' => $validated['type'],
            'date' => $validated['date'],
            'contract_id' => $contractId,
            'deal_id' => $validated['deal_id'] ?? null,
        ]);

        $history->load('contract:id,num');

        return response()->json([
            'message' => 'History updated successfully.',
            'history' => $this->formatContractAmountHistoryItem($history),
        ]);
    }

    private function formatContractAmountHistoryItem(ContractAmountHistory $history): array
    {
        $signedAmount = $history->type === 'in'
            ? (float) $history->amount
            : -(float) $history->amount;

        return [
            'id' => $history->id,
            'amount' => (float) $history->amount,
            'type' => $history->type,
            'amount_type' => $history->amount_type,
            'signed_amount' => $signedAmount,
            'contract_id' => $history->contract_id,
            'contract_num' => $history->contract?->num,
            'deal_id' => $history->deal_id,
            'date' => $history->date->format('Y-m-d'),
        ];
    }

//    public function getCashBox(int $pawnshop_id)
//    {
//        $now = Carbon::now()->format('Y-m-d');
//
//        $deals = Deal::whereDate('date', '<=', $now)
//            ->where('pawnshop_id',$pawnshop_id)
//            ->whereIn('type', [Deal::IN_DEAL, Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL])
//            ->selectRaw("
//            SUM(CASE WHEN type = ? AND cash = true THEN amount ELSE 0 END) as total_cash_in,
//            SUM(CASE WHEN type IN (?, ?, ?) AND cash = true THEN amount ELSE 0 END) as total_cash_out,
//            SUM(CASE WHEN type = ? AND cash = false THEN amount ELSE 0 END) as total_bank_in,
//            SUM(CASE WHEN type IN (?, ?, ?) AND cash = false THEN amount ELSE 0 END) as total_bank_out
//        ", [
//                Deal::IN_DEAL,
//                Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL,
//                Deal::IN_DEAL,
//                Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL
//            ])
//            ->first();
//
//        $cash_box = ($deals->total_cash_in ?? 0) - ($deals->total_cash_out ?? 0);
//        $bank_cash_box = ($deals->total_bank_in ?? 0) - ($deals->total_bank_out ?? 0);
//        $total_amount = $cash_box + $bank_cash_box;
//
//        return response()->json([
//            'cashBox' => $cash_box,
//            'bankCashBox' => $bank_cash_box,
//            'totalAmount' => $total_amount,
//        ]);
//    }

//    public function getCashBox(int $pawnshop_id)
//    {
//        $date = Carbon::now()->format('Y-m-d');
//
//        $accountIds = DB::table('chart_of_accounts')
//            ->where('code', 'like', '10210%')
//            ->pluck('id');
//
//        $debitBank = DB::table('transactions')
//            ->whereIn('debit_account_id', $accountIds)
////            ->where('date', '<=', $date)
//            ->sum('amount_amd');
//
//        $creditBank = DB::table('transactions')
//            ->whereIn('credit_account_id', $accountIds)
////            ->where('date', '<=', $date)
//            ->sum('amount_amd');
//
//        $cash_box = ($deals->total_cash_in ?? 0) - ($deals->total_cash_out ?? 0);
//        $bank_cash_box = ($debitBank ?? 0) - ($creditBank ?? 0);
//        $total_amount = $cash_box + $bank_cash_box;
//
//        return response()->json([
//            'cashBox' => $cash_box,
//            'bankCashBox' => $bank_cash_box,
//            'totalAmount' => $total_amount,
//        ]);
//    }
    public function getCashBox(int $pawnshop_id)
    {
        $cashAccountIds = ChartOfAccount::where('code', 'like', '10000%')->pluck('id');

        $bankAccountIds = ChartOfAccount::where('code', 'like', '10210%')->pluck('id');

//            ChartOfAccount::where(function ($q) {
//            $q->where('code', 'like', '10210%')
//                ->orWhere('code', 'like', '1020%')
//                ->orWhere('code', 'like', '1021%');
//        })->pluck('id');

        $debitCash = Transaction::whereIn('debit_account_id', $cashAccountIds)
            ->sum('amount_amd');

        $creditCash = Transaction::whereIn('credit_account_id', $cashAccountIds)
            ->sum('amount_amd');

        $debitBank = Transaction::whereIn('debit_account_id', $bankAccountIds)
            ->sum('amount_amd');

        $creditBank = Transaction::whereIn('credit_account_id', $bankAccountIds)
            ->sum('amount_amd');

        $cash_box = $debitCash - $creditCash;
        $bank_cash_box = $debitBank - $creditBank;

        return response()->json([
            'cashBox' => $cash_box,
            'bankCashBox' => $bank_cash_box,
            'totalAmount' => $cash_box + $bank_cash_box,
        ]);
    }

    public function addCashbox(Request $request)
    {
        $amount = str_replace(',', '', $request->amount);
        $name = $request->name;
        $receiver = $request->receiver;

        $isCash = (bool) $request->cash; // True = cashbox replenishment
        $fromUnknownUser = (bool) $request->from_unknown_user;

        $bank = auth()->user()->pawnshop->bank;
        $orderId = null;
        $orderIdOut = null;

        if ($fromUnknownUser) {
            $orderId = $this->createCashboxOrder(
                $name,
                $amount,
                'in',
                $receiver,
                'Անկանխիկ համալրում անհայտ անձից',
                false
            );

            return response()->json([
                'success' => true,
                'order_id' => $orderId,
            ]);
        }

        if ($isCash) {
            $orderId = $this->createCashboxOrder(
                $name,
                $amount,
                'in',
                $receiver,
                'Դրամարկղ համալրում',
                true
            );

            return response()->json([
                'success' => true,
                'order_id' => $orderId,
            ]);
        }

        $orderId = $this->createCashboxOrder(
            $name,
            $amount,
            'in',
            $receiver,
            'Անկանխիկ հաշվին համալրում',
            false
        );

        $orderIdOut = $this->createCashboxOrder(
            $name,
            $amount,
            'out',
            $receiver,
            'Դրամարկղից փոխանցում',
            true
        );

        return response()->json([
            'success' => true,
            'order_id' => $orderId,
            'order_id_out' => $orderIdOut,
        ]);
    }


    public function addCostNDM(Request $request)
    {
        $name = $request->name;
        $amount = $request->amount;
        $receiver = $request->receiver;
        $cash = $request->cash;
        $save = $request->save_template;
        $type = $request->type;

        $purpose = Order::NDM_PURPOSE;
        $filter_type = Order::NDM_FILTER;
        $order_id = $this->getOrder($request->cash, $type);
        $order_id = $this->createOrderAndDeal($order_id, $type === 'out' ? 'cost_out' : 'in', $name, $amount, $purpose, $receiver, $cash,$filter_type);

        return response()->json([
            'success' => 'success',
            'order_id' => $order_id
        ]);
    }

    public function makeExpense(Request $request)
    {
        $name = $request->name;
        $amount = $request->amount;
        $receiver = $request->receiver;
        $client_id = $request->client_id;
        $cash = $request->cash;
        $type = 'cost_out';
        $purpose = $request->purpose;
        $account_number = $request->account_number;
        $basis = $request->basis;

        $filter_type = Order::EXPENSE_FILTER;
        $order_id = $this->getOrder($cash, $type);
        $this->createOrderAndDeal($order_id,$type,$name,$amount,$purpose,$receiver,$cash,$filter_type,$client_id,$account_number,$basis);

        return response()->json([
            'success' => 'success',
            'order_id' => $order_id
        ]);
    }

    private function createCashboxOrder($name,$amount, $type, $receiver,$purpose,$cash)
    {
        $order_id = $this->getOrder($cash, $type);
        $order = $this->createOrder($type, $name, $amount, $order_id, $purpose, $receiver,$cash);
        $this->createDeal($amount, null, null, null, null, $type, null, null,$order->id,$cash, $receiver,$purpose);
        return $order->id;
    }

    private function createOrderAndDeal($order_id, string $type, ?string $title, $amount, $purpose, $receiver, $cash,$filter_type, $client_id = null, $account_number = null, $basis = null)
    {
        $order = $this->createOrder($type, $title, $amount, $order_id, $purpose, $receiver,$cash,$client_id,$account_number,$basis);
        $this->createDeal($amount, null, null, null, null,$type,null,null,$order->id, $cash,$receiver,$purpose,$filter_type);
        return $order->id;
    }

    private function createOrder(string $type, ?string $title, $amount, $order_id, $purpose, $receiver,$cash, $client_id = null, $account_number = null, $basis = null)
    {
        if ($cash) {
            $amount = round($amount);
        }
        return Order::create([
            'type' => $type,
            'num' => $this->nextOrderNum($type, $cash),
            'title' => $title,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $amount,
            'date' => Carbon::now()->format('Y-m-d'),
            'purpose' => $purpose,
            'receiver' => $receiver,
            'client_id' => $client_id,
            'account_number' => $account_number,
            'basis' => $basis,
            'cash' => $cash,
            'user_id' => auth()->id(),
        ]);
    }

}
