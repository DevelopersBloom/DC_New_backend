<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_journal', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->after('deal_id');
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->after('transactionable_id');
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents_journal', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropColumn('contract_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropColumn('contract_id');
        });
    }
};
