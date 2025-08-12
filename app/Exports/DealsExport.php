<?php

namespace App\Exports;

use App\Models\Deal;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class DealsExport implements FromCollection, WithHeadings, WithMapping
{
    protected $dealColumns;
    protected $orderColumns;
    protected $historyColumns;

    public function __construct()
    {
        // Բոլոր սյունակները DB attributes-ից
        $this->dealColumns = \Schema::getColumnListing((new Deal())->getTable());
        $this->orderColumns = \Schema::getColumnListing((new \App\Models\Order())->getTable());
        $this->historyColumns = \Schema::getColumnListing((new \App\Models\History())->getTable());
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
