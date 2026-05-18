<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('posting_rules', function (Blueprint $table) {
            $table->string('debit_partner')->after('debit_account_id')->nullable();
            $table->string('credit_partner')->after('credit_account_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('posting_rules', function (Blueprint $table) {
            $table->dropColumn(['debit_partner', 'credit_partner']);
        });
    }
};
