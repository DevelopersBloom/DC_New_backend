<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            // Deferred FK from create_loan_applications_table's final_estimate_id column,
            // now that loan_application_estimates exists.
            $table->foreign('final_estimate_id')
                ->references('id')
                ->on('loan_application_estimates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropForeign(['final_estimate_id']);
        });
    }
};
