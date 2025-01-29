<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Payment;

class DiscountService
{
    private PaymentService $paymentService;
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function requestDiscount(array $data)
    {
        $status = $data['amount'] <= 5000 ? Discount::ACCEPTED : Discount::PENDING;
        $discount =  Discount::create([
            'amount' => $data['amount'],
            'user_id' => Auth()->user()->id,
            'contract_id' => $data['contract_id'],
            'pawnshop_id' => Auth()->user()->pawnshop_id,
            'status' => $status,
        ]);
         if ($status === Discount::ACCEPTED) {
             $this->applyDiscount($discount->contract, $discount->amount);
             $this->createHistory($discount);
         }
    }

    public function processDiscountResponse(Discount $discount,string $answer)
    {
        $discount->update([
            'status' =>  $answer === 'accept' ? Discount::ACCEPTED : Discount::REJECTED
        ]);
        if ($answer === 'accept') {
            $contract = $discount->contract;
            $discountAmount = $discount->amount;
            $this->applyDiscount($contract, $discountAmount);
            $this->createHistory($discount);
        }
        return $discount;
    }

    private function applyDiscount($contract,$discountAmount)
    {
        $penalty = $this->paymentService->countPenalty($contract->id);
        $penaltyAmount = $penalty['penalty_amount'];
        if ($penaltyAmount > 0 && $discountAmount > 0) {
            $cash = true;
            $payer = auth()->user() ?? null;
            $discountAmount = $this->paymentService->processPenalty($contract->id, $discountAmount, $penaltyAmount, $payer, $cash);
        }
        while ($discountAmount > 0) {
            $firstUnpayedPayment = Payment::where('contract_id', $contract->id)
                ->where('status', 'initial')
                ->orderBy('date')
                ->first();

            if (!$firstUnpayedPayment)
                break;

            if ($firstUnpayedPayment->amount <= 0 && $firstUnpayedPayment->mother > 0) {
                $appliedAmount = min($discountAmount, $firstUnpayedPayment->mother);
                $firstUnpayedPayment->decrement('mother', $appliedAmount);
                $contract->increment('collected', $appliedAmount);
                $contract->decrement('left',$appliedAmount);
                $discountAmount -= $appliedAmount;
            } else {
                $appliedAmount = min($discountAmount, $firstUnpayedPayment->amount);
                $firstUnpayedPayment->increment('paid', $appliedAmount);
                $firstUnpayedPayment->decrement('amount', $appliedAmount);
                $discountAmount -= $appliedAmount;
            }

            // Mark payment as completed if fully paid
            if ($firstUnpayedPayment->amount <= 0 && $firstUnpayedPayment->mother <= 0) {
                $firstUnpayedPayment->update(['status' => 'completed']);
            }
        }
    }
    public function createHistory($discount)
    {
        $historyType = HistoryType::where('name','discount')->firstOrFail();

        History::create([
            'amount' => $discount->amount,
            'user_id' => Auth()->user()->id,
            'type_id' => $historyType->id,
            'contract_id' => $discount->contract_id,
            'date' =>  now()->setTimezone('Asia/Yerevan')->format('Y-m-d'),
        ]);
    }
}
