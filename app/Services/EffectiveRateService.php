<?php


namespace App\Services;

use App\Models\Contract;
use App\Models\Order;

class EffectiveRateService
{

    public function calculateEffectiveRate(Contract $contract): ?float
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
            $cashflows[] = -$payment->amount;
        }

        $monthlyRate = $this->irr($cashflows);

        if ($monthlyRate === null) {
            return null;
        }

        $effectiveAnnualRate = (pow(1 + $monthlyRate, 12) - 1) * 100;

        return round($effectiveAnnualRate, 2);
    }

    /**
     * Ներքին եկամտաբերության հաշվարկ (Newton-Raphson մեթոդ)
     */
    private function irr(array $cashflows, $guess = 0.1): ?float
    {
        $maxIterations = 100;
        $precision = 1e-7;

        $rate = $guess;
        for ($i = 0; $i < $maxIterations; $i++) {
            $npv = 0;
            $derivative = 0;
            foreach ($cashflows as $t => $cf) {
                $npv += $cf / pow(1 + $rate, $t);
                $derivative += -$t * $cf / pow(1 + $rate, $t + 1);
            }
            if ($derivative == 0) {
                return null;
            }

            $newRate = $rate - $npv / $derivative;
            if (abs($newRate - $rate) < $precision) {
                return $newRate;
            }
            $rate = $newRate;
        }
        return null;
    }
}

