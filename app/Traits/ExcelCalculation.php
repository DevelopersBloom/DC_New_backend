<?php

namespace App\Traits;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Payment;
use Carbon\Carbon;

class ExcelCalculation
{
    public function calculateData($year, $month, $pawnshop_id, $type = 'monthly')
    {
        $isQuarterly = $type === 'quarterly';
        $periods = $this->getCalculationPeriods($year, $month, $isQuarterly);

        $current_date = $periods['current_date'];
        $previous_date = $periods['previous_date'];

        $contracts = $this->getContractsData($pawnshop_id, $current_date, $previous_date);
        $deals = $this->getDealData($pawnshop_id, $current_date, $previous_date);
        $payments = $this->getPaymentsData($contracts['contract_ids'], $current_date, $previous_date);

        return [
            'contracts' => $contracts,
            'deals' => $deals,
            'payments' => $payments,
        ];
    }

    private function getCalculationPeriods($year, $month, $isQuarterly)
    {
        if ($isQuarterly) {
            $current_date = Carbon::createFromDate($year, $month)->endOfQuarter()->toDateString();
            $previous_date = Carbon::createFromDate($year, $month)->subMonths(3)->endOfQuarter()->toDateString();
        } else {
            $current_date = Carbon::createFromDate($year, $month)->endOfMonth()->toDateString();
            $previous_date = Carbon::createFromDate($year, $month)->subMonthNoOverflow()->endOfMonth()->toDateString();
        }

        return compact('current_date', 'previous_date');
    }

    private function getContractsData($pawnshop_id, $current_date, $previous_date)
    {
        $current_contracts = Contract::where('pawnshop_id', $pawnshop_id)
            ->whereDate('date', '<=', $current_date)
            ->where(function ($query) use ($current_date) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query) use ($current_date) {
                        $query->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at', '>', $current_date);
                    });
            });

        $previous_contracts = Contract::where('pawnshop_id', $pawnshop_id)
            ->whereDate('date', '<=', $previous_date)
            ->where(function ($query) use ($previous_date) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query) use ($previous_date) {
                        $query->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at', '>', $previous_date);
                    });
            });

        return [
            'current_count' => $current_contracts->count(),
            'previous_count' => $previous_contracts->count(),
            'current_worth' => $current_contracts->sum('estimated_amount'),
            'previous_worth' => $previous_contracts->sum('estimated_amount'),
            'contract_ids' => $current_contracts->get()->pluck('id'),
        ];
    }

    private function getDealData($pawnshop_id, $current_date, $previous_date)
    {
        $current_deal = Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$current_date])
            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")
            ->orderBy('id', 'DESC')
            ->first();

        $previous_deal = Deal::whereRaw("STR_TO_DATE(date, '%Y-%m-%d') <= ?", [$previous_date])
            ->orderByRaw("STR_TO_DATE(date, '%d.%m.%Y') DESC")
            ->orderBy('id', 'DESC')
            ->first();

        return compact('current_deal', 'previous_deal');
    }

    private function getPaymentsData($contract_ids, $current_date, $previous_date)
    {
        $interest_amount_current = Payment::where('type', 'regular')
            ->whereIn('contract_id', $contract_ids)
            ->where('date', '<=', $current_date)
            ->sum('paid');

        $interest_amount_previous = Payment::where('type', 'regular')
            ->whereIn('contract_id', $contract_ids)
            ->where('date', '<=', $previous_date)
            ->sum('paid');

        return compact('interest_amount_current', 'interest_amount_previous');
    }
}
