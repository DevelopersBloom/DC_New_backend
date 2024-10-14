<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\History;
use App\Models\HistoryType;
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

    public function __construct(PaymentService $paymentService) {
        $this->paymentService = $paymentService;
    }
    public function makePayment(Request $request): JsonResponse
    {
        $contract =  Contract::findOrFail($request->contract_id);
        $amount = $request->amount;
        $penalty = $request->penalty;
        $payer = $request->payer;
        $cash = $request->cash;

        $paymentIds = $request->payments;
        $payments = Payment::whereIn('id', $paymentIds)->get();
        $paymentsSum = $this->paymentService->processPayments(
            $contract,$amount,$penalty,$payer,$cash,$payments
        );

       $this->createDeal($amount ?? $paymentsSum, 'in', $contract->id, $this->generateOrderInNew($request,$payments)->id, $cash);
       $this->updateContractStatus($contract);
       return response()->json([
           'success' => 'success',
           'message' => 'Successfully created payment!'
//          'contract' => $this->getFullContract($request->contract_id),
//          'data' => $request->all()
       ]);

    }
    private function updateContractStatus($contract) {
        $paymentsLeft = $contract->payments->where('status', 'initial');

        if ($paymentsLeft->isEmpty()) {
            $contract->status = 'completed';
            $contract->left = 0;
        }
        $contract->save();
    }

    public function makeFullPayment(Request $request): JsonResponse
    {
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

        $this->createDeal($amount, 'in', $contract->id, $newOrder->id, $cash);

        // Fetch the updated contract with full details
        $updatedContract = $this->getFullContract($request->contract_id);

        return response()->json([
            'success' => 'success',
            'contract' => $updatedContract,
        ]);
    }
}
