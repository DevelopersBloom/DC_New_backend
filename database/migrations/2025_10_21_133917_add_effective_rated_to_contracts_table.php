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
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('effective_annual_rate', 8, 2)->nullable()->after('interest_rate');
            $table->decimal('effective_daily_rate', 10, 6)->nullable()->after('effective_annual_rate');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['effective_annual_rate', 'effective_daily_rate']);
        });
    }
};
