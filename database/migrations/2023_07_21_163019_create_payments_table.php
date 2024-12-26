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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('PGI_ID')->nullable();
            $table->integer('amount')->nullable();
            $table->integer('paid')->nullable();
            $table->integer('mother')->default(0);
            $table->integer('days')->nullable();
            $table->boolean('last_payment')->default(false);
            $table->string('type')->default('regular');
            $table->string('name')->nullable();
            $table->boolean('cash')->default(true);
            $table->string('surname')->nullable();
            $table->string('passport')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('another_payer')->default(false);
            $table->integer('penalty')->default(0);
            $table->integer('contract_id')->nullable();
            $table->integer('pawnshop_id')->nullable();
            $table->date('date')->nullable();
            $table->string('from_date')->nullable();
            $table->enum('status',['completed','initial'])->default('initial');
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
        Schema::dropIfExists('payments');
    }
};
