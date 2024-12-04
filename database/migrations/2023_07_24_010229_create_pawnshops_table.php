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
        Schema::create('pawnshops', function (Blueprint $table) {
            $table->id();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('license')->nullable();
            $table->string('representative')->nullable();
            $table->string('telephone')->nullable();
            $table->string('phone1')->nullable();
            $table->string('phone2')->nullable();
            $table->string('email')->nullable();
            $table->string('bank')->nullable();
            $table->integer('cashbox')->nullable();
            $table->integer('bank_cashbox')->nullable();
            $table->integer('funds')->nullable();
            $table->integer('worth')->nullable();
            $table->integer('given')->nullable();
            $table->integer('insurance')->nullable();
            $table->integer('order_in')->nullable();
            $table->integer('order_out')->nullable();
            $table->integer('bank_order_in')->nullable();
            $table->integer('bank_order_out')->nullable();
            $table->string('card_account_number')->nullable();
            $table->decimal('assurance_money')->nullable();
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
        Schema::dropIfExists('pawnshops');
    }
};
