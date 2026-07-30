<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE deals
            MODIFY amount DECIMAL(20,2) NULL,
            MODIFY interest_amount DECIMAL(20,2) NULL,
            MODIFY discount DECIMAL(20,2) NULL,
            MODIFY penalty DECIMAL(20,2) NULL,
            MODIFY cashbox DECIMAL(20,2) NULL,
            MODIFY bank_cashbox DECIMAL(20,2) NULL,
            MODIFY worth DECIMAL(20,2) NOT NULL DEFAULT 0,
            MODIFY given DECIMAL(20,2) NOT NULL DEFAULT 0,
            MODIFY insurance DECIMAL(20,2) NULL,
            MODIFY funds DECIMAL(20,2) NULL,
            MODIFY prepayment DECIMAL(20,2) NULL,
            MODIFY principal_amount DECIMAL(20,2) NULL
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE deals
            MODIFY amount INT NULL,
            MODIFY interest_amount INT NULL,
            MODIFY discount INT NULL,
            MODIFY penalty INT NULL,
            MODIFY cashbox INT NULL,
            MODIFY bank_cashbox INT NULL,
            MODIFY worth INT NOT NULL DEFAULT 0,
            MODIFY given INT NOT NULL DEFAULT 0,
            MODIFY insurance INT NULL,
            MODIFY funds INT NULL,
            MODIFY prepayment INT NULL,
            MODIFY principal_amount INT NULL
        ");
    }
};
