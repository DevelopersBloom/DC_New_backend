<?php
namespace App\Services;

use App\Models\Contract;
use App\Models\Order;

class EffectiveRateService
{
    public function calculateEffectiveRate(Contract $contract): array
    {
        $lumpAmount = Order::where('contract_id', $contract->id)
            ->where('filter', Order::REFUND_LUMP_FILTER)
            ->select('amount')
            ->first();
        $fees = $lumpAmount?->amount ?? $contract->provided_amount * ($contract->lump_rate / 100);
        $principal = $contract->mother;

        $netAmount = $principal - $fees;

        $cashflows = [];
        $cashflows[] = $netAmount;

        foreach ($contract->payments as $payment) {
            if ($contract->payment_type = 'classic') {
                $amount = $payment->amount + $payment->mother;
            } else {
                $amount = $payment->amount;
            }
            $cashflows[] = -$amount;
        }

        $monthlyRate = $this->irr($cashflows);

        if ($monthlyRate === null) {
            return ['annual' => null, 'daily' => null];
        }

        $effectiveAnnualDecimal = pow(1 + $monthlyRate, 12) - 1;
        $effectiveDailyDecimal = pow(1 + $effectiveAnnualDecimal, 1 / 365) - 1;

        $annualPercent = round($effectiveAnnualDecimal * 100, 10);
        $dailyPercent = round($effectiveDailyDecimal * 100, 10);

        return [
            'annual' => $annualPercent,
            'daily' => $dailyPercent,
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
