<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientClassification;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ClientClassificationService
{
    public function maxOverdueDaysForClient(Client $client): int
    {
        $today = Carbon::now('Asia/Yerevan')->startOfDay();
        $maxOverdue = 0;

        foreach ($client->contracts()
                     ->where('status', 'initial')
            ->with('payments')
                     ->cursor() as $contract) {

            foreach ($contract->payments as $p) {
                $amount     = (float)($p->amount ?? 0);
                $paidAmount = (float)($p->paid ?? 0);
                $isPaid     = $p->status === 'completed';
                $paidAt     = $isPaid ? Carbon::parse($p->date, 'Asia/Yerevan') : null;

                $due = Carbon::parse($p->to_date, 'Asia/Yerevan')->startOfDay();

                //if ($isPaid) continue;


                if (!$isPaid && $due->lt($today)) {
                    $overdueDays = $due->diffInDays($today);
                }
                elseif ($isPaid && $paidAt->gt($due)) {
                    // վճարվել է ուշ — հաշվում ենք ուշացման օրերը
                    $overdueDays = $due->diffInDays($paidAt);
                }
                else {
                    $overdueDays = 0;
                }

                if ($overdueDays > $maxOverdue) {
                    $maxOverdue = $overdueDays;
                }
            }
        }

        return $maxOverdue;
    }

    public function classificationByOverdue(int $overdueDays): ClientClassification
    {
        $d = max(0, $overdueDays);

        static $byName = null;
        if ($byName === null) {
            $byName = ClientClassification::query()
                ->whereIn('name', ['standard', 'monitored', 'substandard', 'suspicious', 'loss'])
                ->get()
                ->keyBy('name');
        }

        if ($d === 0) {
            return $byName['standard'] ?? ClientClassification::where('name', 'standard')->first();
        }
        if ($d >= 1 && $d <= 90) {
            return $byName['monitored'] ?? ClientClassification::where('name', 'monitored')->first();
        }
        if ($d >= 91 && $d <= 180) {
            return $byName['substandard'] ?? ClientClassification::where('name', 'substandard')->first();
        }
        if ($d >= 181 && $d <= 270) {
            return $byName['suspicious'] ?? ClientClassification::where('name', 'suspicious')->first();
        }
        return $byName['loss'] ?? ClientClassification::where('name', 'loss')->first();
    }
    public function getClassificationData(Contract $contract): array
    {
        $reserve = 0.0;
        $riskWeight = 0.0;

        $classificationName = $contract->client->classification->name ?? null;

        if (!$classificationName) {
            return [
                'reserve'     => 0.0,
                'risk_weight' => 0.0,
            ];
        }

        $classification = ClientClassification::where('name', $classificationName)->first();

        if ($classification) {
            $reservePercent = (float)($classification->reserve_percent ?? 0) / 100;
            $riskWeightPercent     = (float)($classification->risk_weight ?? 0) / 100;

            $baseAmount = (float)($contract->left ?? 0);

            $reserve = $baseAmount * $reservePercent;
            $riskWeight = $baseAmount * $riskWeightPercent;
        }

        return [
            'reserve' => round($reserve, 2),
            'risk_weight' => round($riskWeight, 2),
        ];
    }
}
