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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->integer('contract_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->integer('pawnshop_id')->nullable();
            $table->string('order')->nullable();
            $table->integer('amount')->nullable();
            $table->string('rep_id')->nullable();
            $table->string('date')->nullable();
            $table->string('client_name')->nullable();
            $table->string('purpose')->nullable();
            $table->string('receiver')->nullable();
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
        Schema::dropIfExists('orders');
    }
};
