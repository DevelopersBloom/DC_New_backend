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
            $table->enum('payment_type', ['classic', 'amortized'])->default('classic')->after('amount');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('principal_payment', 15, 2)->default(0)->after('amount');
            $table->decimal('interest_payment', 15, 2)->default(0)->after('principal_payment');
            $table->decimal('remaining', 15, 2)->default(0)->after('interest_payment');
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
            $table->dropColumn('payment_type');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['principal_payment', 'interest_payment', 'remaining']);
        });
    }
};
