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
        Schema::create('histories', function (Blueprint $table) {
            $table->id();
            $table->integer('contract_id');
            $table->integer('type_id');
            $table->integer('user_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->string('date');
            $table->integer('amount')->nullable();
            $table->integer('discount')->nullable();
            $table->integer('penalty')->nullable();
            $table->string('delay_days')->nullable();
            $table->integer('interest_amount')->nullable();
            $table->softDeletes();
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
        Schema::dropIfExists('histories');
    }
};
