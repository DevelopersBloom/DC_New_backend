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
        Schema::table('loan_ndm', function (Blueprint $table) {
            $table->date('calc_date')->nullable()->after('contract_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('loan_ndm', function (Blueprint $table) {
            $table->dropColumn('calc_date');
        });
    }
};
