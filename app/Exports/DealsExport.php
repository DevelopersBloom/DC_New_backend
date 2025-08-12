<?php

namespace App\Exports;

use App\Models\Deal;
use App\Models\Order;
use App\Models\History;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\Schema;

class DealsExport implements FromCollection, WithHeadings, WithMapping
{
    protected $dealColumns;
    protected $orderColumns;
    protected $historyColumns;

    public function __construct()
    {
        // ✅ միայն այս դաշտերը Deal-ից
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

        // ✅ Order և History բոլոր սյունակները
        $this->orderColumns = Schema::getColumnListing((new Order())->getTable());
        $this->historyColumns = Schema::getColumnListing((new History())->getTable());
    }

    public function collection()
    {
        return Deal::with(['order', 'history'])->get();
    }

    public function headings(): array
    {
        $dealHeaders = array_map(fn($col) => "deal_$col", $this->dealColumns);
        $orderHeaders = array_map(fn($col) => "order_$col", $this->orderColumns);
        $historyHeaders = array_map(fn($col) => "history_$col", $this->historyColumns);

        return array_merge($dealHeaders, $orderHeaders, $historyHeaders);
    }

    public function map($deal): array
    {
        $dealData = [];
        foreach ($this->dealColumns as $col) {
            $dealData[] = $deal->{$col} ?? '';
        }

        $orderData = [];
        foreach ($this->orderColumns as $col) {
            $orderData[] = $deal->order->{$col} ?? '';
        }

        $historyData = [];
        foreach ($this->historyColumns as $col) {
            $historyData[] = $deal->history->{$col} ?? '';
        }

        return array_merge($dealData, $orderData, $historyData);
    }
}
