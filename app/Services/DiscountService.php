<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Discount;
use App\Models\History;
use App\Models\HistoryType;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class DiscountService
{
    private PaymentService $paymentService;
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function requestDiscount(array $data)
    {
        //$status = $data['amount'] <= 5000 ? Discount::ACCEPTED : Discount::PENDING;
        $status = Discount::ACCEPTED;
        $discount =  Discount::create([
            'amount' => $data['amount'],
            'user_id' => Auth()->user()->id,
            'contract_id' => $data['contract_id'],
            'pawnshop_id' => Auth()->user()->pawnshop_id,
            'status' => $status,
        ]);
         if ($status === Discount::ACCEPTED) {
             $discountHistory = $this->applyDiscount($discount->contract, $discount->amount);
             $discount->history = $discountHistory;
             $discount->save();
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

    private function applyDiscount($contract, $discountAmount)
    {
        $penalty = $this->paymentService->countPenalty($contract->id);
        $penaltyAmount = $penalty['penalty_amount'];
        $history = [];

        if ($penaltyAmount > 0 && $discountAmount > 0) {
            $cash = true;
            $payer = auth()->user() ?? null;
            $discount = $this->paymentService->processPenalty($contract->id, $discountAmount, $penaltyAmount, $payer, $cash,null,$penalty['parent_id']);
            $discountAmount = $discount['amount'];

            $history[] = [
                'type' => 'penalty',
                'payment_id' => $discount['payment_id'],
                'amount' => $penaltyAmount,
            ];
        }

        while ($discountAmount > 0) {
            $firstUnpayedPayment = Payment::where('contract_id', $contract->id)
                ->where('status', 'initial')
                ->orderBy('date')
                ->first();

            if (!$firstUnpayedPayment) break;

            if ($firstUnpayedPayment->amount <= 0 && $firstUnpayedPayment->mother > 0) {
                $appliedAmount = min($discountAmount, $firstUnpayedPayment->mother);

                $firstUnpayedPayment->mother -= $appliedAmount;
                $firstUnpayedPayment->discount_amount = ($payment->discount_amount ?? 0) + $appliedAmount;
                $firstUnpayedPayment->save();
                $contract->increment('collected', $appliedAmount);
                $contract->decrement('left', $appliedAmount);
                $discountAmount -= $appliedAmount;

                $history[] = [
                    'type' => 'mother',
                    'contract_id' => $contract->id,
                    'payment_id' => $firstUnpayedPayment->id,
                    'amount' => $appliedAmount,
                ];
            } else {
                $appliedAmount = min($discountAmount, $firstUnpayedPayment->amount);
                // Update the payment fields safely
                $firstUnpayedPayment->paid += $appliedAmount;
                $firstUnpayedPayment->amount -= $appliedAmount;
                $firstUnpayedPayment->discount_amount = ($firstUnpayedPayment->discount_amount ?? 0) + $appliedAmount;

                $firstUnpayedPayment->save();

                $discountAmount -= $appliedAmount;

                $history[] = [
                    'type' => 'regular_payment',
                    'payment_id' => $firstUnpayedPayment->id,
                    'amount' => $appliedAmount,
                ];
            }

            if ($firstUnpayedPayment->amount <= 0 && $firstUnpayedPayment->mother <= 0) {
                $history[] = [
                    'type' => 'status',
                    'payment_id' => $firstUnpayedPayment->id,
                    'previous_status' => $firstUnpayedPayment->status,
                ];
                $firstUnpayedPayment->update(['status' => 'completed']);
            }
        }

        return $history;
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
