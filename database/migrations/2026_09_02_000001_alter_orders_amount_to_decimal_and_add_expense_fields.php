<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen orders.amount so non-cash expenses can keep their exact decimal
        // amount (deals.amount is already DECIMAL(20,2) since 2026_07_30).
        // Add optional account_number / basis fields sent from the expense form
        // for non-cash ("Անկանխիկ") expenses.
        DB::statement("ALTER TABLE orders
            MODIFY amount DECIMAL(20,2) NULL,
            ADD account_number VARCHAR(16) NULL AFTER amount,
            ADD basis TEXT NULL AFTER account_number
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders
            DROP COLUMN basis,
            DROP COLUMN account_number,
            MODIFY amount INT NULL
        ");
    }
};
