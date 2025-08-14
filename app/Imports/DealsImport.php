<?php

namespace App\Imports;

use App\Models\Deal;
use App\Models\Order;
use App\Models\History;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB;

class DealsImport implements ToCollection
{
    public function collection(Collection $rows): void
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
dd($row);
                // Step 1 - Create Order
                $order = Order::create([
                    'contract_id' => $row['contract_id'] ?? null,
                    'type'        => $row['order_type'] ?? null,
                    'title'       => $row['order_title'] ?? null,
                    'pawnshop_id' => $row['pawnshop_id'] ?? null,
                    'order'       => $row['order_number'] ?? null,
                    'amount'      => $row['amount'] ?? 0,
                    'rep_id'      => $row['rep_id'] ?? null,
                    'date'        => $row['order_date'] ?? now(),
                    'client_name' => $row['client_name'] ?? null,
                    'purpose'     => $row['order_purpose'] ?? null,
                    'receiver'    => $row['order_receiver'] ?? null,
                    'cashbox'     => $row['cashbox'] ?? null,
                    'num'         => $row['order_num'] ?? null,
                    'cash'        => $row['cash'] === 'Կանխիկ',
                    'filter'      => $row['order_filter'] ?? null,
                ]);

                // Step 2 - Create History
                $history = History::create([
                    'amount'         => $row['amount'] ?? 0,
                    'type_id'        => $row['history_type_id'] ?? null,
                    'user_id'        => $row['user_id'] ?? null,
                    'date'           => $row['history_date'] ?? now(),
                    'contract_id'    => $row['contract_id'] ?? null,
                    'order_id'       => $order->id,
                    'discount'       => $row['discount'] ?? null,
                    'penalty'        => $row['penalty'] ?? null,
                    'interest_amount'=> $row['interest_amount'] ?? null,
                    'delay_days'     => $row['delay_days'] ?? null,
                ]);

                // Step 3 - Create Deal
                Deal::create([
                    'type'           => $row['deal_type'] ?? null,
                    'amount'         => $row['amount'] ?? 0,
                    'penalty'        => $row['penalty'] ?? null,
                    'discount'       => $row['discount'] ?? null,
                    'interest_amount'=> $row['interest_amount'] ?? null,
                    'order_id'       => $order->id,
                    'pawnshop_id'    => $row['pawnshop_id'] ?? null,
                    'contract_id'    => $row['contract_id'] ?? null,
                    'client_id'      => $row['client_id'] ?? null,
                    'cashbox'        => $row['cashbox'] ?? null,
                    'bank_cashbox'   => $row['bank_cashbox'] ?? null,
                    'worth'          => $row['worth'] ?? null,
                    'funds'          => $row['funds'] ?? null,
                    'cash'           => $row['cash'] === 'Կանխիկ',
                    'given'          => $row['given'] ?? null,
                    'insurance'      => $row['insurance'] ?? null,
                    'date'           => $row['deal_date'] ?? now(),
                    'delay_days'     => $row['delay_days'] ?? null,
                    'purpose'        => $row['deal_purpose'] ?? null,
                    'receiver'       => $row['deal_receiver'] ?? null,
                    'created_by'     => $row['created_by'] ?? null,
                    'updated_by'     => $row['updated_by'] ?? null,
                    'filter_type'    => $row['filter_type'] ?? null,
                    'payment_id'     => $row['payment_id'] ?? null,
                    'history_id'     => $history->id,
                    'category_id'    => $row['category_id'] ?? null,
                ]);
            }
        });
    }
}
