<?php

namespace App\Imports;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\HistoryType;
use App\Models\Order;
use App\Models\History;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Facades\DB;

class DealsImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $index => $row) {

                // Skip header row
                if ($index === 0) {
                    continue;
                }
                $contractId = ($row[6]) ? $this->getContractIdByNumber($row[6]) : null;

                $order = Order::create([
                    'num'         => $row[6]  ?? null,
                    'contract_id' => $contractId,
                    'type'        => $row[16] ?? null,
                    'title'       => $row[17] ?? null,
                    'pawnshop_id' => $row[5] ?? 1,
                    'order'       => $row[18] ?? null,
                    'rep_id'      => 2211,
                    'amount'      => $row[19] ?? 0,
                    'date'        => $row[20] ?? now(),
                    'client_name' => $row[21] ?? null,
                    'purpose'     => $row[22] ?? null,
                    'receiver'    => $row[23] ?? null,
                    'cash'        => ($row[7] ?? '') === 'Կանխիկ',
                    'filter'      => $row[24] ?? null,
                ]);

                // 2) Create History
                $history = History::create([
                    'contract_id'    => $contractId,
                    'amount'         => $row[1] ?? 0,
                    'type_id'        => $this->getHistoryTypeIdByName($row[24] ?? 8),
                    'date'           => $row[25] ?? now(),
                    'discount'       => $row[3] ?? null,
                    'penalty'        => $row[2] ?? null,
                    'interest_amount'=> $row[4] ?? null,
                    'delay_days'     => $row[9] ?? null,
                    'order_id'       => $order->id,
                    'user_id'        => $row[12] ?? 1
                ]);

                Deal::create([
                    'type'           => $row[0] ?? null,
                    'amount'         => $row[1] ?? 0,
                    'penalty'        => $row[2] ?? null,
                    'discount'       => $row[3] ?? null,
                    'interest_amount'=> $row[4] ?? null,
                    'pawnshop_id'    => $row[5] ?? null,
                    'contract_id'    => $contractId,
                    'cash'           => ($row[7] ?? '') === 'Կանխիկ',
                    'date'           => $row[8] ?? now(),
                    'delay_days'     => $row[9] ?? null,
                    'purpose'        => $row[10] ?? null,
                    'receiver'       => $row[11] ?? null,
                    'created_by'     => $row[12] ?? null,
                    'updated_by'     => $row[13] ?? null,
                    'filter_type'    => $row[14] ?? null,
                    'category_id'    => $row[15] ?? null,
                    'order_id'       => $order->id,
                    'history_id'     => $history->id,
                ]);
            }
        });
    }

    private function getHistoryTypeIdByName(?string $name)
    {
        if (!$name) {
            return 8;
        }

        return HistoryType::where('title',$name)->value('id');
    }

    private function getContractIdByNumber(?string $num)
    {
        if (!$num) {
            return null;
        }

        return Contract::where('num', $num)->value('id');
    }

}
