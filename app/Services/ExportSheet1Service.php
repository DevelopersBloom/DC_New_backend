<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Payment;

class ExportSheet1Service
{
    public function getContractStats($pawnshopId, $currentDate, $previousDate,$firstDayOfMonth,$firstDayOfPreviousMonth = null)
    {
        $currentContracts = Contract::where('pawnshop_id', $pawnshopId)
            ->whereDate('date', '<=', $currentDate)
            ->whereDate('date', '>=', $firstDayOfMonth)
            ->where(function ($query) use ($currentDate) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query1) use ($currentDate) {
                        $query1->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at', '>', $currentDate);
                    });
            });

        $previousContracts = Contract::where('pawnshop_id', $pawnshopId)
            ->whereDate('date', '<=', $previousDate)
            ->whereDate('date', '>=', $firstDayOfPreviousMonth)
            ->where(function ($query) use ($previousDate) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query1) use ($previousDate) {
                        $query1->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at', '>', $previousDate);
                    });
            });

        return [
            'current_contracts' => $currentContracts,
            'previous_contracts' => $previousContracts,
            'current_contract_count' => $currentContracts->count(),
            'previous_contract_count' => $previousContracts->count(),
            'current_worth' => $currentContracts->sum('estimated_amount'),
            'current_given' => $this->calculateGivenAmount($currentContracts, $currentDate),
            'previous_worth' => $previousContracts->sum('estimated_amount'),
            'previous_given' => $this->calculateGivenAmount($previousContracts, $previousDate),
            'contract_ids' => $currentContracts->get()->pluck('id'),
        ];
    }

    public function calculateCategoryEstimates($contracts, $categoryId)
    {
        return $contracts->where('category_id', $categoryId)->sum('estimated_amount');
    }

    public function calculateTakenCategoryEstimates($contracts, $categoryId)
    {
        return $contracts->where('status', Contract::STATUS_TAKEN)
            ->where('category_id', $categoryId)
            ->sum('estimated_amount');
    }

    public function calculateGivenAmount($contracts, $date)
    {
        $partialPayments = Payment::where('type', 'partial')
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->whereRaw("STR_TO_DATE(date, '%d.%m.%Y') <= ?", [$date])
            ->sum('amount');
        return $contracts->sum('provided_amount') - $partialPayments;
    }

    public function getCategoryBreakdown($contracts, $categories)
    {
        $breakdown = [];
        foreach ($categories as $categoryId) {
            $breakdown[$categoryId] = [
                'current_estimated' => $this->calculateCategoryEstimates($contracts['current_contracts'], $categoryId),
                'previous_estimated' => $this->calculateCategoryEstimates($contracts['previous_contracts'], $categoryId),
                'current_taken' => $this->calculateTakenCategoryEstimates($contracts['current_contracts'], $categoryId),
                'previous_taken' => $this->calculateTakenCategoryEstimates($contracts['previous_contracts'], $categoryId),
            ];
        }
        return $breakdown;
    }

    public function getInterestPayments($contractIds, $currentDate, $previousDate,$firstDayOfMonth,$firstDayOfPreviousMonth)
    {

        return [
            'interest_current_month' => Payment::where('type', 'regular')
              //  ->whereIn('contract_id', $contractIds)
                ->where('date', '<=', $currentDate)
                ->where('date', '>=', $firstDayOfMonth)
                ->sum('paid'),
            'interest_previous_month' => Payment::where('type', 'regular')
              //  ->whereIn('contract_id', $contractIds)
                ->where('date', '<=', $previousDate)
                ->where('date','>=', $firstDayOfPreviousMonth)
                ->sum('paid'),
        ];
    }

    public function getDealStats($date, $pawnshop)
    {
        $deal = Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$date])
            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")
            ->orderBy('id', 'DESC')
            ->first();

        if ($deal) {
            return [
                'cashbox_sum' => $deal->cashbox + $deal->bank_cashbox,
                'insurance' => $deal->insurance,
                'funds' => $deal->funds,
                'bank_cashbox_sum' => $deal->bank_cashbox,
            ];
        }

        return [
            'cashbox_sum' => $pawnshop->cashbox + $pawnshop->bank_cashbox,
            'insurance' => $pawnshop->insurance,
            'funds' => $pawnshop->funds,
            'bank_cashbox_sum' => $pawnshop->bank_cashbox,
        ];
    }

    public function getNDMStats($endDate,$startDate,$purpose)
    {
        return Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$endDate])
            ->whereRaw("STR_TO_DATE(date, '%Y-%m-%d') >= ?", [$startDate])
            ->where('purpose', $purpose)
            ->selectRaw("SUM(CASE WHEN type = 'in' THEN amount WHEN type = 'cost_out' THEN -amount ELSE 0 END) as total")
            ->value('total');
    }
}
