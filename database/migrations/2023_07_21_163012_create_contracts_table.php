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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->integer('ADB_ID')->nullable();
            $table->boolean('cash')->default(true);
            $table->string('name')->nullable();
            $table->string('surname')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('passport')->nullable();
            $table->string('dob')->nullable();
            $table->string('passport_given')->nullable();
            $table->string('info')->nullable();
            $table->boolean('extended')->default(false);
            $table->string('address')->nullable();
            $table->string('phone1')->nullable();
            $table->string('phone2')->nullable();
            $table->string('email')->nullable();
            $table->string('bank')->nullable();
            $table->string('card')->nullable();
            $table->text('comment')->nullable();
            $table->integer('worth')->nullable();
            $table->integer('given')->nullable();
            $table->integer('left')->nullable();
            $table->integer('collected')->default(0);
            $table->float('rate')->nullable();
            $table->float('penalty')->nullable();
            $table->integer('one_time_payment')->nullable();
            $table->integer('penalty_amount')->nullable();
            $table->integer('executed')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('deadline')->nullable();
            $table->string('date')->nullable();
            $table->string('close_date')->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('evaluator_id')->nullable();
            $table->integer('pawnshop_id')->nullable();
            $table->enum('status',['initial','completed','executed'])->default('initial');
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
        Schema::dropIfExists('contracts');
    }
};
