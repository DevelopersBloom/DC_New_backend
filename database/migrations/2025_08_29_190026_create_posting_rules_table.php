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
        Schema::create('posting_rules', function (Blueprint $table) {
            $table->id();
            $table->string('business_event_filter')->nullable();
            $table->foreignId('debit_account_id')->nullable()->constrained('chart_of_accounts');
            $table->foreignId('credit_account_id')->nullable()->constrained('chart_of_accounts');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('posting_rules');
    }
};
