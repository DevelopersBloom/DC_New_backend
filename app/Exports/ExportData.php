<?php

namespace App\Exports;

use Illuminate\Support\Carbon;

class ExportData
{
    private $year;
    private $month;
    private $pawnshop_id;

    public function __construct($year, $month, $pawnshop_id)
    {
        $this->year = $year;
        $this->month = $month;
        $this->pawnshop_id = $pawnshop_id;
    }

    public function getData(): array
    {
        $month = $this->month;
        $year = $this->year;
        $days = Carbon::createFromFormat('Y', $year)->month($month)->daysInMonth;
        $lastDayOfMonth = Carbon::create($year, $month, 1)->endOfMonth()->format('d/m/Y');
        $pawnshop = Pawnshop::where('id', $this->pawnshop_id)->first();

        // Get current and previous date range
        $current_date = Carbon::createFromDate($year, $month)->endOfMonth()->toDateString();
        $previous_date = Carbon::createFromDate($year, $month)->subMonthNoOverflow()->endOfMonth()->toDateString();

        // Contracts calculations
        $current_contracts = Contract::where('pawnshop_id', $this->pawnshop_id)
            ->whereDate('date', '<=', $current_date)
            ->where(function ($query) use ($current_date) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query1) use ($current_date) {
                        $query1->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at', '>', $current_date);
                    });
            });

        $previous_contracts = Contract::where('pawnshop_id', $this->pawnshop_id)
            ->whereDate('date', '<=', $previous_date)
            ->where(function ($query) use ($previous_date) {
                $query->where('status', 'initial')
                    ->orWhere(function ($query1) use ($previous_date) {
                        $query1->whereIn('status', ['completed', 'executed'])
                            ->whereNotNull('deleted_at')
                            ->whereDate('closed_at', '>', $previous_date);
                    });
            });

        // Calculate other necessary data
        $current_given = $current_contracts->sum('provided_amount');
        $previous_given = $previous_contracts->sum('provided_amount');
        $current_taken_gold_estimated = $current_contracts->where('status', Contract::STATUS_TAKEN)->where('category_id', 1)->sum('estimated_amount');
        $previous_taken_gold_estimated = $previous_contracts->where('status', Contract::STATUS_TAKEN)->where('category_id', 1)->sum('estimated_amount');

        // Example of calculating interest for the month and previous month
        $interest_amount_current_month = Payment::where('type', 'regular')
            ->whereIn('contract_id', $current_contracts->pluck('id'))
            ->where('date', '<=', $current_date)
            ->sum('paid');

        $interest_amount_previous_month = Payment::where('type', 'regular')
            ->whereIn('contract_id', $previous_contracts->pluck('id'))
            ->where('date', '<=', $previous_date)
            ->sum('paid');

        // Return the processed data to be used in both Monthly and Quarterly exports
        return [
            'current_given' => $current_given,
            'previous_given' => $previous_given,
            'current_taken_gold_estimated' => $current_taken_gold_estimated,
            'previous_taken_gold_estimated' => $previous_taken_gold_estimated,
            'interest_amount_current_month' => $interest_amount_current_month,
            'interest_amount_previous_month' => $interest_amount_previous_month
        ];
    }
}

