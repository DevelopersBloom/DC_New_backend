<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $acc39920  = ChartOfAccount::idByCode('39920');
        $acc102101 = ChartOfAccount::idByCode('102101');
        $acc10000  = ChartOfAccount::idByCode('10000');

        DB::table('posting_rules')->insert([
            [
                // Contract closure (B4): refund any bucket amount left over after
                // offsetting the payoff — reverses prepayment_received.
                'business_event_filter' => 'prepayment_refund',
                'debit_account_id'      => $acc39920,
                'credit_account_id'     => $acc102101,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                'business_event_filter' => 'prepayment_refund_cash',
                'debit_account_id'      => $acc39920,
                'credit_account_id'     => $acc10000,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('posting_rules')
            ->whereIn('business_event_filter', ['prepayment_refund', 'prepayment_refund_cash'])
            ->delete();
    }
};
