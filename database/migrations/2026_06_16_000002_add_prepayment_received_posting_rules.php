<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $acc102101 = ChartOfAccount::idByCode('102101');
        $acc10000  = ChartOfAccount::idByCode('10000');
        $acc39920  = ChartOfAccount::idByCode('39920');

        DB::table('posting_rules')->insert([
            [
                'business_event_filter' => 'prepayment_received',
                'debit_account_id'      => $acc102101,
                'credit_account_id'     => $acc39920,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                'business_event_filter' => 'prepayment_received_cash',
                'debit_account_id'      => $acc10000,
                'credit_account_id'     => $acc39920,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('posting_rules')
            ->whereIn('business_event_filter', ['prepayment_received', 'prepayment_received_cash'])
            ->delete();
    }
};
