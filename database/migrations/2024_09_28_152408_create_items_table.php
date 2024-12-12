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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('contract_id');
            $table->string('subcategory')->nullable();
            $table->string('model')->nullable();
            $table->float('weight')->nullable();
            $table->float('clear_weight')->nullable();
            $table->string('hallmark')->nullable();
            $table->string('car_make')->nullable();
            $table->integer('manufacture')->nullable();
            $table->string('power')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('color')->nullable();
            $table->string('registration')->nullable();
            $table->string('identification')->nullable();
            $table->string('ownership')->nullable();
            $table->string('issued_by')->nullable();
            $table->date('date_of_issuance')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories');
            $table->foreign('contract_id')->references('id')->on('contracts');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('items');
    }
};

