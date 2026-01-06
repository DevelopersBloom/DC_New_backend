<?php
//namespace App\Services;
//
//use App\Models\Contract;
//use App\Models\Order;
//use Illuminate\Support\Facades\Log;
//
//class EffectiveRateService
//{
//    public function calculateEffectiveRate(Contract $contract): array
//    {
//        $lumpAmount = Order::where('contract_id', $contract->id)
//            ->where('filter', Order::REFUND_LUMP_FILTER)
//            ->select('amount')
//            ->first();
//
//        $kaskoAmount = $contract->kasko_amount ?? 0;
//        $fees = $lumpAmount?->amount ?? $contract->provided_amount * ($contract->lump_rate / 100);
//        $principal = $contract->mother;
//
//        $netAmount = $principal - $fees;
//        $cashflows = [$netAmount];
//        $netAmountKasko = $principal - $fees - $kaskoAmount;
//        $cashflowsKasko = [$netAmountKasko];
//
//        Log::info('EffectiveRate values', [
//            'principal'   => $principal,
//            'fees'        => $fees,
//            'kaskoAmount' => $kaskoAmount
//        ]);
//        foreach ($contract->payments as $payment) {
//            if ($contract->payment_type == 'classic') {
//                $amount = $payment->amount + $payment->mother;
//            } else {
//                $amount = $payment->amount;
//            }
//            $cashflows[] = -$amount;
//        }
//
//        $monthlyRate = $this->irr($cashflows);
//
//        if ($monthlyRate === null) {
//            return [
//                'annual' => null,
//                'daily' => null,
//                'kasko_daily' => null,
//                'kasko_annual' => null,
//            ];
//        }
//
//        $effectiveAnnualDecimal = pow(1 + $monthlyRate, 12) - 1;
//        $effectiveDailyDecimal = pow(1 + $effectiveAnnualDecimal, 1 / 365) - 1;
//        Log::info("amount: {$amount}, monthlyRate:{$monthlyRate},
//            effectiveAnnual: {$effectiveAnnualDecimal}, effectiveDaily:{$effectiveDailyDecimal}");
//
//        $kaskoDaily = null;
//        $effectiveKaskoAnnual = null;
//        $effectiveKaskoDaily = null;
//        if (!empty($kaskoAmount)) {
//
//            foreach ($contract->payments as $payment) {
//                $paymentKasko = $payment->kasko_amount ?? 0;
//
//                if ($contract->payment_type == 'classic') {
//                    $amountKasko = $payment->amount + $payment->mother + $paymentKasko;
//                } else {
//                    $amountKasko = $payment->amount + $paymentKasko;
//                }
//
//                $cashflowsKasko[] = -$amountKasko;
//            }
//
//            $monthlyRateKasko = $this->irr($cashflowsKasko);
//
//            if ($monthlyRateKasko !== null) {
//                $effectiveKaskoAnnual = pow(1 + $monthlyRateKasko, 12) - 1;
//                $effectiveKaskoDaily = pow(1 + $effectiveKaskoAnnual, 1 / 365) - 1;
//
//            }
//        }
//        Log::info('cashflows', [
//            'data' => $cashflows
//        ]);
//
//        Log::info('cashflowsKasko', [
//            'data' => $cashflowsKasko
//        ]);
//
//        return [
//            'annual'      => $effectiveAnnualDecimal * 100,
//            'daily'       =>$effectiveDailyDecimal * 100,
//            'kasko_annual' => $effectiveKaskoAnnual * 100,
//            'kasko_daily' => $effectiveKaskoDaily * 100,
//        ];
//    }
//
//    private function irr(array $cashflows, $guess = 0.1): ?float
//    {
//        $maxIterations = 100;
//        $precision = 1e-7;
//
//        $rate = $guess;
//        for ($i = 0; $i < $maxIterations; $i++) {
//            $npv = 0.0;
//            $derivative = 0.0;
//            foreach ($cashflows as $t => $cf) {
//                if (1 + $rate == 0) {
//                    return null;
//                }
//                $npv += $cf / pow(1 + $rate, $t);
//                $derivative += -$t * $cf / pow(1 + $rate, $t + 1);
//            }
//            if ($derivative == 0) {
//                return null;
//            }
//
//            $newRate = $rate - $npv / $derivative;
//            if (!is_finite($newRate)) {
//                return null;
//            }
//            if (abs($newRate - $rate) < $precision) {
//                return $newRate;
//            }
//            $rate = $newRate;
//        }
//        return null;
//    }
//}
//
////namespace App\Services;
////
////use App\Models\Contract;
////use App\Models\Order;
////use Illuminate\Support\Facades\Log;
////
////class EffectiveRateService
////{
////    public function calculateEffectiveRate(Contract $contract): array
////    {
////        $lumpAmount = Order::where('contract_id', $contract->id)
////            ->where('filter', Order::REFUND_LUMP_FILTER)
////            ->select('amount')
////            ->first();
////
////        $kaskoAmount = $contract->kasko_amount ?? 0;
////        $fees = $lumpAmount?->amount ?? $contract->provided_amount * ($contract->lump_rate / 100);
////        $principal = $contract->mother;
////
////        $netAmount = $principal - $fees;
////        $cashflows = [$netAmount];
////
////        $netAmountKasko = $principal - $fees - $kaskoAmount;
////        $cashflowsKasko = [$netAmountKasko];
////
////        Log::info('EffectiveRate values', [
////            'principal' => $principal,
////            'fees' => $fees,
////            'kaskoAmount' => $kaskoAmount
////        ]);
////
////        // Regular cashflows
////        foreach ($contract->payments as $payment) {
////            $amount = ($contract->payment_type == 'classic')
////                ? round($payment->amount, 10) + $payment->mother
////                : round($payment->amount, 10);
////
////            $cashflows[] = -$amount;
////        }
////
////        $monthlyRateStr = $this->irrHighPrecision($cashflows);
////
////        if ($monthlyRateStr === null) {
////            return [
////                'annual' => null,
////                'daily' => null,
////                'kasko_daily' => null,
////                'kasko_annual' => null,
////            ];
////        }
////
////        $monthlyRate = (float)$monthlyRateStr;
////
////        $effectiveAnnualDecimal = pow(1 + $monthlyRate, 12) - 1;
////        $effectiveDailyDecimal = pow(1 + $effectiveAnnualDecimal, 1 / 365) - 1;
////
////        // Kasko cashflows
////        $effectiveKaskoAnnualDecimal = null;
////        $effectiveKaskoDailyDecimal = null;
////
////        if ($kaskoAmount > 0) {
////            foreach ($contract->payments as $payment) {
////                $paymentKasko = $payment->kasko_amount ?? 0;
////                $amountKasko = ($contract->payment_type == 'classic')
////                    ? round($payment->amount, 10) + $payment->mother + $paymentKasko
////                    : round($payment->amount, 10) + $paymentKasko;
////
////                $cashflowsKasko[] = -$amountKasko;
////            }
////
////            $monthlyRateKaskoStr = $this->irrHighPrecision($cashflowsKasko);
////            if ($monthlyRateKaskoStr !== null) {
////                $monthlyRateKasko = (float)$monthlyRateKaskoStr;
////                $effectiveKaskoAnnualDecimal = pow(1 + $monthlyRateKasko, 12) - 1;
////                $effectiveKaskoDailyDecimal = pow(1 + $effectiveKaskoAnnualDecimal, 1 / 365) - 1;
////            }
////        }
////
////        return [
////            'annual' => round($effectiveAnnualDecimal * 100, 10),
////            'daily' => round($effectiveDailyDecimal * 100, 10),
////            'kasko_annual' => $effectiveKaskoAnnualDecimal !== null ? round($effectiveKaskoAnnualDecimal * 100, 10) : null,
////            'kasko_daily' => $effectiveKaskoDailyDecimal !== null ? round($effectiveKaskoDailyDecimal * 100, 10) : null,
////        ];
////    }
////
////    /**
////     * High-precision IRR using BC Math
////     */
////    private function irrHighPrecision(array $cashflows, int $scale = 12): ?string
////    {
////        $guess = "0.1";
////        $maxIterations = 50;
////
////        for ($i = 0; $i < $maxIterations; $i++) {
////            $npv = "0";
////            $derivative = "0";
////
////            foreach ($cashflows as $t => $cf) {
////                $tStr = (string)$t;
////                $pow = bcpow(bcadd("1", $guess, $scale), $tStr, $scale);
////                $npv = bcadd($npv, bcdiv((string)$cf, $pow, $scale), $scale);
////
////                $pow2 = bcpow(bcadd("1", $guess, $scale), bcadd($tStr, "1"), $scale);
////                $term = bcmul("-{$t}", bcdiv((string)$cf, $pow2, $scale), $scale);
////                $derivative = bcadd($derivative, $term, $scale);
////            }
////
////            if ($derivative == "0") return null;
////
////            $newRate = bcsub($guess, bcdiv($npv, $derivative, $scale), $scale);
////
////            $diff = bcsub($newRate, $guess, $scale);
////            if ($diff[0] === '-') $diff = substr($diff, 1);
////
////            if (bccomp($diff, "0.000000000001", $scale) <= 0) {
////                return $newRate;
////            }
////
////            $guess = $newRate;
////        }
////
////        return null;
////    }
////}

