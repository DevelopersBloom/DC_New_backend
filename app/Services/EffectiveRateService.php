<?php
namespace App\Services;

use App\Models\Contract;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class EffectiveRateService
{
//    public function calculateEffectiveRate(Contract $contract): array
//    {
//        $lumpAmount = Order::where('contract_id', $contract->id)
//            ->where('filter', Order::REFUND_LUMP_FILTER)
//            ->select('amount')
//            ->first();
//        $kaskoAmount = $contract->kasko_amount ?? 0;
//        $fees = $lumpAmount?->amount ?? $contract->provided_amount * ($contract->lump_rate / 100);
//        $principal = $contract->mother;
//
//        $netAmount = $principal - $fees;
//        $netAmountKasko = $principal - $fees - $kaskoAmount;
//
//        $cashflows = [];
//        $cashflows[] = $netAmount;
//        $cashflowsKasko[] = $netAmountKasko;
//
//        foreach ($contract->payments as $payment) {
//            $paymentKasko = $payment->kasko_amount ?? 0;
//            if ($contract->payment_type == 'classic') {
//                $amount = $payment->amount + $payment->mother;
//                $amountKasko = $amount + $paymentKasko;
//
//            } else {
//                $amount = $payment->amount;
//                $amountKasko = $payment->amount + $paymentKasko;
//            }
//            $cashflows[] = -$amount;
//            $cashflowsKasko[] = -$amountKasko;
//        }
//
//        $monthlyRate = $this->irr($cashflows);
//        $monthlyRateKasko = $this->irr($cashflowsKasko);
//
//        if ($monthlyRate === null) {
//            return ['annual' => null, 'daily' => null];
//        }
//
//        $effectiveAnnualDecimal = pow(1 + $monthlyRate, 12) - 1;
//        $effectiveDailyDecimal = pow(1 + $effectiveAnnualDecimal, 1 / 365) - 1;
//
//        $effectiveKaskoAnnualDecimal = pow(1 + $monthlyRateKasko, 12) - 1;
//        $effectiveKaskoDailyDecimal = pow(1 + $effectiveKaskoAnnualDecimal, 1 / 365) - 1;
//
//        $annualPercent = round($effectiveAnnualDecimal * 100, 10);
//        $dailyPercent = round($effectiveDailyDecimal * 100, 10);
//        $dailyKaskoPercent = round($effectiveKaskoDailyDecimal * 100,10);
//
//        return [
//            'annual' => $annualPercent,
//            'daily' => $dailyPercent,
//            'kasko_daily' => $dailyKaskoPercent,
//        ];
//    }
    public function calculateEffectiveRate(Contract $contract): array
    {
        $lumpAmount = Order::where('contract_id', $contract->id)
            ->where('filter', Order::REFUND_LUMP_FILTER)
            ->select('amount')
            ->first();

        $kaskoAmount = $contract->kasko_amount ?? 0;
        $fees = $lumpAmount?->amount ?? $contract->provided_amount * ($contract->lump_rate / 100);
        $principal = $contract->mother;

        $netAmount = $principal - $fees;
        $cashflows = [$netAmount];
        $netAmountKasko = $principal - $fees - $kaskoAmount;
        $cashflowsKasko = [$netAmountKasko];
        Log::info($principal,$fees,$kaskoAmount);
        foreach ($contract->payments as $payment) {
            if ($contract->payment_type == 'classic') {
                $amount = $payment->amount + $payment->mother;
            } else {
                $amount = $payment->amount;
            }
            $cashflows[] = -$amount;
        }

        $monthlyRate = $this->irr($cashflows);

        if ($monthlyRate === null) {
            return [
                'annual' => null,
                'daily' => null,
                'kasko_daily' => null,
                'kasko_annual' => null,
            ];
        }

        $effectiveAnnualDecimal = pow(1 + $monthlyRate, 12) - 1;
        $effectiveDailyDecimal = pow(1 + $effectiveAnnualDecimal, 1 / 365) - 1;
        Log::info("amount: {$amount}, monthlyRate:{$monthlyRate},
            effectiveAnnual: {$effectiveAnnualDecimal}, effectiveDaily:{$effectiveDailyDecimal}");

        $kaskoDaily = null;

        if (!empty($kaskoAmount)) {

            foreach ($contract->payments as $payment) {
                $paymentKasko = $payment->kasko_amount ?? 0;

                if ($contract->payment_type == 'classic') {
                    $amountKasko = $payment->amount + $payment->mother + $paymentKasko;
                } else {
                    $amountKasko = $payment->amount + $paymentKasko;
                }

                $cashflowsKasko[] = -$amountKasko;
            }

            $monthlyRateKasko = $this->irr($cashflowsKasko);

            if ($monthlyRateKasko !== null) {
                $effectiveKaskoAnnual = pow(1 + $monthlyRateKasko, 12) - 1;
                $effectiveKaskoDaily = pow(1 + $effectiveKaskoAnnual, 1 / 365) - 1;

            }
        }
        Log::info("amountKasko: {$cashflows}");

        return [
            'annual'      => round($effectiveAnnualDecimal * 100, 10),
            'daily'       => round($effectiveDailyDecimal * 100, 10),
            'kasko_annual' => round($effectiveKaskoAnnual * 100, 10),
            'kasko_daily' => round($effectiveKaskoDaily * 100, 10),
        ];
    }

    private function irr(array $cashflows, $guess = 0.1): ?float
    {
        $maxIterations = 100;
        $precision = 1e-7;

        $rate = $guess;
        for ($i = 0; $i < $maxIterations; $i++) {
            $npv = 0.0;
            $derivative = 0.0;
            foreach ($cashflows as $t => $cf) {
                if (1 + $rate == 0) {
                    return null;
                }
                $npv += $cf / pow(1 + $rate, $t);
                $derivative += -$t * $cf / pow(1 + $rate, $t + 1);
            }
            if ($derivative == 0) {
                return null;
            }

            $newRate = $rate - $npv / $derivative;
            if (!is_finite($newRate)) {
                return null;
            }
            if (abs($newRate - $rate) < $precision) {
                return $newRate;
            }
            $rate = $newRate;
        }
        return null;
    }
}
