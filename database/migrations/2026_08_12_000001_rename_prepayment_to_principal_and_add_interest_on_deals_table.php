<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE deals
            CHANGE prepayment prepayment_principal DECIMAL(20,2) NULL,
            ADD prepayment_interest DECIMAL(20,2) NULL AFTER prepayment_principal
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE deals
            DROP COLUMN prepayment_interest,
            CHANGE prepayment_principal prepayment DECIMAL(20,2) NULL
        ");
    }
};