namespace App\Services;

use App\Models\Contract;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EffectiveRateService
{
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
        $netAmountKasko = $principal - $fees - $kaskoAmount;

        // Get contract date as initial disbursement date
        $initialDate = Carbon::parse($contract->date)->startOfDay();

        Log::info('EffectiveRate values', [
            'principal' => $principal,
            'fees' => $fees,
            'kaskoAmount' => $kaskoAmount,
            'initialDate' => $initialDate->format('Y-m-d')
        ]);

        // Build cashflows and dates for XIRR
        $cashflows = [$netAmount];
        $dates = [$initialDate];

        $cashflowsKasko = [$netAmountKasko];
        $datesKasko = [$initialDate];

        // Collect payments with dates and sort by date
        $paymentsWithDates = [];
        foreach ($contract->payments as $payment) {
            if (!$payment->date) {
                Log::warning('Payment missing date, skipping', ['payment_id' => $payment->id]);
                continue;
            }

            $paymentDate = Carbon::parse($payment->date)->startOfDay();

            if ($contract->payment_type == 'classic') {
                $amount = $payment->amount + $payment->mother;
            } else {
                $amount = $payment->amount;
            }

            $paymentsWithDates[] = [
                'date' => $paymentDate,
                'payment' => $payment,
                'amount' => -$amount
            ];
        }

        // Sort payments by date
        usort($paymentsWithDates, function ($a, $b) {
            return $a['date']->timestamp <=> $b['date']->timestamp;
        });

        // Add sorted payments to cashflows
        foreach ($paymentsWithDates as $paymentData) {
            $cashflows[] = $paymentData['amount'];
            $dates[] = $paymentData['date'];
        }

        // Calculate XIRR (returns annual rate)
        $effectiveAnnualDecimal = $this->xirr($cashflows, $dates);

        if ($effectiveAnnualDecimal === null) {
            return [
                'annual' => null,
                'daily' => null,
                'kasko_daily' => null,
                'kasko_annual' => null,
            ];
        }

        $effectiveDailyDecimal = pow(1 + $effectiveAnnualDecimal, 1 / 365) - 1;
        Log::info("XIRR calculation: effectiveAnnual: {$effectiveAnnualDecimal}, effectiveDaily: {$effectiveDailyDecimal}");

        $effectiveKaskoAnnual = null;
        $effectiveKaskoDaily = null;

        if (!empty($kaskoAmount)) {
            // Build kasko cashflows with dates
            foreach ($paymentsWithDates as $paymentData) {
                $payment = $paymentData['payment'];
                $paymentKasko = $payment->kasko_amount ?? 0;

                if ($contract->payment_type == 'classic') {
                    $amountKasko = $payment->amount + $payment->mother + $paymentKasko;
                } else {
                    $amountKasko = $payment->amount + $paymentKasko;
                }

                $cashflowsKasko[] = -$amountKasko;
                $datesKasko[] = $paymentData['date'];
            }

            $effectiveKaskoAnnual = $this->xirr($cashflowsKasko, $datesKasko);

            if ($effectiveKaskoAnnual !== null) {
                $effectiveKaskoDaily = pow(1 + $effectiveKaskoAnnual, 1 / 365) - 1;
            }
        }
        Log::info('cashflows with dates', [
            'cashflows' => $cashflows,
            'dates' => array_map(fn($d) => $d->format('Y-m-d'), $dates)
        ]);

        Log::info('cashflowsKasko with dates', [
            'cashflows' => $cashflowsKasko,
            'dates' => array_map(fn($d) => $d->format('Y-m-d'), $datesKasko)
        ]);

        return [
            'annual' => $effectiveAnnualDecimal * 100,
            'daily' => $effectiveDailyDecimal * 100,
            'kasko_annual' => $effectiveKaskoAnnual !== null ? $effectiveKaskoAnnual * 100 : null,
            'kasko_daily' => $effectiveKaskoDaily !== null ? $effectiveKaskoDaily * 100 : null,
        ];
    }

    /**
     * Calculate XIRR (Extended Internal Rate of Return) using actual dates
     * Returns annual rate as decimal (e.g., 0.15 for 15%)
     */
    private function xirr(array $cashflows, array $dates, float $guess = 0.1): ?float
    {
        if (count($cashflows) !== count($dates)) {
            Log::error('XIRR: Cashflows and dates arrays must have same length');
            return null;
        }

        if (count($cashflows) < 2) {
            Log::warning('XIRR: Need at least 2 cashflows');
            return null;
        }

        $maxIterations = 100;
        $precision = 1e-7;

        $rate = $guess;
        $initialDate = $dates[0];

        for ($i = 0; $i < $maxIterations; $i++) {
            $npv = 0.0;
            $derivative = 0.0;

            foreach ($cashflows as $index => $cf) {
                $date = $dates[$index];

                // Calculate years from initial date to this date
                // diffInDays with false returns signed difference (can be negative)
                $daysDiff = $initialDate->diffInDays($date, false);
                $years = $daysDiff / 365.0;

                if (1 + $rate == 0) {
                    return null;
                }

                $denominator = pow(1 + $rate, $years);
                $npv += $cf / $denominator;

                // Derivative for Newton-Raphson method
                $derivative += -$years * $cf / pow(1 + $rate, $years + 1);
            }

            if (abs($derivative) < $precision) {
                return null;
            }

            $newRate = $rate - $npv / $derivative;

            if (!is_finite($newRate) || $newRate <= -1) {
                return null;
            }

            if (abs($newRate - $rate) < $precision) {
                return $newRate;
            }

            $rate = $newRate;
        }

        Log::warning('XIRR: Max iterations reached without convergence');
        return null;
    }
}
