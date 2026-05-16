<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $acc10000   = ChartOfAccount::idByCode('10000');
        $acc60120   = ChartOfAccount::idByCode('60120');
        $acc16605PS = ChartOfAccount::idByCode('16605PS');
        $acc86000   = ChartOfAccount::idByCode('86000');
        $acc86001   = ChartOfAccount::idByCode('86001');

        DB::table('posting_rules')->insertOrIgnore([
            [
                'business_event_filter' => 'pay_penalty_amount_loss',
                'debit_account_id'      => $acc10000,
                'credit_account_id'     => $acc60120,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                'business_event_filter' => 'recovery_principal',
                'debit_account_id'      => $acc16605PS,
                'credit_account_id'     => $acc86000,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                'business_event_filter' => 'recovery_interest',
                'debit_account_id'      => $acc16605PS,
                'credit_account_id'     => $acc86001,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('posting_rules')->whereIn('business_event_filter', [
            'pay_penalty_amount_loss',
            'recovery_principal',
            'recovery_interest',
        ])->delete();
    }
};
