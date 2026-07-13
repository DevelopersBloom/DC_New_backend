<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $acc39920   = ChartOfAccount::idByCode('39920');
        $acc16200NV = ChartOfAccount::idByCode('16200NV');
        $acc16201NI = ChartOfAccount::idByCode('16201NI');

        DB::table('posting_rules')->insert([
            [
                // Reclassify the bucket's principal share into the real loan account
                // once the installment's due date arrives (B3).
                'business_event_filter' => 'prepayment_apply_principal',
                'debit_account_id'      => $acc39920,
                'credit_account_id'     => $acc16200NV,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
            [
                // Reclassify the bucket's interest share into interest income
                // once the installment's due date arrives (B3).
                'business_event_filter' => 'prepayment_apply_interest',
                'debit_account_id'      => $acc39920,
                'credit_account_id'     => $acc16201NI,
                'created_at'            => now(),
                'updated_at'            => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('posting_rules')
            ->whereIn('business_event_filter', ['prepayment_apply_principal', 'prepayment_apply_interest'])
            ->delete();
    }
};
