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
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('date')->nullable();
            $table->string('type')->nullable();
            $table->boolean('cash')->default(true);
            $table->string('purpose')->nullable();
            $table->string('receiver')->nullable();
            $table->string('source')->nullable();
            $table->integer('amount')->nullable();
            $table->integer('order_id')->nullable();
            $table->integer('contract_id')->nullable();
            $table->integer('pawnshop_id')->nullable();
            $table->integer('cashbox')->nullable();
            $table->integer('bank_cashbox')->nullable();
            $table->integer('worth')->default(0);
            $table->integer('given')->default(0);
            $table->integer('insurance')->nullable();
            $table->integer('funds')->nullable();
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
        Schema::dropIfExists('deals');
    }
};
