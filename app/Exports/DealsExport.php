<?php

namespace App\Exports;

use App\Models\Deal;
use App\Models\Order;
use App\Models\History;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class DealsExport implements FromCollection, WithHeadings
{
    protected $dealColumns;
    protected $orderColumns;
    protected $historyColumns;

    public function __construct()
    {
        $this->dealColumns = [
            'type',
            'amount',
            'penalty',
            'discount',
            'interest_amount',
            'order_id',
            'pawnshop_id',
            'contract_num',
            'cash',
            'given',
            'insurance',
            'date',
            'delay_days',
            'purpose',
            'receiver',
            'source',
            'created_by',
            'updated_by',
            'filter_type',
            'payment_id',
            'history_id',
            'category_id'
        ];

        $this->orderColumns = Schema::getColumnListing((new Order())->getTable());
        $this->historyColumns = Schema::getColumnListing((new History())->getTable());
    }

    public function collection()
    {
        return Deal::with(['order', 'history'])
            ->get()
            ->map(function ($deal) {
                $row = [];
dd($deal->toArray());
                // Deal fields
                foreach ($this->dealColumns as $col) {
                    $row["deal_$col"] = $deal->{$col} ?? '';
                }

                // Order fields
                foreach ($this->orderColumns as $col) {
                    $row["order_$col"] = $deal->order->{$col} ?? '';
                }

                // History fields
                foreach ($this->historyColumns as $col) {
                    $row["history_$col"] = $deal->history->{$col} ?? '';
                }

                return collect($row);
            });
    }

    public function headings(): array
    {
        $dealHeaders = array_map(fn($col) => "deal_$col", $this->dealColumns);
        $orderHeaders = array_map(fn($col) => "order_$col", $this->orderColumns);
        $historyHeaders = array_map(fn($col) => "history_$col", $this->historyColumns);

        return array_merge($dealHeaders, $orderHeaders, $historyHeaders);
    }
}
