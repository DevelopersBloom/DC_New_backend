<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAmountHistory;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Carbon\Carbon;
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

    public function calculatePawnshopCashbox($month,$year)
    {
        $cashboxData = [];
        $pawnshopId = auth()->user()->pawnshop_id;
        $now = Carbon::now();
        $daysInMonth = ($year == $now->year && $month == $now->month)
            ? $now->day
            : Carbon::createFromDate($year, $month, 1)->daysInMonth;

        for ($day = $daysInMonth; $day >= 1; $day--) {
            $date = Carbon::create($year, $month, $day)->format('Y-m-d');
            $data = ContractAmountHistory::selectRaw("
                SUM(CASE
                    WHEN amount_type = 'estimated_amount' AND type = 'in'  THEN amount
                    WHEN amount_type = 'estimated_amount' AND type = 'out' THEN -amount
                    ELSE 0
                END) AS estimated,

                SUM(CASE
                    WHEN amount_type = 'provided_amount' AND type = 'in' THEN amount
                    WHEN amount_type = 'provided_amount' AND type = 'out' THEN -amount
                    ELSE 0
                END) AS provided,

                SUM(CASE
                    WHEN amount_type = 'estimated_amount' AND type = 'in' AND category_id = 3 THEN amount
                    WHEN amount_type = 'estimated_amount' AND type = 'out' AND category_id = 3 THEN -amount
                    ELSE 0
                END) AS car_estimated,
                SUM(CASE
                    WHEN amount_type = 'estimated_amount' AND type = 'in' AND category_id = 2 THEN amount
                    WHEN amount_type = 'estimated_amount' AND type = 'out' AND category_id = 2 THEN -amount
                    ELSE 0
                END) AS electronics_estimated
            ")->whereDate('date', '<=', $date)->where('pawnshop_id',$pawnshopId)->first();

            $totals = Deal::whereDate('date', '<=', $date)
                ->where('type', Deal::IN_DEAL)
                ->where('pawnshop_id',$pawnshopId)
                ->selectRaw(
                    "SUM(CASE WHEN filter_type='appa' THEN amount ELSE 0 END) AS appa,
                     SUM(CASE WHEN filter_type='ndm' THEN amount ELSE 0 END) AS ndmIn,
                     SUM(amount) AS totalCashboxIn")
                ->first();

            $totalOuts = Deal::whereIn('type', [Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL])
                ->whereDate('date', '<=', $date)
                ->where('pawnshop_id',$pawnshopId)
                ->selectRaw(
                    "SUM(CASE WHEN filter_type='ndm' THEN amount ELSE 0 END) AS ndmOut,
                     SUM(amount) AS totalCashboxOut")
                ->first();

            $bankIn = Deal::whereDate('date', '<=', $date)
                ->where('type', Deal::IN_DEAL)
                ->where('pawnshop_id', $pawnshopId)
                ->where('cash', false)
                ->sum('amount');

            $bankOut = Deal::whereIn('type', [Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL])
                ->whereDate('date', '<=', $date)
                ->where('pawnshop_id', $pawnshopId)
                ->where('cash', false)
                ->sum('amount');

           $cashboxData[] = [
                'date' => $date,
                'estimated_amount' => $data->estimated ?? 0,
                'provided_amount' => $data->provided ?? 0,
                'car_estimated' => $data->car_estimated ?  (int)$data->car_estimated: 0,
                'electronics_estimated' => $data->electronics_estimated ?? 0,
//
//                'appa' => $totals->appa ?? 0,
//                'ndm' => ($totals->ndmIn ?? 0) - ($totalOuts->ndmOut ?? 0),
                'cashbox' => $totals->totalCashboxIn  - $totalOuts->totalCashboxOut,
                'bank_cashbox' => $bankIn - $bankOut
            ];
        }
        return [
            "data" => [$cashboxData]
        ];


    }

    public function getCashBox(int $pawnshop_id)
    {
        $now = Carbon::now()->format('Y-m-d');

        $deals = Deal::whereDate('date', '<=', $now)
            ->where('pawnshop_id',$pawnshop_id)
            ->whereIn('type', [Deal::IN_DEAL, Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL])
            ->selectRaw("
            SUM(CASE WHEN type = ? AND cash = true THEN amount ELSE 0 END) as total_cash_in,
            SUM(CASE WHEN type IN (?, ?, ?) AND cash = true THEN amount ELSE 0 END) as total_cash_out,
            SUM(CASE WHEN type = ? AND cash = false THEN amount ELSE 0 END) as total_bank_in,
            SUM(CASE WHEN type IN (?, ?, ?) AND cash = false THEN amount ELSE 0 END) as total_bank_out
        ", [
                Deal::IN_DEAL,
                Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL,
                Deal::IN_DEAL,
                Deal::OUT_DEAL, Deal::EXPENSE_DEAL, Deal::COST_OUT_DEAL
            ])
            ->first();

        $cash_box = ($deals->total_cash_in ?? 0) - ($deals->total_cash_out ?? 0);
        $bank_cash_box = ($deals->total_bank_in ?? 0) - ($deals->total_bank_out ?? 0);
        $total_amount = $cash_box + $bank_cash_box;

        return response()->json([
            'cashBox' => $cash_box,
            'bankCashBox' => $bank_cash_box,
            'totalAmount' => $total_amount,
        ]);
    }

//    public function index(Request $request){
//        $dealType = $request->input('type', Deal::HISTORY);
//        $deals = Deal::where('pawnshop_id', auth()->user()->pawnshop_id)
//            ->select('id',DB::raw("DATE(date) as date"),
//                'cashbox','bank_cashbox','amount','pawnshop_id','cash','order_id','contract_id','type','interest_amount','delay_days','created_by')
//                ->with(['order:id,client_name,order,contract_id,purpose','contract:id,num,discount,penalty_amount,discount,mother'])
//            ->with('createdBy:id,name,surname')
//            ->when($request->dateFrom,function ($query) use ($request){
//                $query->where(function ($query) use ($request) {
//                    $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [Carbon::parse($request->dateFrom)->setTimezone('Asia/Yerevan')]);
//                })->get();
//            })
//            ->when($request->dateTo,function ($query) use ($request){
//                $query->where(function ($query) use ($request) {
//                    $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [Carbon::parse($request->dateTo)->setTimezone('Asia/Yerevan')]);
//                })->get();
//            })
//            ->when($dealType === Deal::IN_DEAL, function ($query) {
//                $query->where('type', 'in');
//            })
//            ->when($dealType === Deal::OUT_DEAL, function ($query) {
//                $query->where('type', 'out');
//            })
//            ->when($dealType === Deal::EXPENSE_DEAL, function ($query) {
//                $query->where('type', 'cost_out');
//            })
//            ->when($request->hasAny(['name', 'surname', 'middle_name', 'passport_series', 'phone']), function ($query) use ($request) {
//                $query->whereHas('contract.client', function ($query) use ($request) {
//                    if ($request->filled('name')) {
//                        $query->where('name', 'like', '%' . $request->name . '%');
//                    }
//                    if ($request->filled('surname')) {
//                        $query->where('surname', 'like', '%' . $request->surname . '%');
//                    }
//                    if ($request->filled('middle_name')) {
//                        $query->where('middle_name', 'like', '%' . $request->middle_name . '%');
//                    }
//                    if ($request->filled('passport_series')) {
//                        $query->where('passport_series', 'like', '%' . $request->passport_series . '%');
//                    }
//                    if ($request->filled('phone')) {
//                        $query->where('phone', 'like', '%' . $request->phone . '%');
//                    }
//                    if ($request->filled('status')) {
//                        $query->where('status', 'like', '%' . $request->status . '%');
//                    }
//                    if ($request->filled('category_id')) {
//                        $query->where('category_id', 'like', '%' . $request->category_id . '%');
//                    }
//                    if ($request->filled('estimated_amount_from')) {
//                        $query->where('estimated_amount', '>=', $request->estimated_amount_from);
//                    }
//                    if ($request->filled('estimated_amount_to')) {
//                        $query->where('estimated_amount', '<=', $request->estimated_amount_to);
//                    }
//                    if ($request->filled('provided_amount_from')) {
//                        $query->where('provided_amount', '>=', $request->provided_amount_from);
//                    }
//                    if ($request->filled('provided_amount_to')) {
//                        $query->where('provided_amount', '<=', $request->provided_amount_to);
//                    }
//                    if ($request->filled('date_from')) {
//                        $query->where('created_at', '>=', Carbon::parse($request->date_from)->setTimezone('Asia/Yerevan'));
//                    }
//                    if ($request->filled('date_to')) {
//                        $query->where('created_at', '<=', Carbon::parse($request->date_to)->setTimezone('Asia/Yerevan'));
//                    }
//                });
//            })
//            ->when($request->filled('deal_days'), function ($query) use ($request) {
//                $query->where('delay_days', $request->deal_days);
//            })
//            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")->orderBy('id','DESC')
//            ->paginate(10);
//
//        $deals->getCollection()->transform(function ($deal) {
//            $deal->total_amount = $deal->cashbox + $deal->bank_cashbox;
//            return $deal;
//        });
//
//        return response()->json([
//            'deals' => $deals
//        ]);
//
//    }
    public function addCashbox(Request $request)
    {
        $amount = $request->amount;
        $name = $request->name;
        $receiver = $request->receiver;

        $isCash = (bool) $request->cash; // True = cashbox replenishment
        $fromUnknownUser = (bool) $request->from_unknown_user;

        $bank = auth()->user()->pawnshop->bank;
        $orderId = null;
        $orderIdOut = null;

        // Case 1: Bank replenishment by unknown person (non-cash IN only)
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

        // Case 2: Cash replenishment into cashbox (cash IN only)
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

        // Case 3: Transfer from cashbox to bank (cash OUT + bank IN)
        $orderId = $this->createCashboxOrder(
            $name,
            $amount,
            'in',
            $receiver,
            'Անկանխիկ հաշվիվ համալրում',
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


//    public function addCashbox1(Request $request)
//    {
//        $cash = $request->cash; //$cash=true -> դրամարկղի համալրում։անկանխիկ հաշվի համալրում
//        $name = $request->name;
//        $amount = $request->amount;
//        $receiver = $request->receiver;
//        $save = $request->save_template;
////        $purpose_out = "Դրամարկղ";
////        $purpose_in = "Անկանխիկ հաշվիվ";
////        if ($cash) {
////            $purpose_out = "Անկանխիկ հաշվիվ";
////            $purpose_in = "Դրամարկղ";
////        }
//        $purpose_out = "Անկանխիկ հաշվիվ";
//        $purpose_in = "Դրամարկղ";
//        $order_id = null;
//        $order_id_out = null;
//        if ($cash) {
//            $order_id = $this->createCashboxOrder($name,$amount, 'in', auth()->user()->pawnshop->bank,$purpose_in,$cash);
//        } else {
//            $order_id = $this->createCashboxOrder($name,$amount, 'in', auth()->user()->pawnshop->bank,$purpose_out,$cash);
//            $order_id_out = $this->createCashboxOrder($name,$amount, 'out', $receiver,$purpose_in, !$cash);
//        }
////        $this->createCashboxOrder($name,$amount, 'out', $receiver,$purpose_out, !$cash);
////        $this->createCashboxOrder($name,$amount, 'in', auth()->user()->pawnshop->bank,$purpose_in,$cash);
//
//        return response()->json([
//            "success" => "success",
//            "order_id" => $order_id,
//            'order_id_out' => $order_id_out
//        ]);
//    }
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
        $cash = $request->cash;
        $save = $request->save_template;
        $type = 'cost_out';
        $purpose = $request->purpose;

        $filter_type = Order::EXPENSE_FILTER;
        $order_id = $this->getOrder($request->cash, $type);
        $this->createOrderAndDeal($order_id,$type,$name,$amount,$purpose,$receiver,$cash,$filter_type);

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

    private function createOrderAndDeal($order_id, string $type, ?string $title, $amount, $purpose, $receiver, $cash,$filter_type)
    {
        $order = $this->createOrder($type, $title, $amount, $order_id, $purpose, $receiver,$cash);
        $this->createDeal($amount, null, null, null, null,$type,null,null,$order->id, $cash,$receiver,$purpose,$filter_type);
        return $order->id;
    }

    private function createOrder(string $type, ?string $title, $amount, $order_id, $purpose, $receiver,$cash)
    {
        return Order::create([
            'type' => $type,
            'title' => $title,
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $amount,
            'date' => Carbon::now()->format('Y-m-d'),
            'purpose' => $purpose,
            'receiver' => $receiver,
            'cash' => $cash
        ]);
    }

}
