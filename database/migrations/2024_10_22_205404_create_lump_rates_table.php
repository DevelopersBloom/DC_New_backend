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
        Schema::create('lump_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('lump_rate')->nullable();
            $table->integer('min_amount')->nullable();
            $table->integer('max_amount')->nullable();
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
        Schema::dropIfExists('lump_rates');
    }
};
