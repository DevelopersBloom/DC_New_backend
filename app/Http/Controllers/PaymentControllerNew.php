<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExecuteItemRequest;
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
use Illuminate\Support\Facades\DB;

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
        $payment_id = $result['id'];
        $order_id = $this->generateOrderInNew($request,$payments)->id;
        $history = $this->createHistory($request, $order_id,$result['interest_amount'],$result['delay_days'],$result['penalty'],
            $result['discount']);
        $this->createDeal($amount ?? $result['$payments_sum'],
           $result['interest_amount'],$result['delay_days'],$result['penalty'],
           $result['discount'], 'in', $contract->id,$contract->client->id,
            $order_id, $cash,null,Contract::REGULAR_PAYMENT,'payment',$history->id,$payment_id,null,1);
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
            $contract->closed_at = Carbon::now();
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
        $payment_id = $this->paymentService->processFullPayment($contract, $amount, $payer, $cash);

        // Generate history for the payment
        $type = HistoryType::where('name', 'full_payment')->first();
        $purpose = 'Վարկի մարում՝ տոկոսագւմար և մայր գումար';
        if ($request->hasPenalty) {
            $purpose .= ', տուգանք';
        }
        $newOrder = $this->generateOrder($contract, $amount, $purpose, 'in', $cash);

        $history = History::create([
            'amount' => $amount,
            'type_id' => $type->id,
            'user_id' => auth()->user()->id,
            'order_id' => $newOrder->id,
            'contract_id' => $contract->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y.m.d'),
        ]);

        $this->createDeal($amount, null,null,null,null,'in', $contract->id,$contract->client->id, $newOrder->id, $cash,null,Contract::FULL_PAYMENT,'full_payment',$history->id,$payment_id);

        // Check if early payment is eligible for a refund
        if (Carbon::now()->lessThan(Carbon::parse($contract->deadline))) {
            $refundAmount = $this->calculateRefundAmount($contract->provided_amount,$contract->lump_rate,$contract->deadline,$contract->deadline_days);
            if ($refundAmount > 0) {
                $refundOrder = $this->generateOrder($contract, $refundAmount,Order::REFUND_LUMP, 'out', $cash);
                $refund_type = HistoryType::where('name', 'one_time_payment_refund')->first();

                $history = History::create([
                    'amount' => $refundAmount,
                    'type_id' => $refund_type->id,
                    'user_id' => auth()->user()->id,
                    'order_id' => $refundOrder->id,
                    'contract_id' => $contract->id,
                    'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
                ]);
                $this->createDeal($refundAmount, null, null, null, null, 'out', $contract->id, $contract->client->id, $refundOrder->id, $cash, null, Order::REFUND_LUMP, Order::REFUND_LUMP_FILTER);

                return response()->json([
                    'success' => 'success',
                    'message' => 'Full payment created successfully with a lump sum refund',
                    'refund_amount' => $refundAmount
                ]);
            }
        }

        // Fetch the updated contract with full details
        $updatedContract = $this->getFullContract($request->contract_id);

        return response()->json([
            'success' => 'success',
            'message' => 'Full payment created successfully',
        ]);
    }

    /**
     * Calculate the refund amount for early full payment
     */
    private function calculateRefundAmount($providedAmont,$lumpRate,$deadline,$deadlineDays)
    {
        $lump_amount = $providedAmont * $lumpRate/100;
        $remainingDays = Carbon::parse($deadline)->diffInDays(Carbon::now());

        $refundAmount = $lump_amount/$deadlineDays*$remainingDays;

        return round($refundAmount, 2);
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
        $payment_id = $this->paymentService->payPartial($contract, $partialAmount, $payer, $cash);
        $history_type = HistoryType::where('name','partial_payment')->first();
        $client_name = $contract->client->name.' '.$contract->client->surname.' '.$contract->client->middle_name;
        $order_id = $this->getOrder($cash,'in');
        $res = [
            'contract_id' => $contract->id,
            'type' => 'in',
            'title' => 'Օրդեր',
            'pawnshop_id' => auth()->user()->pawnshop_id,
            'order' => $order_id,
            'amount' => $partialAmount,
            'rep_id' => '2211',
            'date' => Carbon::now()->format('Y-m-d'),
            'client_name' => $client_name,
            'purpose' => 'Մասնակի մարում',
            'cash' => $cash
        ];
        $new_order = Order::create($res);
        $history = History::create([
            'amount' => $partialAmount,
            'user_id' => auth()->user()->id,
            'type_id' => $history_type->id,
            'order_id' => $new_order->id,
            'contract_id' => $contract->id,
            'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
        ]);
        $this->createDeal($partialAmount, null,null, null,null,'in', $contract->id,$contract->client->id, $new_order->id, $cash,null, Contract::PARTIAL_PAYMENT,'partial_payment',$history->id,$payment_id);

        // Update contract status and check if any payments remain
        $this->updateContractStatus($contract);

        return response()->json([
            'success' => 'success',
            'message' => 'Partial payment processed successfully!'
        ]);
    }
    public function executeItem(ExecuteItemRequest $request)
    {
        DB::beginTransaction();
        try {
            $contractId = $request->contract_id;
            $executedAmount = $request->amount;
            $buyerInfo = $request->buyer_info;
            $num = $request->rep_id;
            $cash = false;


            $contract = Contract::findOrFail($contractId);
            $client_name = $contract->client->name.' '.$contract->client->surname.' '.$contract->client->middle_name;
            if ($contract->status === Contract::STATUS_EXECUTED) {
                throw new \Exception("This contract has already been executed.");
            }
            $contract->status = 'executed';
            $contract->executed = $request->amount;
            $contract->left = 0;
            $contract->closed_at = Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d');
            $contract->save();
            $order_id = $this->getOrder($cash,'in');
            $res = [
                'contract_id' => $contract->id,
                'type' => 'in',
                'title' => 'Օրդեր',
                'pawnshop_id' => auth()->user()->pawnshop_id,
                'order' => $order_id,
                'amount' => $executedAmount,
                //'rep_id' => '2211',
                'rep_id' => $num,
                'date' => Carbon::now()->format('Y-m-d'),
                'receiver' => $buyerInfo,
                'purpose' => Order::EXECUTION_PURPOSE,
                'client_name' => $client_name,
                'num' => $contract->num,
                'cash' => $cash

            ];
            $order = Order::create($res);
            $type = HistoryType::where('name', 'execution')->first();

            $history = History::create([
                'amount' => $executedAmount,
                'user_id' => auth()->user()->id,
                'type_id' => $type->id,
                'order_id' => $order->id,
                'contract_id' => $contract->id,
                'date' => Carbon::now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
            ]);
            $this->createDeal($executedAmount, null,null, null,null,'in', $contract->id,null, $order->id, $cash,$buyerInfo, Order::EXECUTION_PURPOSE,'execution',$history->id,null);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Execution processed successfully!'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Execution failed: ' . $e->getMessage()
            ], 500);
        }
    }

}
