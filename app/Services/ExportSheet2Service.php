<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Deal;
use Carbon\Carbon;

class ExportSheet2Service
{
    public function getContractData($endCurrent, $endPrevious,$startCurrent,$startPrevious, $pawnshopId)
    {
        $currentContracts = Contract::where('pawnshop_id', $pawnshopId)
            ->whereDate('date', '<=', $endCurrent)
            ->whereDate('date', '>=', $startCurrent)
            ->where(function ($query) use ($endCurrent) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query1) use ($endCurrent) {
                        $query1->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at','>',$endCurrent);
                    });
            });
        $previousContracts = Contract::where('pawnshop_id', $pawnshopId)
            ->whereDate('date', '<=', $endPrevious)
            ->whereDate('date', '>=', $startPrevious)
            ->where(function ($query) use ($endPrevious) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query1) use ($endPrevious) {
                        $query1->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at','>',$endPrevious);
                    });
            });
        $data = [
                'currentMaxEstimatedAmount' => $currentContracts->max('estimated_amount'),
                'currentMinEstimatedAmount' => $currentContracts->min('estimated_amount'),
                'currentMaxRate' => $currentContracts->max('interest_rate'),
                'currentMinRate' => $currentContracts->min('interest_rate'),
                'currentMaxDeadline' => $currentContracts->max('deadline_days'),
                'currentMinDeadline' => $currentContracts->min('deadline_days'),
                'previousMaxEstimatedAmount' => $previousContracts->max('estimated_amount'),
                'previousMinEstimatedAmount' => $previousContracts->min('estimated_amount'),
                'previousMaxRate' => $previousContracts->max('interest_rate'),
                'previousMinRate' => $previousContracts->min('interest_rate'),
                'previousMaxDeadline' => $previousContracts->max('deadline_days'),
                'previousMinDeadline' => $previousContracts->min('deadline_days'),
        ];

        return $data;
    }
    public function getMaxAmountsByCategory($endDate,$startDate)
    {
        $deals = Deal::where('purpose', Deal::TAKEN_PURPOSE)
            ->where('date', '<=', $endDate)
            ->where('date','>=',$startDate);

        return [
            'gold' => $deals->where('category_id', 1)->max('amount'),
            'electronics' => $deals->where('category_id', 2)->max('amount'),
            'car' => $deals->where('category_id', 3)->max('amount'),
        ];
    }

}
