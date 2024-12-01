<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Traits\ContractTrait;
use App\Traits\FileTrait;
use App\Traits\HistoryTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentControllerNew extends Controller
{
    use ContractTrait, FileTrait, HistoryTrait;

    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }
    public function makePayment(Request $request): JsonResponse
    {

        $contract =  Contract::findOrFail($request->contract_id);
        $amount = $request->amount;
        $payer = $request->payer;
        $cash = $request->cash;

        $paymentIds = $request->payments;

        $payments = Payment::whereIn('id', $paymentIds)->get();
        $result = $this->paymentService->processPayments(
            $contract,$amount,$payer,$cash,$payments
        );
        $order_id = $this->generateOrderInNew($request,$payments)->id;
        $this->createHistory($request, $order_id,$result['interest_amount'],$result['delay_days'],$result['penalty'],
            $result['discount']);

        $this->createDeal($amount ?? $result['$payments_sum'],
           $result['interest_amount'],$result['delay_days'],$result['penalty'],
           $result['discount'], 'in', $contract->id,
            $order_id, $cash,'Հերթական վճարում');
       $this->updateContractStatus($contract);
       return response()->json([
           'success' => 'success',
           'message' => 'Successfully created payment!'
//          'contract' => $this->getFullContract($request->contract_id),
//          'data' => $request->all()
       ]);
    }

    private function updateContractStatus($contract)
    {
        $paymentsLeft = $contract->payments->where('status', 'initial');

        if ($paymentsLeft->isEmpty()) {
            $contract->status = 'completed';
            $contract->left = 0;
        }
        $contract->save();
    }
    public function makeFullPayment(Request $request): JsonResponse
    {
        $has_penalty_amount = $this->countPenalty($request->contract_id);
        if ($has_penalty_amount['penalty_amount'] > 0) {
            return response()->json([
                'message' => 'You have an unpaid penalty amount! ',
            ], 404);
        }
        $contract = Contract::findOrFail($request->contract_id);
        $amount = $request->amount;
        $payer = $request->payer;
        $cash = $request->cash;
        if (!$contract) {
            return response()->json([
                'success' => 'error',
                'message' => 'Contract not found',
            ], 404);
        }
        auth()->user()->pawnshop->given = auth()->user()->pawnshop->given - $contract->left;
        auth()->user()->pawnshop->save();
        $this->paymentService->processFullPayment($contract, $amount, $payer, $cash);

        // Generate history for the payment
        $type = HistoryType::where('name', 'full_payment')->first();
        $purpose = 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
        if ($request->hasPenalty) {
            $purpose .= ', տուգանք';
        }
        $newOrder = $this->generateOrder($contract, $amount, $purpose, 'in', $cash);

        History::create([
            'amount' => $amount,
            'type_id' => $type->id,
            'user_id' => auth()->user()->id,
            'order_id' => $newOrder->id,
            'contract_id' => $contract->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d'),
        ]);
        $this->createDeal($amount, null,null,null,null,'in', $contract->id, $newOrder->id, $cash,'Ամբողջական վճարում');

        // Fetch the updated contract with full details
        $updatedContract = $this->getFullContract($request->contract_id);

        return response()->json([
            'success' => 'success',
            'message' => 'Full payment created successfully',
        ]);
    }

    public function payPartial(Request $request): JsonResponse
    {
        $has_penalty_amount = $this->countPenalty($request->contract_id);
        if ($has_penalty_amount['penalty_amount'] > 0) {
            return response()->json([
                'message' => 'You have an unpaid penalty amount! ',
            ], 404);
        }
        $contract_id = $request->contract_id;
        $contract = Contract::findOrFail($contract_id);
        $partialAmount = $request->amount;
        $payer = $request->payer;
        $cash = $request->cash;

        // Call the payment service to handle the partial payment
        $this->paymentService->payPartial($contract, $partialAmount, $payer, $cash);

        $history_type = HistoryType::where('name','partial_payment')->first();
        $client_name = $contract->name.' '.$contract->surname.' '.$contract->middle_name;
        $order_id = $this->getOrder($cash,'in');
        $res = [
            'contract_id' => $contract->id,
            'type' => 'in',
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $partialAmount,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('d.m.Y'),
            'client_name' => $client_name,
            'purpose' => 'Մասնակի մարում',
        ];
        $new_order = Order::create($res);
        History::create([
            'amount' => $partialAmount,
            'user_id' => auth()->user()->id,
            'type_id' => $history_type->id,
            'order_id' => $new_order->id,
            'contract_id' => $contract->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('d.m.Y'),
        ]);
        $this->createDeal($partialAmount, null,null, null,null,'in', $contract->id, $new_order->id, $cash, 'Մասնակի վճարում');

        // Update contract status and check if any payments remain
        $this->updateContractStatus($contract);

        return response()->json([
            'success' => 'success',
            'message' => 'Partial payment processed successfully!'
        ]);
    }

}
