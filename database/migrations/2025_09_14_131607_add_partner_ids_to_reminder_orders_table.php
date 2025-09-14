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
        Schema::table('reminder_orders', function (Blueprint $table) {
            $table->foreignId('debit_partner_id')
                ->nullable()
                ->after('debit_account_id')
                ->constrained('clients')
                ->nullOnDelete();

            $table->foreignId('credit_partner_id')
                ->nullable()
                ->after('credit_account_id')
                ->constrained('clients')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('reminder_orders', function (Blueprint $table) {
            $table->dropForeign(['debit_partner_id']);
            $table->dropForeign(['credit_partner_id']);
            $table->dropColumn(['debit_partner_id', 'credit_partner_id']);
        });
    }
};
