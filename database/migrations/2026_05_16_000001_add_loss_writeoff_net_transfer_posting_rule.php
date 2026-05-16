<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $acc73015   = ChartOfAccount::idByCode('73015');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');

        DB::table('posting_rules')->insertOrIgnore([
            [
                'business_event_filter' => 'loss_writeoff_net_transfer',
                'debit_account_id'      => $acc73015,
                'credit_account_id'     => $acc16605PS,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('posting_rules')
            ->where('business_event_filter', 'loss_writeoff_net_transfer')
            ->delete();
    }
};
