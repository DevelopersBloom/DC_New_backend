<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Order;
use App\Models\Pawnshop;
use App\Models\Payment;
use App\Traits\ContractTrait;
use App\Traits\OrderTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

class
DealController extends Controller
{
    use OrderTrait,ContractTrait;
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
            $contractData = Contract::whereDate('date', $date)
                ->selectRaw('SUM(estimated_amount) as total_estimated,
                        SUM(provided_amount) as total_provided')
                ->where('pawnshop_id',$pawnshopId)
                ->first();

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

           $cashboxData[] = [
                'date' => $date,
                'estimated_amount' => $contractData->total_estimated ?? 0,
                'provided_amount' => $contractData->total_provided ?? 0,
                'appa' => $totals->appa ?? 0,
                'ndm' => ($totals->ndmIn ?? 0) - ($totalOuts->ndmOut ?? 0),
                'cashbox' => $totals->totalCashboxIn  - $totalOuts->totalCashboxOut,
            ];
        }
        return [
            "data" => [$cashboxData]
        ];


    }

//    public function getCashBox(int $pawnshop_id)
//    {
//        $pawnshop = Pawnshop::findOrFail(auth()->user()->pawnshop_id);
//        $cash_box = $pawnshop->cashbox;
//        $bank_cash_box = $pawnshop->bank_cashbox;
//        $total_amount = $cash_box + $bank_cash_box;
//        return response()->json([
//            'cashBox' => $cash_box,
//            'bankCashBox' => $bank_cash_box,
//            'totalAmount' => $total_amount
//        ]);
//    }
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

    public function index(Request $request){
        $dealType = $request->input('type', Deal::HISTORY);
        $deals = Deal::where('pawnshop_id', auth()->user()->pawnshop_id)
            ->select('id','cashbox','bank_cashbox','amount','pawnshop_id','cash','order_id','contract_id','type','interest_amount','delay_days','created_by')
                ->with(['order:id,client_name,order,contract_id,purpose','contract:id,num,discount,penalty_amount,discount,mother'])
            ->with('createdBy:id,name,surname')
            ->when($request->dateFrom,function ($query) use ($request){
                $query->where(function ($query) use ($request) {
                    $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') >= ?", [Carbon::parse($request->dateFrom)->setTimezone('Asia/Yerevan')]);
                })->get();
            })
            ->when($request->dateTo,function ($query) use ($request){
                $query->where(function ($query) use ($request) {
                    $query->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [Carbon::parse($request->dateTo)->setTimezone('Asia/Yerevan')]);
                })->get();
            })
            ->when($dealType === Deal::IN_DEAL, function ($query) {
                $query->where('type', 'in');
            })
            ->when($dealType === Deal::OUT_DEAL, function ($query) {
                $query->where('type', 'out');
            })
            ->when($dealType === Deal::EXPENSE_DEAL, function ($query) {
                $query->where('type', 'cost_out');
            })
            ->when($request->hasAny(['name', 'surname', 'middle_name', 'passport_series', 'phone']), function ($query) use ($request) {
                $query->whereHas('contract.client', function ($query) use ($request) {
                    if ($request->filled('name')) {
                        $query->where('name', 'like', '%' . $request->name . '%');
                    }
                    if ($request->filled('surname')) {
                        $query->where('surname', 'like', '%' . $request->surname . '%');
                    }
                    if ($request->filled('middle_name')) {
                        $query->where('middle_name', 'like', '%' . $request->middle_name . '%');
                    }
                    if ($request->filled('passport_series')) {
                        $query->where('passport_series', 'like', '%' . $request->passport_series . '%');
                    }
                    if ($request->filled('phone')) {
                        $query->where('phone', 'like', '%' . $request->phone . '%');
                    }
                    if ($request->filled('status')) {
                        $query->where('status', 'like', '%' . $request->status . '%');
                    }
                    if ($request->filled('category_id')) {
                        $query->where('category_id', 'like', '%' . $request->category_id . '%');
                    }
                    if ($request->filled('estimated_amount_from')) {
                        $query->where('estimated_amount', '>=', $request->estimated_amount_from);
                    }
                    if ($request->filled('estimated_amount_to')) {
                        $query->where('estimated_amount', '<=', $request->estimated_amount_to);
                    }
                    if ($request->filled('provided_amount_from')) {
                        $query->where('provided_amount', '>=', $request->provided_amount_from);
                    }
                    if ($request->filled('provided_amount_to')) {
                        $query->where('provided_amount', '<=', $request->provided_amount_to);
                    }
                    if ($request->filled('date_from')) {
                        $query->where('created_at', '>=', Carbon::parse($request->date_from)->setTimezone('Asia/Yerevan'));
                    }
                    if ($request->filled('date_to')) {
                        $query->where('created_at', '<=', Carbon::parse($request->date_to)->setTimezone('Asia/Yerevan'));
                    }
                });
            })
            ->when($request->filled('deal_days'), function ($query) use ($request) {
                $query->where('delay_days', $request->deal_days);
            })
            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")->orderBy('id','DESC')
            ->paginate(10);

        $deals->getCollection()->transform(function ($deal) {
            $deal->total_amount = $deal->cashbox + $deal->bank_cashbox;
            return $deal;
        });

        return response()->json([
            'deals' => $deals
        ]);

    }
