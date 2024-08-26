<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class UpdateContractStatusBasedOnPayments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Update contracts to 'completed' where conditions are met
        DB::statement('
            UPDATE contracts c
            SET c.status = "completed"
            WHERE c.status = "initial"
            AND c.close_date IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM payments p
                WHERE p.contract_id = c.id
                AND (p.status != "completed" OR p.paid IS NULL)
            )
        ');

        // Set close_date to null where conditions are not met
        DB::statement('
            UPDATE contracts c
            SET c.close_date = NULL
            WHERE c.status = "initial"
            AND c.close_date IS NOT NULL
            AND EXISTS (
                SELECT 1 FROM payments p
                WHERE p.contract_id = c.id
                AND (p.status != "completed" OR p.paid IS NULL)
            )
        ');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // This down method assumes that we want to revert the contracts
        // back to their initial status and restore the close_date if it was changed to null.
        DB::statement('
            UPDATE contracts c
            SET c.status = "initial"
            WHERE c.status = "completed"
        ');

        // Revert close_date to original values if they were changed to null
        // This part assumes you have a way to know what the original close_date was,
        // for simplicity, we'll assume we set it to current timestamp for the reversal
        DB::statement('
            UPDATE contracts c
            SET c.close_date = CURRENT_TIMESTAMP
            WHERE c.close_date IS NULL
            AND c.status = "initial"
        ');
    }
}
