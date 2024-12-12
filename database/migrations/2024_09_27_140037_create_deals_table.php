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
            $table->integer('interest_amount')->nullable();
            $table->integer('discount')->nullable();
            $table->integer('delay_days')->nullable();
            $table->integer('order_id')->nullable();
            $table->integer('penalty')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('pawnshop_id')->nullable();
            $table->integer('cashbox')->nullable();
            $table->integer('bank_cashbox')->nullable();
            $table->integer('worth')->default(0);
            $table->integer('given')->default(0);
            $table->integer('insurance')->nullable();
            $table->integer('funds')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->string('filter_type')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('history_id')->nullable();

            $table->foreign('history_id')->references('id')->on('histories')->onDelete('cascade');
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');

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
        Schema::dropIfExists('deals');
    }
};