//    public function addCashbox(Request $request)
//    {
//        $amount = $request->amount;
//        $purpose = $request->purpose;
//        $source = $request->source;
//        $receiver = $request->receiver;
//        $save_template = $request->save_template;
//        $name = $request->name;
//        $order_id = $this->getOrder(true,'out');
//        $res = [
//            'type' => 'cost_out',
//            'title' => $name,
//            'pawnshop_id' => auth()->user()->pawnshop_id,
//            'order' => $order_id,
//            'amount' => $amount,
//            'date' => Carbon::now()->format('d.m.Y'),
//            'purpose' => $purpose,
//            'receiver' => $receiver
//        ];
//        $new_order = Order::create($res);
//        $this->createDeal($amount,null,null,'out',null,$new_order->id,true,$purpose,$receiver,$source);
//        $order_id = $this->getOrder(false,'in');
//        $res = [
//            'type' => 'cost_in',
//            'title' => $name,
//            'pawnshop_id' => auth()->user()->pawnshop_id,
//            'order' => $order_id,
//            'amount' => $amount,
//            'date' => Carbon::now()->format('d.m.Y'),
//            'purpose' => $purpose,
//            'receiver' => auth()->user()->pawnshop->bank
//        ];
//        $new_order = Order::create($res);
//        $this->createDeal($amount,null,null,'in',null,$new_order->id,false,$purpose,$receiver,$source);
//        return response()->json([
//            "success" => "success",
//        ]);
//    }
//    public function addCost(Request $request)
//    {
//        $amount = $request->amount;
//        $purpose = $request->purpose;
//        $source = $request->source;
//        $receiver = $request->receiver;
//        $cash = $request->cash;
//        $save_template = $request->save_template;
//        $name = $request->name;
//
//        $order_id = $this->getOrder($cash,'out');
//        $res = [
//            'type' => 'cost_out',
//            'title' => 'Օրդեր',
//            'pawnshop_id' => auth()->user()->pawnshop_id,
//            'order' => $order_id,
//            'amount' => $amount,
//            'date' => Carbon::now()->format('d.m.Y'),
//            'purpose' => $purpose,
//            'receiver' => $receiver
//        ];
//        $new_order = Order::create($res);
//        $this->createDeal($amount,null,null,'cost_out',null,$new_order->id,$cash,$purpose,$receiver);
//        return response()->json([
//            'success' => 'success'
//        ]);
//    }
//    public function addCostOld(Request $request){
//        $type = $request->type;
//        $source = $request->source;
//        $amount = $request->amount;
//        $purpose = null;
//        $cash = $request->cash;
//        $otherPurpose = $request->otherPurpose;
//        $receiver = $request->receiver;
//        $purposeTranslation = $request->purposeTranslation;
//        if($request -> purpose === 'other'){
//            $purpose = $otherPurpose;
//        }else{
//            $purpose = $purposeTranslation;
//        }
//        if($type === 'out'){
//            if($request->purpose === 'bank_cashbox_charging'){
//                $order_id = $this->getOrder(true,'out');
//                $res = [
//                    'type' => 'cost_out',
//                    'title' => 'Օրդեր',
//                    'pawnshop_id' => auth()->user()->pawnshop_id,
//                    'order' => $order_id,
//                    'amount' => $amount,
//                    'date' => Carbon::now()->format('d.m.Y'),
//                    'purpose' => 'Անկանխիկ հաշվի համալրում',
//                    'receiver' => $receiver
//                ];
//                $new_order = Order::create($res);
//                $this->createDeal($amount,'out',null,$new_order->id,true,'Անկանխիկ հաշվի համալրում',$receiver);
//                $order_id = $this->getOrder(false,'in');
//                $res = [
//                    'type' => 'cost_in',
//                    'title' => 'Օրդեր',
//                    'pawnshop_id' => auth()->user()->pawnshop_id,
//                    'order' => $order_id,
//                    'amount' => $amount,
//                    'date' => Carbon::now()->format('d.m.Y'),
//                    'purpose' => 'Հաշվի համալրում',
//                    'receiver' => auth()->user()->pawnshop->bank
//                ];
//                $new_order = Order::create($res);
//                $this->createDeal($amount,'in',null,$new_order->id,false,'Հաշվի համալրում',auth()->user()->pawnshop->bank);
//            }else{
//                $order_id = $this->getOrder($cash,'out');
//                $res = [
//                    'type' => 'cost_out',
//                    'title' => 'Օրդեր',
//                    'pawnshop_id' => auth()->user()->pawnshop_id,
//                    'order' => $order_id,
//                    'amount' => $amount,
//                    'date' => Carbon::now()->format('d.m.Y'),
//                    'purpose' => $purpose,
//                    'receiver' => $receiver
//                ];
//                $new_order = Order::create($res);
//                $this->createDeal($amount,'out',null,$new_order->id,$cash,$purpose,$receiver);
//            }
//        }else{
//            $order_id = $this->getOrder($cash,'in');
//            $res = [
//                'type' => 'cost_in',
//                'title' => 'Օրդեր',
//                'pawnshop_id' => auth()->user()->pawnshop_id,
//                'order' => $order_id,
//                'amount' => $amount,
//                'date' => Carbon::now()->format('d.m.Y'),
//                'purpose' => $purpose,
//                'source' => $source,
//                'receiver' => '«Դայմոնդ Կրեդիտ» ՍՊԸ'
//            ];
//            $new_order = Order::create($res);
//            $this->createDeal($amount,'in',null,$new_order->id,$cash,$purpose,null,$source);
//        }
//
//        return response()->json([
//            'success' => 'success'
//        ]);
//
//    }
    public function addCashbox(Request $request)
    {
        $cash = $request->cash; //$cash=true -> դրամարկղի համալրում։անկանխիկ հաշվի համալրում
        $name = $request->name;
        $amount = $request->amount;
        $receiver = $request->receiver;
        $save = $request->save_template;
        $purpose_out = "Դրամարկղ";
        $purpose_in = "Անկանխիկ հաշվիվ";
        if ($cash) {
            $purpose_out = "Անկանխիկ հաշվիվ";
            $purpose_in = "Դրամարկղ";
        }
        $this->createCashboxOrder($name,$amount, 'out', $receiver,$purpose_out, !$cash);
        $this->createCashboxOrder($name,$amount, 'in', auth()->user()->pawnshop->bank,$purpose_in,$cash);

        return response()->json(["success" => "success"]);
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
        $this->createOrderAndDeal($order_id, $type === 'out' ? 'cost_out' : 'in', $name, $amount, $purpose, $receiver, $cash,$filter_type);

        return response()->json(['success' => 'success']);
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

        return response()->json(['success' => 'success']);
    }

    private function createCashboxOrder($name,$amount, $type, $receiver,$purpose,$cash)
    {
        $order_id = $this->getOrder($cash, $type);
        $order = $this->createOrder($type, $name, $amount, $order_id, $purpose, $receiver,$cash);
        $this->createDeal($amount, null, null, null, null, $type, null, null,$order->id,$cash, $receiver,$purpose);
    }

    private function createOrderAndDeal($order_id, string $type, ?string $title, $amount, $purpose, $receiver, $cash,$filter_type)
    {
        $order = $this->createOrder($type, $title, $amount, $order_id, $purpose, $receiver,$cash);
        $this->createDeal($amount, null, null, null, null,$type,null,null,$order->id, $cash,$receiver,$purpose,$filter_type);
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
