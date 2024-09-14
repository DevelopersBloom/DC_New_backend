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
        Schema::create('penalty_percentages', function (Blueprint $table) {
            $table->id();
            $table->integer('category_id');
            $table->integer('min_amount'); // Minimum amount
            $table->integer('max_amount'); // Maximum amount
            $table->float('percentage'); // Penalty percentage
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
        Schema::dropIfExists('penalty_percentages');
    }
};
