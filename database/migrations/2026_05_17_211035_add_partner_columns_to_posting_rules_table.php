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
            $table->foreignId('debit_partner_id')->after('debit_account_id')->nullable()->constrained('clients');
            $table->foreignId('credit_partner_id')->after('credit_account_id')->nullable()->constrained('clients');
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
            $table->dropForeign(['debit_partner_id']);
            $table->dropForeign(['credit_partner_id']);
            $table->dropColumn(['debit_partner_id', 'credit_partner_id']);
        });
    }
};
