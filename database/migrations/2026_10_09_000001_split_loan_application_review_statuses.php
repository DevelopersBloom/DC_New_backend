<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Splits the single "in_review" status into collateral review and loan review.
     * Legacy "approved" rows are left untouched on purpose.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE loan_applications MODIFY status ENUM('submitted','in_review','collateral_review','loan_review','approved','rejected','converted') NOT NULL DEFAULT 'submitted'");
        DB::table('loan_applications')->where('status', 'in_review')->update(['status' => 'collateral_review']);
        DB::statement("ALTER TABLE loan_applications MODIFY status ENUM('submitted','collateral_review','loan_review','approved','rejected','converted') NOT NULL DEFAULT 'submitted'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE loan_applications MODIFY status ENUM('submitted','in_review','collateral_review','loan_review','approved','rejected','converted') NOT NULL DEFAULT 'submitted'");
        DB::table('loan_applications')->whereIn('status', ['collateral_review', 'loan_review'])->update(['status' => 'in_review']);
        DB::statement("ALTER TABLE loan_applications MODIFY status ENUM('submitted','in_review','approved','rejected','converted') NOT NULL DEFAULT 'submitted'");
    }
};
