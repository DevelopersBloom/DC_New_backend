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

class DealController extends Controller
{
    use ContractTrait,OrderTrait;
    public function getCashBox(int $pawnshop_id)
    {
        $pawnshop = Pawnshop::findOrFail($pawnshop_id);
        $cash_box = $pawnshop->cashbox;
        $bank_cash_box = $pawnshop->bank_cashbox;
        $total_amount = $cash_box + $bank_cash_box;
        return response()->json([
            'cashBox' => $cash_box,
            'bankCashBox' => $bank_cash_box,
            'totalAmount' => $total_amount
        ]);
    }

    public function index(Request $request){
        $dealType = $request->input('type', Deal::HISTORY);
        $deals = Deal::where('pawnshop_id', auth()->user()->pawnshop_id)
            ->select('id','cashbox','bank_cashbox','amount','pawnshop_id','cash','order_id','contract_id','type','interest_amount','delay_days')
                ->with(['order:id,client_name,order,contract_id,purpose','contract:id,discount,penalty_amount,discount,mother'])
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
                $query->where('type', 'in')->where('purpose', 'ծախս');
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
    public function addCost(Request $request){
        $type = $request->type;
        $source = $request->source;
        $amount = $request->amount;
        $purpose = null;
        $cash = $request->cash;
        $otherPurpose = $request->otherPurpose;
        $receiver = $request->receiver;
        $purposeTranslation = $request->purposeTranslation;
        if($request -> purpose === 'other'){
            $purpose = $otherPurpose;
        }else{
            $purpose = $purposeTranslation;
        }
        if($type === 'out'){
            if($request->purpose === 'bank_cashbox_charging'){
                $order_id = $this->getOrder(true,'out');
                $res = [
                    'type' => 'cost_out',
                    'title' => 'Օրդեր',
                    'pawnshop_id' => auth()->user()->pawnshop_id,
                    'order' => $order_id,
                    'amount' => $amount,
                    'date' => Carbon::now()->format('d.m.Y'),
                    'purpose' => 'Անկանխիկ հաշվի համալրում',
                    'receiver' => $receiver
                ];
                $new_order = Order::create($res);
                $this->createDeal($amount,'out',null,$new_order->id,true,'Անկանխիկ հաշվի համալրում',$receiver);
                $order_id = $this->getOrder(false,'in');
                $res = [
                    'type' => 'cost_in',
                    'title' => 'Օրդեր',
                    'pawnshop_id' => auth()->user()->pawnshop_id,
                    'order' => $order_id,
                    'amount' => $amount,
                    'date' => Carbon::now()->format('d.m.Y'),
                    'purpose' => 'Հաշվի համալրում',
                    'receiver' => auth()->user()->pawnshop->bank
                ];
                $new_order = Order::create($res);
                $this->createDeal($amount,'in',null,$new_order->id,false,'Հաշվի համալրում',auth()->user()->pawnshop->bank);
            }else{
                $order_id = $this->getOrder($cash,'out');
                $res = [
                    'type' => 'cost_out',
                    'title' => 'Օրդեր',
                    'pawnshop_id' => auth()->user()->pawnshop_id,
                    'order' => $order_id,
                    'amount' => $amount,
                    'date' => Carbon::now()->format('d.m.Y'),
                    'purpose' => $purpose,
                    'receiver' => $receiver
                ];
                $new_order = Order::create($res);
                $this->createDeal($amount,'out',null,$new_order->id,$cash,$purpose,$receiver);
            }
        }else{
            $order_id = $this->getOrder($cash,'in');
            $res = [
                'type' => 'cost_in',
                'title' => 'Օրդեր',
                'pawnshop_id' => auth()->user()->pawnshop_id,
                'order' => $order_id,
                'amount' => $amount,
                'date' => Carbon::now()->format('d.m.Y'),
                'purpose' => $purpose,
                'source' => $source,
                'receiver' => '«Դայմոնդ Կրեդիտ» ՍՊԸ'
            ];
            $new_order = Order::create($res);
            $this->createDeal($amount,'in',null,$new_order->id,$cash,$purpose,null,$source);
        }

        return response()->json([
            'success' => 'success'
        ]);

    }

}
